<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CastbaseConnectIgCommand;
use App\Command\SocialCastbaseSyncCommand;
use App\Command\SocialHandoffCommand;
use App\Command\SocialPublishTickCommand;
use App\Entity\CastbaseSyncCursor;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Notification\AdminNotifier;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostRepository;
use App\Service\SecretCipher;
use App\Service\Social\RampSchedule;
use App\Social\Castbase\CastbaseClient;
use App\Social\Publisher\SocialPublisherInterface;
use App\Social\Publisher\SocialPublisherRegistry;
use App\Social\SocialAlerts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Публикация IG через castbase: handoff, sync, connect-ig, пропуск локальным тиком.
 * HTTP замокан (MockHttpClient), БД — SQLite с откатом транзакции; репозитории настоящие
 * (findForHandoff/claimForHandoff — портативные, без NOW()).
 */
class SocialCastbaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $projectDir;
    private string $mediaDir;
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: array<mixed>|null}> */
    private array $requests = [];
    /** @var list<callable(string, string): ?MockResponse> */
    private array $responders = [];
    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
        $this->projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->mediaDir = $this->projectDir . '/var/castbase-test-media';
        @mkdir($this->mediaDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        array_map('unlink', glob($this->mediaDir . '/*') ?: []);
        @rmdir($this->mediaDir);
        parent::tearDown();
    }

    // ---------- handoff ----------

    public function testHandoffSendsPostOnceWithCtaCaptionAndIdempotencyKey(): void
    {
        $post = $this->post(['caption' => "Хук\n\nТекст\n\n#один #два", 'cta' => 'Смотри бренд']);
        $this->responders[] = fn (string $m, string $u) => str_ends_with($u, '/v1/posts') ? $this->json(['id' => 'cb-post-1', 'status' => 'scheduled'], 201) : null;

        $this->runHandoff();
        $this->runHandoff(); // второй запуск: пост уже handed_off с castbase_post_id — второго POST нет

        $posts = $this->postsRequests();
        self::assertCount(1, $posts);
        self::assertSame('wb-sp-' . $post->getId(), $posts[0]['headers']['idempotency-key']);
        self::assertSame('social_post:' . $post->getId(), $posts[0]['body']['external_ref']);
        self::assertSame(['castbase-chan-1'], $posts[0]['body']['channel_ids']);
        self::assertSame("Хук\n\nТекст\n\nСмотри бренд — ссылка в профиле\n\n#один #два", $posts[0]['body']['caption']);
        self::assertSame('cb-post-1', $post->getCastbasePostId());
        self::assertSame(SocialPost::STATUS_HANDED_OFF, $post->getStatus());
    }

    public function testConditionalClaimLetsOnlyOneRunHandOffThePost(): void
    {
        $post = $this->post();
        $repo = self::getContainer()->get(SocialPostRepository::class);

        self::assertTrue($repo->claimForHandoff($post->getId(), new \DateTimeImmutable()));
        self::assertFalse($repo->claimForHandoff($post->getId(), new \DateTimeImmutable()));
    }

    public function testNetworkErrorKeepsPostHandedOffAndRetryReusesSameKeys(): void
    {
        $post = $this->post(['media' => 'reels', 'cover' => true]);
        $fail = true;
        $this->responders[] = function (string $m, string $u) use (&$fail) {
            if (str_ends_with($u, '/v1/posts')) {
                return $fail ? new MockResponse('', ['error' => 'connection reset']) : $this->json(['id' => 'cb-post-2'], 201);
            }

            return null;
        };

        $this->runHandoff();
        self::assertSame(SocialPost::STATUS_HANDED_OFF, $post->getStatus());
        self::assertNull($post->getCastbasePostId());
        self::assertSame(1, $post->getPublishAttempts());

        $fail = false;
        $this->runHandoff();

        $keys = array_map(fn (array $r) => $r['headers']['idempotency-key'], array_filter($this->requests, fn ($r) => $r['method'] === 'POST'));
        $id = $post->getId();
        // media: m0 + cover, post — ровно одни и те же ключи в обеих попытках
        self::assertSame(
            ["wb-sp-{$id}-m0", "wb-sp-{$id}-cover", "wb-sp-{$id}", "wb-sp-{$id}-m0", "wb-sp-{$id}-cover", "wb-sp-{$id}"],
            array_values($keys),
        );
        self::assertSame('cb-post-2', $post->getCastbasePostId());
    }

    public function testCarouselKeepsSlideOrderAndReelsGetCoverAndOptions(): void
    {
        $carousel = $this->post(['media' => 'carousel', 'slides' => 3]);
        $this->responders[] = fn (string $m, string $u) => str_ends_with($u, '/v1/posts') ? $this->json(['id' => 'cb-c'], 201) : null;
        $this->runHandoff();

        $ids = array_column($this->postsRequests()[0]['body']['media'], 'media_id');
        self::assertSame(['m-0', 'm-1', 'm-2'], $ids);
        self::assertArrayNotHasKey('options', $this->postsRequests()[0]['body']);
        self::assertSame('cb-c', $carousel->getCastbasePostId());

        $this->requests = [];
        $this->post(['media' => 'reels', 'cover' => true]);
        $this->runHandoff();
        $body = $this->postsRequests()[0]['body'];
        self::assertSame(['item', 'cover'], array_column($body['media'], 'role'));
        self::assertSame('WEARBASE · Прямой бренд', $body['options']['audio_name']);
        self::assertTrue($body['options']['share_to_feed']);
    }

    public function testShadowRunUsesDryRunSeparateRefAndKeepsStatus(): void
    {
        $post = $this->post();
        $this->responders[] = fn (string $m, string $u) => str_ends_with($u, '/v1/posts') ? $this->json(['id' => 'cb-shadow'], 201) : null;

        $tester = $this->runHandoff(['--post' => (string) $post->getId(), '--shadow' => true]);

        $body = $this->postsRequests()[0]['body'];
        self::assertTrue($body['options']['dry_run']);
        self::assertMatchesRegularExpression('/^social_post:' . $post->getId() . ':shadow:\d+$/', $body['external_ref']);
        self::assertSame(SocialPost::STATUS_SCHEDULED, $post->getStatus());
        self::assertNull($post->getCastbasePostId());
        self::assertSame(0, $tester->getStatusCode());
    }

    public function testShadowWorksForLocalEnabledChannelWithCastbaseChannelId(): void
    {
        $post = $this->post(['via' => SocialChannel::VIA_LOCAL]);
        $this->responders[] = fn (string $m, string $u) => str_ends_with($u, '/v1/posts') ? $this->json(['id' => 'cb-shadow'], 201) : null;

        $tester = $this->runHandoff(['--post' => (string) $post->getId(), '--shadow' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertTrue($this->postsRequests()[0]['body']['options']['dry_run']);
        self::assertSame(SocialPost::STATUS_SCHEDULED, $post->getStatus());
        self::assertSame(SocialChannel::VIA_LOCAL, $post->getChannel()->getPublishVia());
    }

    public function testShadowExplainsDisabledDryRunFromFieldErrorCode(): void
    {
        $post = $this->post();
        // Как отвечает castbase (PostValidator::options): верхний code — validation_failed, причина — в errors[].code.
        $this->responders[] = fn (string $m, string $u) => str_ends_with($u, '/v1/posts') ? $this->json([
            'code' => 'validation_failed', 'detail' => 'Опция не поддерживается. Доступны: share_to_feed, audio_name.',
            'errors' => [['field' => 'options.dry_run', 'code' => 'unsupported_option', 'message' => 'Опция не поддерживается.']],
        ], 422) : null;

        $tester = $this->runHandoff(['--post' => (string) $post->getId(), '--shadow' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('IG_DRY_RUN_ENABLED', $tester->getDisplay());
        self::assertStringContainsString('options.dry_run:unsupported_option', $tester->getDisplay());
    }

    public function testExternalRefTakenWithoutIdIsRetriedNotFailed(): void
    {
        $post = $this->post();
        // Гонка вставок в castbase (SchedulePost): 409 external_ref_taken, а id в detail нет.
        $this->responders[] = fn (string $m, string $u) => str_ends_with($u, '/v1/posts')
            ? $this->json(['code' => 'external_ref_taken', 'detail' => 'Пост с external_ref «social_post:1» уже есть.'], 409) : null;

        $this->runHandoff();

        self::assertSame(SocialPost::STATUS_HANDED_OFF, $post->getStatus());
        self::assertNull($post->getCastbasePostId());
        self::assertSame(1, $post->getPublishAttempts());
    }

    public function testCastbaseClientSecretsAreSensitiveParameters(): void
    {
        $ctor = new \ReflectionMethod(CastbaseClient::class, '__construct');
        $connect = new \ReflectionMethod(CastbaseClient::class, 'connectInstagram');
        foreach ([$ctor->getParameters()[2], $connect->getParameters()[0]] as $param) {
            self::assertNotEmpty($param->getAttributes(\SensitiveParameter::class), $param->getName());
        }
    }

    public function testLocalChannelPostsAreNotHandedOff(): void
    {
        $this->post(['via' => SocialChannel::VIA_LOCAL]);

        $this->runHandoff();

        self::assertSame([], $this->requests);
    }

    public function testPermanentApiErrorMarksPublishFailedAndAlerts(): void
    {
        $post = $this->post();
        $this->responders[] = fn (string $m, string $u) => str_ends_with($u, '/v1/posts')
            ? $this->json(['code' => 'validation_failed', 'detail' => 'Подпись длиннее 2200', 'errors' => [['field' => 'caption', 'code' => 'too_long', 'message' => 'Подпись длиннее 2200']]], 422) : null;
        $notifier = $this->notifier();
        $notifier->expects(self::once())->method('send')->with(self::stringContains('#' . $post->getId()));

        $this->runHandoff([], $notifier);

        self::assertSame(SocialPost::STATUS_PUBLISH_FAILED, $post->getStatus());
        self::assertStringContainsString('validation_failed', (string) $post->getLastError());
        self::assertStringContainsString('caption:too_long', (string) $post->getLastError());
    }

    // ---------- sync ----------

    public function testSyncAppliesPublishedAndFailedAndCursorPreventsReapply(): void
    {
        $ok = $this->post(['status' => SocialPost::STATUS_HANDED_OFF]);
        $bad = $this->post(['status' => SocialPost::STATUS_HANDED_OFF]);
        $feed = [
            ['seq' => 5, 'type' => 'post.published', 'created_at' => '2026-11-05T09:10:41+00:00', 'data' => ['external_ref' => 'social_post:' . $ok->getId(), 'external_id' => '179000']],
            ['seq' => 6, 'type' => 'post.failed', 'created_at' => '2026-11-05T09:11:00+00:00', 'data' => ['external_ref' => 'social_post:' . $bad->getId(), 'error_code' => 'reauth_required', 'error_message' => 'токен']],
            ['seq' => 7, 'type' => 'post.dry_run_ok', 'created_at' => '2026-11-05T09:12:00+00:00', 'data' => ['external_ref' => 'social_post:' . $ok->getId() . ':shadow:1']],
        ];
        $afters = [];
        $this->responders[] = function (string $m, string $u) use ($feed, &$afters) {
            if (!str_contains($u, '/v1/events')) {
                return null;
            }
            parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
            $afters[] = (int) $q['after'];
            $data = array_values(array_filter($feed, fn ($e) => $e['seq'] > (int) $q['after']));

            return $this->json(['data' => $data, 'next_after' => $data === [] ? (int) $q['after'] : 7]);
        };
        $notifier = $this->notifier();
        $notifier->expects(self::once())->method('send'); // только failed

        $this->runSync($notifier);

        self::assertSame(SocialPost::STATUS_PUBLISHED, $ok->getStatus());
        self::assertSame('179000', $ok->getExternalId());
        self::assertSame('2026-11-05 09:10:41', $ok->getPublishedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame(SocialPost::STATUS_PUBLISH_FAILED, $bad->getStatus());
        self::assertStringContainsString('reauth_required', (string) $bad->getLastError());
        self::assertSame(7, $this->em->find(CastbaseSyncCursor::class, 'events')->getSeq());

        $this->runSync($notifier); // повтор: читает с курсора 7, ничего не применяет и не алертит второй раз
        self::assertSame([0, 7, 7], $afters);
        self::assertSame(SocialPost::STATUS_PUBLISHED, $ok->getStatus());
    }

    public function testOutcomeUnknownBecomesPublishFailedWithManualCheckAlertAndIsNotRetried(): void
    {
        $post = $this->post(['status' => SocialPost::STATUS_HANDED_OFF, 'castbasePostId' => 'cb-9']);
        $this->responders[] = function (string $m, string $u) use ($post) {
            parse_str((string) parse_url($u, PHP_URL_QUERY), $q);

            return $this->json((int) $q['after'] >= 3
                ? ['data' => [], 'next_after' => 3]
                : ['data' => [['seq' => 3, 'type' => 'post.outcome_unknown', 'created_at' => '2026-11-05T09:10:41+00:00', 'data' => ['external_ref' => 'social_post:' . $post->getId(), 'error_code' => 'network_error', 'error_message' => 'обрыв']]], 'next_after' => 3]);
        };
        $notifier = $this->notifier();
        $notifier->expects(self::once())->method('send')->with(self::stringContains('Проверь профиль'));

        $this->runSync($notifier);

        self::assertSame(SocialPost::STATUS_PUBLISH_FAILED, $post->getStatus());
        self::assertStringStartsWith('outcome_unknown:', (string) $post->getLastError());
        // handoff такой пост не берёт — автоповтора нет
        $this->requests = [];
        $this->runHandoff();
        self::assertSame([], $this->requests);
    }

    public function testRehandoffAfterFinishedCastbasePostUsesNewGenerationKeyAndRef(): void
    {
        // оператор вернул в scheduled пост, чей castbase-пост уже завершился failed/outcome_unknown
        $post = $this->post(['castbasePostId' => 'cb-old', 'outcome' => 'failed']);
        $this->responders[] = fn (string $m, string $u) => str_ends_with($u, '/v1/posts') ? $this->json(['id' => 'cb-new'], 201) : null;

        $this->runHandoff();

        $req = $this->postsRequests()[0];
        $id = $post->getId();
        self::assertSame("wb-sp-{$id}-g1", $req['headers']['idempotency-key']);
        self::assertSame("social_post:{$id}:g1", $req['body']['external_ref']);
        self::assertSame('cb-new', $post->getCastbasePostId());
        self::assertSame(1, $post->getCastbaseGeneration());

        // события старого поколения не трогают пост, нового — применяются
        $this->requests = [];
        $this->responders = [function (string $m, string $u) use ($id) {
            if (str_contains($u, 'after=2')) {
                return $this->json(['data' => [], 'next_after' => 2]);
            }

            return $this->json(['data' => [
                ['seq' => 1, 'type' => 'post.failed', 'created_at' => '2026-11-05T09:00:00+00:00', 'data' => ['external_ref' => "social_post:{$id}", 'error_code' => 'x', 'error_message' => 'старый']],
                ['seq' => 2, 'type' => 'post.published', 'created_at' => '2026-11-05T09:10:00+00:00', 'data' => ['external_ref' => "social_post:{$id}:g1", 'external_id' => '555']],
            ], 'next_after' => 2]);
        }];
        $this->runSync($this->notifier());
        self::assertSame(SocialPost::STATUS_PUBLISHED, $post->getStatus());
        self::assertSame('555', $post->getExternalId());
    }

    /** @return array<string, array{?string}> */
    public static function liveCastbasePostOutcomes(): array
    {
        return ['уже опубликован' => ['published'], 'ещё в работе у castbase' => [null]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveCastbasePostOutcomes')]
    public function testReturnToScheduledWhileCastbasePostIsLiveDoesNotDuplicate(?string $outcome): void
    {
        $post = $this->post(['castbasePostId' => 'cb-live', 'outcome' => $outcome]);
        $notifier = $this->notifier();
        $notifier->expects(self::once())->method('send')->with(self::stringContains('сверь'));

        $this->runHandoff([], $notifier);

        self::assertSame([], $this->requests, 'ни медиа, ни поста в castbase');
        self::assertSame(SocialPost::STATUS_PUBLISH_FAILED, $post->getStatus());
        self::assertSame('cb-live', $post->getCastbasePostId());
        self::assertSame(0, $post->getCastbaseGeneration());
    }

    public function testSyncRecordsOutcomeOfCurrentGeneration(): void
    {
        $ok = $this->post(['status' => SocialPost::STATUS_HANDED_OFF]);
        $bad = $this->post(['status' => SocialPost::STATUS_HANDED_OFF]);
        $this->responders[] = function (string $m, string $u) use ($ok, $bad) {
            if (str_contains($u, 'after=2')) {
                return $this->json(['data' => [], 'next_after' => 2]);
            }

            return $this->json(['data' => [
                ['seq' => 1, 'type' => 'post.published', 'created_at' => '2026-11-05T09:00:00+00:00', 'data' => ['external_ref' => 'social_post:' . $ok->getId(), 'external_id' => '1']],
                ['seq' => 2, 'type' => 'post.failed', 'created_at' => '2026-11-05T09:00:00+00:00', 'data' => ['external_ref' => 'social_post:' . $bad->getId(), 'error_code' => 'x']],
            ], 'next_after' => 2]);
        };
        $this->runSync($this->notifier());

        self::assertSame('published', $ok->getCastbaseOutcome());
        self::assertSame('failed', $bad->getCastbaseOutcome());
    }

    // ---------- tick ----------

    public function testLocalTickSkipsCastbaseChannelPost(): void
    {
        $post = $this->post(['status' => SocialPost::STATUS_SCHEDULED]);
        $publisher = new class implements SocialPublisherInterface {
            public int $calls = 0;
            public function platform(): string { return 'ig'; }
            public function publish(SocialChannel $channel, SocialPost $post, array $mediaAbsPaths): string { ++$this->calls; return 'x'; }
        };
        $channels = $this->createMock(SocialChannelRepository::class);
        $channels->method('findEnabledByHost')->willReturn([$post->getChannel()]);
        $posts = $this->createMock(SocialPostRepository::class);
        $posts->method('claimOne')->willReturn([$post]);
        $posts->method('countPublishedToday')->willReturn(0);
        $posts->method('countPublishedLast24h')->willReturn(0);
        $tick = new SocialPublishTickCommand($this->em, $channels, $posts, new SocialPublisherRegistry([$publisher]), $this->notifier(), new RampSchedule(), $this->projectDir, '', 50, 50);

        (new CommandTester($tick))->execute(['--post' => (string) $post->getId()]);

        self::assertSame(0, $publisher->calls);
        self::assertSame(SocialPost::STATUS_SCHEDULED, $post->getStatus());
    }

    // ---------- connect-ig ----------

    public function testConnectIgSendsDecryptedTokenWaitsForActiveAndNeverPrintsIt(): void
    {
        $channel = $this->igChannel();
        // castbase: POST /v1/channels → 202 pending; итог проверки — GET /v1/channels/{id}.
        $this->responders[] = fn (string $m, string $u) => $m === 'POST' && str_ends_with($u, '/v1/channels') ? $this->json(['id' => 'cb-chan-7', 'status' => 'pending'], 202) : null;
        $this->responders[] = fn (string $m, string $u) => $m === 'GET' && str_ends_with($u, '/v1/channels/cb-chan-7') ? $this->json(['id' => 'cb-chan-7', 'status' => 'active', 'username' => 'wearbase', 'kind' => 'byo']) : null;

        $tester = $this->runConnect($channel);

        $req = $this->requests[0];
        self::assertSame('IGAA-secret-token', $req['body']['access_token']);
        self::assertSame('instagram', $req['body']['platform']);
        self::assertMatchesRegularExpression('/^wb-ch-' . $channel->getId() . '-[0-9a-f]{16}-new-a1$/', $req['headers']['idempotency-key']);
        self::assertStringNotContainsString('IGAA-secret-token', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('cb-chan-7', $channel->getCastbaseChannelId());
        self::assertSame(SocialChannel::VIA_LOCAL, $channel->getPublishVia()); // переключатель не трогаем
    }

    public function testConnectIgFailedTokenCheckKeepsChannelIdAndExplainsReason(): void
    {
        $channel = $this->igChannel();
        $this->responders[] = fn (string $m, string $u) => $m === 'POST' ? $this->json(['id' => 'cb-chan-8', 'status' => 'pending'], 202) : null;
        $this->responders[] = fn (string $m, string $u) => $m === 'GET' ? $this->json(['id' => 'cb-chan-8', 'status' => 'failed', 'status_reason' => 'not_professional', 'status_message' => 'Аккаунт не Professional']) : null;

        $tester = $this->runConnect($channel);

        self::assertSame(1, $tester->getStatusCode());
        self::assertNull($channel->getCastbaseChannelId());
        self::assertStringContainsString('not_professional', $tester->getDisplay());
    }

    public function testConnectIgDoesNothingWhenCastbaseChannelIsActive(): void
    {
        $channel = $this->igChannel()->setCastbaseChannelId('cb-chan-1');
        $this->em->flush();
        $this->responders[] = fn (string $m, string $u) => $m === 'GET' ? $this->json(['id' => 'cb-chan-1', 'status' => 'active', 'username' => 'wearbase']) : null;

        $tester = $this->runConnect($channel);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], array_filter($this->requests, fn ($r) => $r['method'] === 'POST'));
    }

    public function testConnectIgAfterReauthRequiredUsesNewKeyAndStoresNewChannel(): void
    {
        $channel = $this->igChannel()->setCastbaseChannelId('cb-chan-old');
        $this->em->flush();
        $this->responders[] = fn (string $m, string $u) => $m === 'GET' && str_ends_with($u, '/cb-chan-old') ? $this->json(['id' => 'cb-chan-old', 'status' => 'reauth_required']) : null;
        $this->responders[] = fn (string $m, string $u) => $m === 'POST' ? $this->json(['id' => 'cb-chan-new', 'status' => 'pending'], 202) : null;
        $this->responders[] = fn (string $m, string $u) => $m === 'GET' && str_ends_with($u, '/cb-chan-new') ? $this->json(['id' => 'cb-chan-new', 'status' => 'active']) : null;

        $tester = $this->runConnect($channel);

        $post = array_values(array_filter($this->requests, fn ($r) => $r['method'] === 'POST'))[0];
        self::assertDoesNotMatchRegularExpression('/-new-a1$/', $post['headers']['idempotency-key']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('cb-chan-new', $channel->getCastbaseChannelId());
    }

    private function igChannel(): SocialChannel
    {
        $cipher = self::getContainer()->get(SecretCipher::class);
        $channel = (new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG)->setName('wb')->setTarget('1')
            ->setTokenEnc($cipher->encrypt('IGAA-secret-token'))->setEnabled(true);
        $this->em->persist($channel);
        $this->em->flush();

        return $channel;
    }

    private function runConnect(SocialChannel $channel): CommandTester
    {
        $tester = new CommandTester(new CastbaseConnectIgCommand($this->em, $this->client(), self::getContainer()->get(SecretCipher::class)));
        $tester->execute(['--channel' => (string) $channel->getId(), '--wait' => '0']);

        return $tester;
    }

    // ---------- helpers ----------

    /** @param array<string, mixed> $o */
    private function post(array $o = []): SocialPost
    {
        $channel = (new SocialChannel())->setPlatform(SocialChannel::PLATFORM_IG)->setName('Chan')->setTarget('1')
            ->setEnabled(true)->setPublishVia($o['via'] ?? SocialChannel::VIA_CASTBASE)->setCastbaseChannelId('castbase-chan-1');
        $this->em->persist($channel);

        $type = $o['media'] ?? 'image';
        $n = $o['slides'] ?? 1;
        $paths = [];
        for ($i = 0; $i < $n; ++$i) {
            file_put_contents($this->mediaDir . "/s{$i}.jpg", "img{$i}");
            $paths[] = "/../var/castbase-test-media/s{$i}.jpg";
        }
        $post = (new SocialPost())->setChannel($channel)->setRubric('brand_reels')
            ->setMediaType($type === 'reels' ? SocialPost::MEDIA_REELS : ($type === 'carousel' ? SocialPost::MEDIA_CAROUSEL : SocialPost::MEDIA_IMAGE))
            ->setMediaPaths($paths)->setCaption($o['caption'] ?? 'Подпись')->setCtaLabel($o['cta'] ?? null)
            ->setStatus($o['status'] ?? SocialPost::STATUS_SCHEDULED)->setScheduledAt(new \DateTime('+1 hour'))
            ->setCastbasePostId($o['castbasePostId'] ?? null)->setCastbaseOutcome($o['outcome'] ?? null);
        if (!empty($o['cover'])) {
            file_put_contents($this->mediaDir . '/cover.jpg', 'cover');
            $post->setCoverPath('/../var/castbase-test-media/cover.jpg');
        }
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    private function client(): CastbaseClient
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $opt) {
            $headers = [];
            foreach ($opt['headers'] as $h) {
                [$k, $v] = explode(': ', $h, 2);
                $headers[strtolower($k)] = $v;
            }
            $body = isset($opt['body']) && is_string($opt['body']) && str_starts_with($headers['content-type'] ?? '', 'application/json')
                ? json_decode($opt['body'], true) : null;
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            foreach ($this->responders as $r) {
                if (($resp = $r($method, $url)) !== null) {
                    return $resp;
                }
            }
            if (str_ends_with($url, '/v1/media')) {
                return $this->json(['media_id' => 'm-' . (count(array_filter($this->requests, fn ($q) => str_ends_with($q['url'], '/v1/media'))) - 1 - $this->mediaOffset()), 'status' => 'ready'], 201);
            }

            return $this->json(['data' => [], 'next_after' => 0]);
        });

        return new CastbaseClient($mock, 'http://castbase.test', 'cb_live_test');
    }

    /** media_id считается от начала текущего прогона: m-0, m-1 … в порядке загрузки. */
    private function mediaOffset(): int
    {
        return $this->mediaBase;
    }

    private int $mediaBase = 0;

    /** @param array<string, mixed> $json */
    private function json(array $json, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($json), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: array<mixed>|null}> */
    private function postsRequests(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => str_ends_with($r['url'], '/v1/posts')));
    }

    private function notifier(): AdminNotifier
    {
        $n = $this->createMock(AdminNotifier::class);
        $n->method('isEnabled')->willReturn(true);

        return $n;
    }

    /** @param array<string, mixed> $input */
    private function runHandoff(array $input = [], ?AdminNotifier $notifier = null): CommandTester
    {
        $this->mediaBase = count(array_filter($this->requests, fn ($q) => str_ends_with($q['url'], '/v1/media')));
        $cmd = new SocialHandoffCommand(
            $this->em,
            self::getContainer()->get(SocialPostRepository::class),
            $this->client(),
            new SocialAlerts($notifier ?? $this->notifier()),
            $this->projectDir,
            null,
        );
        $tester = new CommandTester($cmd);
        $tester->execute($input);

        return $tester;
    }

    private function runSync(AdminNotifier $notifier): CommandTester
    {
        $tester = new CommandTester(new SocialCastbaseSyncCommand($this->em, $this->client(), new SocialAlerts($notifier)));
        $tester->execute([]);

        return $tester;
    }
}
