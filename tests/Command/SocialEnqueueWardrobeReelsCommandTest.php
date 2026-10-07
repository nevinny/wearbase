<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SocialEnqueueWardrobeReelsCommand;
use App\Entity\SocialChannel;
use App\Entity\SocialPost;
use App\Repository\SocialChannelRepository;
use App\Repository\SocialPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SocialEnqueueWardrobeReelsCommandTest extends TestCase
{
    private string $root;
    private EntityManagerInterface $em;
    private SocialChannelRepository $channels;
    private SocialPostRepository $posts;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wardrobe-reels-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var', 0777, true);
        mkdir($this->root . '/public_html/images/social/pilot', 0777, true);
        file_put_contents($this->root . '/public_html/images/social/pilot/video.mp4', 'video');
        file_put_contents($this->root . '/public_html/images/social/pilot/cover.jpg', 'cover');
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->channels = $this->createMock(SocialChannelRepository::class);
        $this->channels->method('findOneBy')->willReturn((new SocialChannel())->setPlatform('ig'));
        $this->posts = $this->createMock(SocialPostRepository::class);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testPreviewNeverWritesPosts(): void
    {
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        self::assertSame(0, $this->executeImport([$this->entry('digitize-v1')]));
    }

    public function testScheduleCreatesOneReelPerDayWithPortablePaths(): void
    {
        $saved = [];
        $this->em->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (SocialPost $post) use (&$saved): void { $saved[] = $post; },
        );
        $this->em->expects(self::once())->method('flush');
        self::assertSame(0, $this->executeImport([$this->entry('digitize-v1'), $this->entry('family-v1')], true));
        self::assertSame('2099-01-01 21:00 +03:00', $saved[0]->getScheduledAt()->format('Y-m-d H:i P'));
        self::assertSame('2099-01-02 21:00 +03:00', $saved[1]->getScheduledAt()->format('Y-m-d H:i P'));
        self::assertSame('/images/social/pilot/video.mp4', $saved[0]->getMediaPath());
        self::assertSame('scheduled', $saved[0]->getStatus());
        self::assertSame('wardrobe-v1.family-v1', $saved[1]->getScriptKey());
        self::assertSame('reels', $saved[1]->getMediaType());
        self::assertNull($saved[0]->getCtaUrl());
    }

    public function testBrokenSecondEntryDoesNotPartiallyQueueBatch(): void
    {
        $broken = $this->entry('family-v1');
        $broken['video'] = '/images/social/missing.mp4';
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        self::assertSame(1, $this->executeImport([$this->entry('digitize-v1'), $broken], true));
    }

    public function testDuplicateImportSkipsExistingScript(): void
    {
        $this->posts->method('findOneBy')->willReturn(new SocialPost());
        $this->em->expects(self::never())->method('persist');
        self::assertSame(0, $this->executeImport([$this->entry('digitize-v1')], true));
    }

    public function testOccupiedDayIsNotSilentlyOverbooked(): void
    {
        $this->posts->method('existsForSlot')->willReturn(true);
        $this->em->expects(self::never())->method('persist');
        self::assertSame(1, $this->executeImport([$this->entry('digitize-v1')], true));
    }

    public function testPathCannotEscapePublicSocialDirectory(): void
    {
        file_put_contents($this->root . '/var/private.mp4', 'private');
        $entry = $this->entry('digitize-v1');
        $entry['video'] = '/images/social/../../../var/private.mp4';
        $this->em->expects(self::never())->method('persist');
        self::assertSame(1, $this->executeImport([$entry], true));
    }

    public function testRepeatedIdsInOneManifestAreRefused(): void
    {
        $this->em->expects(self::never())->method('persist');
        self::assertSame(1, $this->executeImport([$this->entry('digitize-v1'), $this->entry('digitize-v1')], true));
    }

    public function testReadyTemplateIsQueuedUnderTemplatesCampaign(): void
    {
        $saved = [];
        $this->em->expects(self::once())->method('persist')->willReturnCallback(
            static function (SocialPost $post) use (&$saved): void { $saved[] = $post; },
        );
        self::assertSame(0, $this->executeImport([$this->templateEntry()], true));
        self::assertSame('wardrobe-templates-v1.t11-kakoy-u-neyo-razmer-v1', $saved[0]->getScriptKey());
        self::assertSame('hook_1', $saved[0]->getVariant());
        self::assertSame(5, $saved[0]->getSlideCount());
        self::assertFalse($saved[0]->isAiGenerated());
        // Подпись и обложка берутся из манифеста как есть; CTA паблишера не дублирует строку из подписи.
        self::assertSame('Гардероб — ссылка в профиле.', $saved[0]->getCaption());
        self::assertSame('/images/social/pilot/cover.jpg', $saved[0]->getCoverPath());
        self::assertSame('wardrobe_reels', $saved[0]->getRubric());
        self::assertNull($saved[0]->getCtaLabel());
    }

    #[DataProvider('draftTemplateProvider')]
    public function testDraftTemplateIsNeverQueued(array $override): void
    {
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        self::assertSame(1, $this->executeImport([$this->templateEntry($override)], true));
    }

    public static function draftTemplateProvider(): iterable
    {
        yield 'draft=true' => [['draft' => true]];
        yield 'нет флага draft' => [['draft' => null]];
        yield 'нет ассетов' => [['assets_missing' => ['01', '04']]];
        yield 'переменные по умолчанию' => [['variables_missing' => ['N']]];
        yield 'шаблон не ready' => [['template' => ['status' => 'partial']]];
    }

    public function testTemplateIdWithLegacyCampaignIsRefused(): void
    {
        $this->em->expects(self::never())->method('persist');
        self::assertSame(1, $this->executeImport([$this->templateEntry(['campaign' => 'wardrobe-v1'])], true));
    }

    public function testDraftInBatchBlocksWholeBatch(): void
    {
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        self::assertSame(1, $this->executeImport([$this->entry('digitize-v1'), $this->templateEntry(['draft' => true])], true));
    }

    private function templateEntry(array $override = []): array
    {
        return array_merge($this->entry('t11-kakoy-u-neyo-razmer-v1'), [
            'campaign' => 'wardrobe-templates-v1', 'draft' => false, 'assets_missing' => [], 'variables_missing' => [],
            'template' => ['id' => 11, 'status' => 'ready'], 'ai_generated' => false,
            'beats' => [['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4], ['n' => 5]],
        ], $override);
    }

    private function entry(string $id): array
    {
        return [
            'id' => $id, 'campaign' => 'wardrobe-v1', 'fingerprint' => str_repeat('a', 64),
            'video' => '/old/mac/project/public_html/images/social/pilot/video.mp4',
            'cover' => '/images/social/pilot/cover.jpg', 'duration_ms' => 19000,
            'caption' => 'Гардероб — ссылка в профиле.', 'cta_url' => 'https://wearbase.ru/ru/wardrobe',
            'scenes' => [['text' => 'Сценарий']],
        ];
    }

    private function executeImport(array $entries, bool $schedule = false): int
    {
        $file = $this->root . '/manifest.json';
        file_put_contents($file, json_encode($entries));
        $tester = new CommandTester(new SocialEnqueueWardrobeReelsCommand($this->em, $this->channels, $this->posts, $this->root));
        return $tester->execute(['manifest' => $file, '--start' => '2099-01-01', '--schedule' => $schedule]);
    }
}
