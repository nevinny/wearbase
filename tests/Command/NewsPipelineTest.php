<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\NewsFetchCommand;
use App\Command\NewsProcessCommand;
use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Enum\NewsItemStatus;
use App\Enum\TosMode;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
use App\Service\News\ArticleTextExtractor;
use App\Service\News\HostPoliteness;
use App\Service\News\NewsLlmClientInterface;
use App\Service\News\NewsLlmUnavailableException;
use App\Service\News\NewsRewriter;
use App\Service\News\NewsSlugger;
use App\Service\News\RssParser;
use App\Service\News\ShingleGate;
use App\Service\News\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Тесты новостного конвейера: дедуп fetch, жёсткий skip forbidden,
 * шингл-гейт, дневной кап 8/день, устойчивость к недоступной ollama.
 * LLM и сеть — стабы (MockHttpClient / stub NewsLlmClientInterface).
 */
final class NewsPipelineTest extends WebTestCase
{
    private const RSS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>t</title>
        <item><guid>g-1</guid><link>https://www.parents.test/news/article-one</link>
            <title>Первая новость</title><pubDate>Mon, 24 Aug 2026 10:00:00 +0300</pubDate></item>
        <item><guid>g-2</guid><link>https://www.parents.test/news/article-two</link>
            <title>Вторая новость</title></item>
        </channel></rss>
        XML;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em()->createQuery('DELETE FROM App\Entity\NewsItem')->execute();
        $this->em()->createQuery('DELETE FROM App\Entity\NewsSource')->execute();
        static::ensureKernelShutdown();
    }

    public function testFetchDeduplicatesOnSecondRun(): void
    {
        $source = $this->createSource('Parents.test', TosMode::FactsOnly);
        $fetcher = $this->buildFetcher(new MockHttpClient([new MockResponse(self::RSS), new MockResponse(self::RSS)]));

        [$code, $out] = $this->runCommand($fetcher);
        self::assertSame(Command::SUCCESS, $code);
        self::assertSame(2, $this->countItems($source->getId()), $out);
        self::assertStringContainsString('Fetched: 2 new item(s)', $out);

        // Повторный запуск по тому же фиду не создаёт дублей
        [, $out2] = $this->runCommand($fetcher);
        self::assertSame(2, $this->countItems($source->getId()));
        self::assertStringContainsString('Fetched: 0 new item(s)', $out2);

        // guid из фида нормализуется в стабильный хеш
        $first = $this->itemsRepo()->findOneBy(['guidHash' => hash('sha256', 'g-1')]);
        self::assertNotNull($first);
        self::assertSame(NewsItemStatus::Discovered, $first->getStatus());
        self::assertSame('2026-08-24', $first->getPublishedAt()?->format('Y-m-d'));
    }

    public function testForbiddenSourceIsHardSkippedEvenIfActive(): void
    {
        $source = $this->createSource('Buro.test', TosMode::Forbidden);

        $requests = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('');
        });

        [$code, $out] = $this->runCommand($this->buildFetcher($client));

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('[skip-tos] Buro.test (forbidden)', $out);
        self::assertStringContainsString('Fetched: 0 new item(s), 1 skipped by ToS', $out);
        self::assertSame(0, $requests, 'forbidden-источник не должен опрашиваться вообще');
        self::assertSame(0, $this->countItems($source->getId()));
    }

    public function testShingleGateRejectsNearCopy(): void
    {
        $text = $this->longText();

        // Stub-LLM вернул почти копию исходника — гейт обязан реджектить.
        $llm = new FixedLlmStub(json_encode([
            'title' => 'Копия исходника',
            'body' => mb_substr($text, 0, 1200),
            'rubric' => 'fashion',
        ], JSON_THROW_ON_ERROR));

        [$itemId] = $this->createFetchedItem($text);
        [$code, $out] = $this->runProcessor($llm);

        self::assertSame(Command::SUCCESS, $code);
        /** @var NewsItem $fresh */
        $fresh = $this->itemsRepo()->find($itemId);
        self::assertSame(NewsItemStatus::Rejected, $fresh->getStatus(), $out);
        self::assertStringStartsWith('shingle_gate:', (string) $fresh->getRejectReason());
        self::assertGreaterThan(ShingleGate::THRESHOLD, $fresh->getShingleScore() ?? 0);
    }

    public function testDailyCapAllowsAtMostEightReady(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->createFetchedItem('Исходник номер ' . $i . ': ' . $this->longText('словарь-' . $i . '-'));
        }

        [, $out] = $this->runProcessor(new UniqueLlmStub());

        self::assertSame(8, $this->itemsRepo()->count(['status' => NewsItemStatus::Ready]), 'кап готовых к публикации ≤8/день: ' . $out);
        self::assertSame(2, $this->itemsRepo()->count(['status' => NewsItemStatus::Rewritten]), 'лишние остаются в rewritten до следующего дня');

        foreach ($this->itemsRepo()->findBy(['status' => NewsItemStatus::Ready]) as $readyItem) {
            self::assertNotNull($readyItem->getSlug(), 'слаг проставляется при ready');
        }
    }

    public function testOllamaUnavailableKeepsItemInFetchedWithoutCrash(): void
    {
        [$itemId] = $this->createFetchedItem($this->longText());

        $unavailable = new class implements NewsLlmClientInterface {
            public function chat(array $messages, ?string $format = null): string
            {
                throw new NewsLlmUnavailableException('connection refused');
            }
        };

        [$code, $out] = $this->runProcessor($unavailable);

        self::assertSame(Command::SUCCESS, $code, 'конвейер не должен падать при недоступной ollama');
        /** @var NewsItem $fresh */
        $fresh = $this->itemsRepo()->find($itemId);
        self::assertSame(NewsItemStatus::Fetched, $fresh->getStatus(), $out);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function buildFetcher(MockHttpClient $client): NewsFetchCommand
    {
        return new NewsFetchCommand(
            $this->em(),
            $client,
            new RssParser(),
            new UrlNormalizer(),
            new HostPoliteness(0.0),
            $this->em()->getRepository(NewsSource::class),
            new NullLogger(),
        );
    }

    /** @return array{int, string} */
    private function runProcessor(NewsLlmClientInterface $llm): array
    {
        return $this->runCommand(new NewsProcessCommand(
            $this->em(),
            new MockHttpClient(),
            $this->itemsRepo(),
            new NewsRewriter($llm),
            new ArticleTextExtractor(),
            new ShingleGate(),
            new NewsSlugger(),
            new HostPoliteness(0.0),
            new NullLogger(),
            autoPublish: false,
        ), ['--limit' => '50']);
    }

    /**
     * Команда собирается на EM текущего kernel и выполняется в том же boot —
     * без shutdown между сборкой и execute.
     *
     * @return array{int, string}
     */
    private function runCommand(Command $command, array $args = []): array
    {
        // Команда собрана на EM уже загруженного kernel — выполняем в том же boot,
        // иначе "kernel should only be booted once".
        if (self::$kernel === null) {
            static::bootKernel();
        }
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($app->find($command->getName()));
        $code = $tester->execute($args);
        $out = $tester->getDisplay();
        static::ensureKernelShutdown();

        return [$code, $out];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function itemsRepo(): NewsItemRepository
    {
        return $this->em()->getRepository(NewsItem::class);
    }

    private function countItems(int $sourceId): int
    {
        return $this->itemsRepo()->count(['source' => $sourceId]);
    }

    private function createSource(string $name, TosMode $tosMode, bool $active = true): NewsSource
    {

        $source = (new NewsSource())
            ->setName($name)
            ->setFeedUrl(sprintf('https://www.%s/rss.xml', mb_strtolower(str_replace('.', '', $name))))
            ->setTosMode($tosMode)
            ->setActive($active);
        $this->em()->persist($source);
        $this->em()->flush();
        static::ensureKernelShutdown();

        return $source; // detached, годится для id/name
    }

    /** @return array{int, string} id и имя источника не нужны — только id item */
    private function createFetchedItem(string $rawText): array
    {
        static $seq = 0;
        ++$seq;


        $em = $this->em();
        $source = $em->getRepository(NewsSource::class)->findOneBy([]);
        if ($source === null) {
            $source = (new NewsSource())
                ->setName('Parents.test')
                ->setFeedUrl('https://www.parentstest/rss.xml')
                ->setTosMode(TosMode::FactsOnly)
                ->setActive(true);
            $em->persist($source);
            $em->flush();
        }

        $item = (new NewsItem())
            ->setSource($source)
            ->setGuidHash('hash-' . $seq . '-' . bin2hex(random_bytes(4)))
            ->setUrl('https://parents.test/article-' . $seq)
            ->setTitle('Новость ' . $seq)
            ->setSourceName($source->getName())
            ->setSourceUrl('https://parents.test/article-' . $seq)
            ->setRawFetchedText($rawText)
            ->setStatus(NewsItemStatus::Fetched);
        $em->persist($item);
        $em->flush();
        $id = $item->getId() ?? throw new \RuntimeException('no id after flush');
        static::ensureKernelShutdown();

        return [$id];
    }

    private function longText(string $prefix = 'факт-'): string
    {
        $sentences = [];
        for ($i = 0; $i < 60; ++$i) {
            $sentences[] = sprintf('%s%s факт номер %d про моду и гардероб города.', $prefix, $i, $i);
        }

        return implode(' ', $sentences);
    }
}

/** LLM-стаб с фиксированным ответом (для шингл-гейта). */
final class FixedLlmStub implements NewsLlmClientInterface
{
    public function __construct(private readonly string $response)
    {
    }

    public function chat(array $messages, ?string $format = null): string
    {
        return $this->response;
    }
}

/** LLM-стаб, выдающий каждый раз уникальную заметку (мимо шингл-гейта). */
final class UniqueLlmStub implements NewsLlmClientInterface
{
    private int $n = 0;

    public function chat(array $messages, ?string $format = null): string
    {
        ++$this->n;
        $words = [];
        for ($i = 0; $i < 90; ++$i) {
            $words[] = sprintf('уникальный%d-%d-токен-заметки', $this->n, $i);
        }

        return json_encode([
            'title' => 'Заметка номер ' . $this->n,
            'body' => implode(' ', $words) . ' и вывод.',
            'rubric' => 'other',
        ], JSON_THROW_ON_ERROR);
    }
}
