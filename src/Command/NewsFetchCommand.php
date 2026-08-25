<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Enum\NewsItemStatus;
use App\Enum\TosMode;
use App\Repository\NewsSourceRepository;
use App\Service\News\HostPoliteness;
use App\Service\News\RssParser;
use App\Service\News\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Шаг «парсер»: обходим активные источники, читаем RSS, дедуп по
 * (source, sha256 нормализованного guid/URL), новые — status=discovered.
 *
 * Политики (_docs/news-sources.md / -tos.md):
 *  - tos_mode=forbidden пропускается жёстко, даже если active=1;
 *  - вежливость: ≤1 запроса/сек на хост;
 *  - фид служит только детектором новинок — текст догружает process.
 */
#[AsCommand(name: 'app:news:fetch', description: 'Читает RSS активных источников, новые статьи кладёт как discovered')]
final class NewsFetchCommand extends Command
{
    private const REQUEST_TIMEOUT = 20.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly RssParser $rssParser,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly HostPoliteness $politeness,
        private readonly NewsSourceRepository $sources,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inserted = 0;
        $skippedTos = 0;

        foreach ($this->sources->findBy(['active' => true]) as $source) {
            if ($source->getTosMode() === TosMode::Forbidden) {
                // Жёсткий skip: правообладатель запретил сбор/переработку.
                ++$skippedTos;
                $output->writeln(sprintf('[skip-tos] %s (forbidden)', $source->getName()));
                continue;
            }

            try {
                $inserted += $this->fetchSource($source);
            } catch (\Throwable $e) {
                // Один упавший источник не останавливает обход остальных.
                $this->logger->warning('news:fetch source failed', [
                    'source' => $source->getName(),
                    'error' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('<comment>[fail] %s: %s</comment>', $source->getName(), $e->getMessage()));
            }
        }

        $output->writeln(sprintf('Fetched: %d new item(s), %d skipped by ToS', $inserted, $skippedTos));

        return Command::SUCCESS;
    }

    private function fetchSource(NewsSource $source): int
    {
        $host = (string) parse_url($source->getFeedUrl(), PHP_URL_HOST);
        $this->politeness->guard($host);

        $response = $this->httpClient->request('GET', $source->getFeedUrl(), ['timeout' => self::REQUEST_TIMEOUT]);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf('HTTP %d от фида', $response->getStatusCode()));
        }
        $items = $this->rssParser->parse($response->getContent());

        $inserted = 0;
        foreach ($items as $item) {
            $guidHash = $this->urlNormalizer->guidHash($item['guid'], $item['link']);
            if ($this->em->getRepository(NewsItem::class)->findBySourceAndGuidHash($source->getId(), $guidHash) !== null) {
                continue; // дедуп: уже видели этот guid/URL у источника
            }

            $news = (new NewsItem())
                ->setSource($source)
                ->setGuidHash($guidHash)
                ->setUrl($item['link'])
                ->setTitle(mb_substr($item['title'], 0, 512))
                ->setSourceName($source->getName())
                ->setSourceUrl($item['link'])
                ->setStatus(NewsItemStatus::Discovered);
            if ($item['pubDate'] !== null) {
                $news->setPublishedAt(\DateTime::createFromImmutable($item['pubDate']));
            }

            $this->em->persist($news);
            ++$inserted;
        }
        $this->em->flush();

        return $inserted;
    }
}
