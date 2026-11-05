<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SocialPublishTickCommand;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Notification\AdminNotifier;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostRepository;
use App\Service\Social\RampSchedule;
use App\Social\Publisher\SocialPublisherInterface;
use App\Social\Publisher\SocialPublisherRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:social:publish-tick: алерт админу ровно на переходе в publish_failed (3-я неудача),
 * не раньше; сбой нотификатора тик не роняет.
 */
class SocialPublishTickFailedAlertTest extends KernelTestCase
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

    private function makePost(int $attempts): SocialPost
    {
        $channel = (new SocialChannel())
            ->setPlatform(SocialChannel::PLATFORM_IG)
            ->setName('Chan <b>')
            ->setTarget('1')
            ->setEnabled(true)
            ->setEgressHost(SocialChannel::HOST_MAC);
        $this->em->persist($channel);

        $post = (new SocialPost())
            ->setChannel($channel)
            ->setRubric('brand_reels')
            ->setMediaType(SocialPost::MEDIA_REELS)
            ->setStatus(SocialPost::STATUS_SCHEDULED)
            ->setScheduledAt(new \DateTime('-1 hour'))
            ->setPublishAttempts($attempts);
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    private function runTick(SocialPost $post, AdminNotifier $notifier): CommandTester
    {
        $publisher = new class implements SocialPublisherInterface {
            public function platform(): string
            {
                return 'ig';
            }

            public function publish(SocialChannel $channel, SocialPost $post, array $mediaAbsPaths): string
            {
                throw new \RuntimeException('rsync failed <denied>');
            }
        };
        // Выборки очереди/квот — MySQL-специфичный SQL (NOW, FOR UPDATE SKIP LOCKED), на SQLite не идут: мокаем репозитории,
        // тестируем то, что делает команда (переходы статуса и алерт) на реальных сущностях.
        $channels = $this->createMock(SocialChannelRepository::class);
        $channels->method('findEnabledByHost')->willReturn([$post->getChannel()]);
        $posts = $this->createMock(SocialPostRepository::class);
        $posts->method('claimOne')->willReturn([$post]);
        $posts->method('countPublishedToday')->willReturn(0);
        $posts->method('countPublishedLast24h')->willReturn(0);
        $command = new SocialPublishTickCommand(
            $this->em,
            $channels,
            $posts,
            new SocialPublisherRegistry([$publisher]),
            $notifier,
            new RampSchedule(),
            self::getContainer()->getParameter('kernel.project_dir'),
            '',
            50,
            50,
        );
        $tester = new CommandTester($command);
        $tester->execute(['--post' => (string) $post->getId()]);

        return $tester;
    }

    public function testThirdFailureSendsOneEscapedAlert(): void
    {
        $post = $this->makePost(2);
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('isEnabled')->willReturn(true);
        $notifier->expects(self::once())->method('send')->with(self::callback(
            static fn (string $m) => str_contains($m, '#' . $post->getId())
                && str_contains($m, 'Chan &lt;b&gt;')
                && str_contains($m, 'rsync failed &lt;denied&gt;')
                && str_contains($m, 'brand_reels'),
        ));

        $this->runTick($post, $notifier);

        self::assertSame(SocialPost::STATUS_PUBLISH_FAILED, $post->getStatus());
    }

    public function testEarlierFailureDoesNotAlert(): void
    {
        $post = $this->makePost(0);
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('isEnabled')->willReturn(true);
        $notifier->expects(self::never())->method('send');

        $this->runTick($post, $notifier);

        self::assertSame(SocialPost::STATUS_SCHEDULED, $post->getStatus());
        self::assertSame(1, $post->getPublishAttempts());
    }

    public function testNotifierFailureDoesNotBreakTick(): void
    {
        $post = $this->makePost(2);
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('isEnabled')->willReturn(true);
        $notifier->method('send')->willThrowException(new \RuntimeException('tg down'));

        $tester = $this->runTick($post, $notifier);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(SocialPost::STATUS_PUBLISH_FAILED, $post->getStatus());
    }
}
