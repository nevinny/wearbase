<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Entity\SocialPostMetric;
use App\Repository\SocialPostMetricRepository;
use App\Service\SecretCipher;
use App\Service\Social\InstagramInsights;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:social:collect-metrics: оркестрация сбора инсайтов IG (InstagramInsights замокан —
 * HTTP-контракт уже покрыт InstagramInsightsTest). Здесь проверяем то, что делает КОМАНДА:
 * отбор постов, перенос linkTaps из последнего снимка, dry-run и что ошибка одного поста
 * не роняет весь прогон.
 */
class SocialCollectMetricsCommandTest extends KernelTestCase
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

    public function testCollectsMetricsCarriesLinkTapsAndSkipsFailedPost(): void
    {
        /** @var SecretCipher $cipher */
        $cipher = self::getContainer()->get(SecretCipher::class);

        $channel = (new SocialChannel())
            ->setPlatform(SocialChannel::PLATFORM_IG)
            ->setName('Test IG')
            ->setTarget('17841400000000000')
            ->setTokenEnc($cipher->encrypt('ig-token'))
            ->setEnabled(true)
            ->setEgressHost(SocialChannel::HOST_MAC);
        $this->em->persist($channel);

        $reels = (new SocialPost())
            ->setChannel($channel)
            ->setRubric('brand_reels')
            ->setMediaType(SocialPost::MEDIA_REELS)
            ->setStatus(SocialPost::STATUS_PUBLISHED)
            ->setExternalId('17958698723984727')
            ->setPublishedAt(new \DateTime('-1 hour'));
        $this->em->persist($reels);

        $carousel = (new SocialPost())
            ->setChannel($channel)
            ->setRubric('brand_gallery')
            ->setMediaType(SocialPost::MEDIA_CAROUSEL)
            ->setStatus(SocialPost::STATUS_PUBLISHED)
            ->setExternalId('18138911335569367')
            ->setPublishedAt(new \DateTime('-2 hours'));
        $this->em->persist($carousel);

        // Пост, на который insights упадёт ошибкой — не должен ронять прогон целиком.
        $broken = (new SocialPost())
            ->setChannel($channel)
            ->setRubric('brand_reels')
            ->setMediaType(SocialPost::MEDIA_REELS)
            ->setStatus(SocialPost::STATUS_PUBLISHED)
            ->setExternalId('99999999999999999')
            ->setPublishedAt(new \DateTime('-3 hours'));
        $this->em->persist($broken);

        $this->em->flush();

        // У рилса уже есть старый снимок с linkTaps — коллектор обязан перенести его, не занулить.
        $oldMetric = (new SocialPostMetric())
            ->setPost($reels)
            ->setLinkTaps(9)
            ->setMeasuredAt(new \DateTime('-1 day'));
        $this->em->persist($oldMetric);
        $this->em->flush();

        $insightsMock = $this->createMock(InstagramInsights::class);
        $insightsMock->method('fetch')->willReturnCallback(
            function (string $mediaId, bool $isReels, string $token): array {
                self::assertSame('ig-token', $token);

                return match ($mediaId) {
                    '17958698723984727' => [
                        'reach'                   => 39,
                        'likes'                   => 5,
                        'comments'                => 1,
                        'shares'                  => 2,
                        'saved'                   => 3,
                        'views'                   => 44,
                        'total_interactions'      => 11,
                        'ig_reels_avg_watch_time' => 3143,
                    ],
                    '18138911335569367' => [
                        'reach'              => 1,
                        'likes'              => 0,
                        'comments'           => 0,
                        'shares'             => 0,
                        'saved'              => 0,
                        'total_interactions' => 1,
                    ],
                    default => throw new \RuntimeException('IG insights error: Unsupported get request (code 100)'),
                };
            },
        );
        self::getContainer()->set(InstagramInsights::class, $insightsMock);

        $command = (new Application(self::$kernel))->find('app:social:collect-metrics');
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--host' => SocialChannel::HOST_MAC]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Собрано метрик: 2, ошибок: 1', $tester->getDisplay());

        /** @var SocialPostMetricRepository $metrics */
        $metrics = self::getContainer()->get(SocialPostMetricRepository::class);

        $reelsMetric = $metrics->findLatestForPost($reels);
        self::assertNotNull($reelsMetric);
        self::assertSame(39, $reelsMetric->getReach());
        self::assertSame(44, $reelsMetric->getViews());
        self::assertSame(3143, $reelsMetric->getAvgWatchMs());
        self::assertSame(9, $reelsMetric->getLinkTaps(), 'linkTaps перенесён из старого снимка, не занулён');

        $carouselMetric = $metrics->findLatestForPost($carousel);
        self::assertNotNull($carouselMetric);
        self::assertSame(1, $carouselMetric->getReach());
        self::assertSame(0, $carouselMetric->getViews());
        self::assertSame(0, $carouselMetric->getAvgWatchMs());

        self::assertNull($metrics->findLatestForPost($broken), 'сломанный пост не получил снимок');
    }

    public function testDryRunDoesNotPersistSnapshot(): void
    {
        /** @var SecretCipher $cipher */
        $cipher = self::getContainer()->get(SecretCipher::class);

        $channel = (new SocialChannel())
            ->setPlatform(SocialChannel::PLATFORM_IG)
            ->setName('Test IG')
            ->setTarget('17841400000000000')
            ->setTokenEnc($cipher->encrypt('ig-token'))
            ->setEnabled(true)
            ->setEgressHost(SocialChannel::HOST_MAC);
        $this->em->persist($channel);

        $post = (new SocialPost())
            ->setChannel($channel)
            ->setRubric('brand_gallery')
            ->setMediaType(SocialPost::MEDIA_CAROUSEL)
            ->setStatus(SocialPost::STATUS_PUBLISHED)
            ->setExternalId('18138911335569367')
            ->setPublishedAt(new \DateTime('-1 hour'));
        $this->em->persist($post);
        $this->em->flush();

        $insightsMock = $this->createMock(InstagramInsights::class);
        $insightsMock->method('fetch')->willReturn(['reach' => 1]);
        self::getContainer()->set(InstagramInsights::class, $insightsMock);

        $command = (new Application(self::$kernel))->find('app:social:collect-metrics');
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--host' => SocialChannel::HOST_MAC, '--dry-run' => true]);

        self::assertSame(0, $exit);

        /** @var SocialPostMetricRepository $metrics */
        $metrics = self::getContainer()->get(SocialPostMetricRepository::class);
        self::assertNull($metrics->findLatestForPost($post), 'dry-run не пишет снимки');
    }
}
