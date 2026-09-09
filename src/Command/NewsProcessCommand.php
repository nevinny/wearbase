<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\NewsItem;
use App\Enum\NewsItemStatus;
use App\Repository\NewsItemRepository;
use App\Service\News\ArticleTextExtractor;
use App\Service\News\HostPoliteness;
use App\Service\News\NewsLlmUnavailableException;
use App\Service\News\NewsRewriter;
use App\Service\News\NewsSlugger;
use App\Service\News\ShingleGate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Шаги «рерайт → гейты → готово» для discovered/fetched:
 *  1) догрузка HTML статьи + извлечение текста (DOM-фильтр script/style/nav…);
 *  2) LLM-рерайт «заметка на основе фактов» + рубрикатор (та же LLM);
 *  3) шингл-гейт: доля 5-грамм с исходником ≤10%, иначе rejected;
 *  4) кап готовых к публикации ≤8/день (по ready_at с начала суток);
 *  5) NEWS_AUTO_PUBLISH=1 — сразу published, иначе ждёт модерации.
 *
 * LLM недоступна → item остаётся в fetched, ошибка в логе, конвейер живёт.
 */
#[AsCommand(name: 'app:news:process', description: 'Догружает тексты, делает рерайт через ollama, гонит через шингл-гейт и дневной кап')]
final class NewsProcessCommand extends Command
{
    private const READY_CAP_PER_DAY = 8;
    private const REQUEST_TIMEOUT = 20.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly NewsItemRepository $items,
        private readonly NewsRewriter $rewriter,
        private readonly ArticleTextExtractor $extractor,
        private readonly ShingleGate $shingleGate,
        private readonly NewsSlugger $slugger,
        private readonly HostPoliteness $politeness,
        private readonly LoggerInterface $logger,
        private readonly bool $autoPublish = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько item взять за запуск', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $todayStart = (new \DateTimeImmutable('today'));

        // Кап считается по уже готовым (ready/published) за сегодня.
        $remaining = self::READY_CAP_PER_DAY - $this->items->countReadySince($todayStart);
        $output->writeln(sprintf('Daily ready cap: %d used, %d remaining', self::READY_CAP_PER_DAY - $remaining, max(0, $remaining)));

        // Сначала добиваем переписанные, но не вошедшие в кап прошлых запусков.
        if ($remaining > 0) {
            $pending = $this->items->findBy(['status' => NewsItemStatus::Rewritten], ['id' => 'ASC'], $remaining);
            foreach ($pending as $item) {
                --$remaining;
                $this->promoteToReady($item);
                ++$ready;
            }
            if ($pending !== []) {
                $this->em->flush();
            }
        }

        $processed = 0;
        $rejected = 0;
        $ready = 0;
        foreach ($this->items->findProcessable($limit) as $item) {
            try {
                if ($item->getRawFetchedText() === null) {
                    if (!$this->fetchArticle($item)) {
                        ++$rejected;
                        continue; // fetch_failed
                    }
                }

                if (!$this->rewriteItem($item)) {
                    continue; // LLM недоступна или мусорный ответ — останется в fetched
                }
            } catch (\Throwable $e) {
                $this->logger->error('news:process item failed', [
                    'id' => $item->getId(),
                    'error' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('<comment>[fail] #%d: %s</comment>', $item->getId(), $e->getMessage()));
                continue;
            }

            ++$processed;

            // Готов к публикации — но только в пределах дневного капа.
            if ($remaining > 0) {
                --$remaining;
                $this->promoteToReady($item);
                ++$ready;
            }
            $this->em->flush();
        }

        if ($this->autoPublish) {
            $published = $this->publishReady();
            $output->writeln(sprintf('Auto-published: %d', $published));
        }

        $output->writeln(sprintf('Processed: %d rewritten, %d ready, %d rejected (fetch)', $processed, $ready, $rejected));

        return Command::SUCCESS;
    }

    /** Догрузка и извлечение текста. false → rejected (fetch_failed). */
    private function fetchArticle(NewsItem $item): bool
    {
        $host = (string) parse_url($item->getUrl(), PHP_URL_HOST);
        $this->politeness->guard($host);

        try {
            $response = $this->httpClient->request('GET', $item->getUrl(), ['timeout' => self::REQUEST_TIMEOUT]);
            if ($response->getStatusCode() >= 300) {
                throw new \RuntimeException(sprintf('HTTP %d', $response->getStatusCode()));
            }
            $html = $response->getContent();
        } catch (\Throwable $e) {
            $this->reject($item, 'fetch_failed: ' . mb_substr($e->getMessage(), 0, 200));
            return false;
        }

        $text = $this->extractor->extract($html);
        if ($text === null) {
            $this->reject($item, 'fetch_failed: текст не извлечён');
            return false;
        }

        $item->setRawFetchedText($text)->setStatus(NewsItemStatus::Fetched);

        return true;
    }

    /**
     * Рерайт + шингл-гейт. false = остаётся fetched (LLM недоступна / мусор)
     * либо rejected (жанр из чёрного списка, шинглы).
     */
    private function rewriteItem(NewsItem $item): bool
    {
        if ($this->rewriter->isForbiddenGenre($item->getTitle())) {
            $this->reject($item, 'forbidden_genre');
            return false;
        }

        try {
            $result = $this->rewriter->rewrite($item->getSourceName(), $item->getRawFetchedText() ?? '', $item->getSource()->getRubricHint());
        } catch (NewsLlmUnavailableException | \RuntimeException $e) {
            // Не падаем и не реджектим: transient-сбой, попробуем на следующем запуске.
            $this->logger->warning('news:process rewrite skipped', ['id' => $item->getId(), 'error' => $e->getMessage()]);
            return false;
        }

        $score = $this->shingleGate->overlap($item->getRawFetchedText() ?? '', $result['body']);
        $item->setShingleScore(round($score, 4));
        if (!$this->shingleGate->passes($score)) {
            $this->reject($item, sprintf('shingle_gate: %.1f%% > 10%%', $score * 100));
            return false;
        }

        $item->setRewrittenTitle(mb_substr($result['title'], 0, 512))
            ->setRewrittenBody($result['body'])
            ->setRubric($result['rubric'])
            ->setStatus(NewsItemStatus::Rewritten);

        return true;
    }

    /** Ready: слаг из транслита заголовка (уникальный), отметка ready_at. */
    private function promoteToReady(NewsItem $item): void
    {
        $base = $this->slugger->slugify($item->getRewrittenTitle() ?? $item->getTitle());
        $slug = $base;
        $i = 2;
        while ($this->items->findOneBy(['slug' => $slug]) !== null) {
            $slug = $base . '-' . $i++;
        }
        $item->setSlug($slug)->setStatus(NewsItemStatus::Ready);
    }

    /** NEWS_AUTO_PUBLISH=1: всё готовое уходит в published. */
    private function publishReady(): int
    {
        $readyItems = $this->items->findBy(['status' => NewsItemStatus::Ready]);
        foreach ($readyItems as $item) {
            $item->setStatus(NewsItemStatus::Published);
        }
        if ($readyItems !== []) {
            $this->em->flush();
        }

        return count($readyItems);
    }

    private function reject(NewsItem $item, string $reason): void
    {
        $item->setStatus(NewsItemStatus::Rejected)->setRejectReason($reason);
        $this->em->flush();
    }
}
