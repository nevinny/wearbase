<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Entity\SocialPostMetric;
use App\Repository\SocialPostMetricRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:social:ingest-clicks: атрибуция UTM-кликов из nginx-логов (Ф0 closed-loop,
 * docs/social_value_plan.md) + инкрементальность повторного прогона.
 */
class SocialIngestClicksCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testAttributionFiltersAndIncrementalRuns(): void
    {
        $channel = (new SocialChannel())
            ->setPlatform(SocialChannel::PLATFORM_TG)
            ->setName('Test TG')
            ->setEnabled(true);
        $this->em->persist($channel);

        // Пост A — атрибуция по utm_content=p<id>, уже есть старый снимок метрик.
        $postA = (new SocialPost())
            ->setChannel($channel)
            ->setRubric('manifesto')
            ->setStatus(SocialPost::STATUS_PUBLISHED)
            ->setPublishedAt(new \DateTime('2026-06-20 10:00:00'));
        $this->em->persist($postA);

        // Пост B — fallback-атрибуция (utm_source+utm_campaign), без снимков вообще.
        $postB = (new SocialPost())
            ->setChannel($channel)
            ->setRubric('vs_marketplace')
            ->setStatus(SocialPost::STATUS_PUBLISHED)
            ->setPublishedAt(new \DateTime('2026-07-05 08:00:00'));
        $this->em->persist($postB);
        $this->em->flush();

        $oldMetric = (new SocialPostMetric())
            ->setPost($postA)
            ->setLinkTaps(5)
            ->setMeasuredAt(new \DateTime('2026-07-10 09:00:00'));
        $this->em->persist($oldMetric);
        $this->em->flush();

        $idA = $postA->getId();

        $log = implode("\n", [
            // 1. Валидный клик по посту A (utm_content), позже старого measuredAt → должен посчитаться.
            "203.0.113.10 - - [10/Jul/2026:12:30:00 +0300] \"GET /ru/brands/test-brand?utm_source=tg&utm_medium=social&utm_campaign=manifesto&utm_content=p{$idA} HTTP/1.1\" 200 1234 \"-\" \"Mozilla/5.0 (Linux; Android 10)\"",
            // 2. Fallback-атрибуция на пост B (без utm_content) — платформа+рубрика+окно публикации.
            "203.0.113.11 - - [11/Jul/2026:10:00:00 +0300] \"GET /ru/?utm_source=tg&utm_medium=social&utm_campaign=vs_marketplace HTTP/1.1\" 200 999 \"-\" \"Mozilla/5.0 (iPhone; CPU iPhone OS 16_0)\"",
            // 3. Бот-превью TelegramBot — должен отсечься по UA, даже с валидными utm.
            "203.0.113.12 - - [11/Jul/2026:10:05:00 +0300] \"GET /ru/?utm_source=tg&utm_medium=social&utm_campaign=vs_marketplace HTTP/1.1\" 200 999 \"-\" \"Mozilla/5.0 (compatible; TelegramBot (like TwitterBot))\"",
            // 4. POST — не клик по ссылке, скип по методу.
            "203.0.113.13 - - [11/Jul/2026:10:10:00 +0300] \"POST /ru/?utm_source=tg&utm_medium=social&utm_campaign=vs_marketplace HTTP/1.1\" 200 999 \"-\" \"Mozilla/5.0\"",
            // 5. Кривая строка — не combined-формат, скип.
            "это не лог-строка вообще",
            // 6. Клик по посту A, но СТАРЕЕ старого measuredAt (09:00 UTC = 12:00 +0300) → не считаем.
            "203.0.113.14 - - [10/Jul/2026:08:00:00 +0300] \"GET /ru/brands/test-brand?utm_source=tg&utm_medium=social&utm_campaign=manifesto&utm_content=p{$idA} HTTP/1.1\" 200 1234 \"-\" \"Mozilla/5.0 (Linux; Android 10)\"",
        ]);

        $file = tempnam(sys_get_temp_dir(), 'social_clicks') . '.log';
        file_put_contents($file, $log);

        $command = (new Application(self::$kernel))->find('app:social:ingest-clicks');

        $tester1 = new CommandTester($command);
        $exit1 = $tester1->execute(['--file' => $file]);
        self::assertSame(0, $exit1);

        /** @var SocialPostMetricRepository $metrics */
        $metrics = self::getContainer()->get(SocialPostMetricRepository::class);

        $this->em->refresh($postA);
        $this->em->refresh($postB);

        $latestA = $metrics->findLatestForPost($postA);
        self::assertNotNull($latestA);
        self::assertSame(6, $latestA->getLinkTaps(), 'старые 5 + 1 новый валидный клик');

        $latestB = $metrics->findLatestForPost($postB);
        self::assertNotNull($latestB);
        self::assertSame(1, $latestB->getLinkTaps());

        // Второй прогон с тем же файлом — идемпотентность: новых снимков быть не должно.
        $tester2 = new CommandTester($command);
        $exit2 = $tester2->execute(['--file' => $file]);
        self::assertSame(0, $exit2);
        unlink($file);

        $latestA2 = $metrics->findLatestForPost($postA);
        $latestB2 = $metrics->findLatestForPost($postB);
        self::assertSame($latestA->getLinkTaps(), $latestA2->getLinkTaps(), 'повторный прогон не задвоил клики поста A');
        self::assertSame($latestB->getLinkTaps(), $latestB2->getLinkTaps(), 'повторный прогон не задвоил клики поста B');
    }
}
