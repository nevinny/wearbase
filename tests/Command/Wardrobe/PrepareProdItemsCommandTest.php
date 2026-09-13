<?php

declare(strict_types=1);

namespace App\Tests\Command\Wardrobe;

use App\Command\Wardrobe\PrepareProdItemsCommand;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeImageSanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Mac-команда app:wardrobe:prepare-prod-items: забирает очередь+фото с прода
 * (мок HTTP), распознаёт через WardrobeAiService (мок — реальную ollama/сеть
 * не трогаем) и пушит результат обратно. WardrobeImageSanitizer — реальный
 * (final, не мокается), фикстура фото — декодируемый JPEG.
 */
final class PrepareProdItemsCommandTest extends TestCase
{
    public function testDryRunRecognizesButDoesNotPushToProd(): void
    {
        $http = new MockHttpClient([
            $this->queueResponse([['id' => 101, 'wardrobe_id' => 5, 'owner_email' => 'owner@test.local']]),
            $this->photoResponse(),
        ]);
        $ai = $this->aiServiceReturning([
            'ok' => true,
            'fields' => ['category' => 'Платья', 'colorName' => 'белый', 'season' => 'summer'],
        ]);

        [$status, $display, $projectDir] = $this->execCommand($http, $ai, ['--dry-run' => true]);

        try {
            self::assertSame(Command::SUCCESS, $status);
            self::assertStringContainsString('распознано 1', $this->flatten($display));
            self::assertStringContainsString('На прод ничего не отправлено', $this->flatten($display));
            // queue + photo — ровно 2 запроса, POST /results не вызывался
            self::assertSame(2, $http->getRequestsCount());
        } finally {
            $this->cleanup($projectDir);
        }
    }

    public function testPushesRecognizedAttributesWhenNotDryRun(): void
    {
        $http = new MockHttpClient([
            $this->queueResponse([['id' => 202, 'wardrobe_id' => 5, 'owner_email' => 'owner@test.local']]),
            $this->photoResponse(),
            new MockResponse((string) json_encode(['status' => 'ok', 'updated' => 1, 'skipped' => 0, 'rejected' => []])),
        ]);
        $ai = $this->aiServiceReturning([
            'ok' => true,
            'fields' => ['category' => 'Платья', 'colorName' => 'белый', 'season' => 'summer'],
        ]);

        [$status, $display, $projectDir] = $this->execCommand($http, $ai, []);

        try {
            self::assertSame(Command::SUCCESS, $status);
            self::assertStringContainsString('применено 1', $this->flatten($display));
            self::assertSame(3, $http->getRequestsCount());
        } finally {
            $this->cleanup($projectDir);
        }
    }

    public function testAbortsWhenVisionLocalDisabled(): void
    {
        $http = new MockHttpClient([$this->queueResponse([])]);
        $ai = $this->createMock(WardrobeAiService::class);
        $ai->expects(self::once())->method('externalPhotoConsentRequired')->with(null)->willReturn(true);
        $ai->expects(self::never())->method('suggestFromPhoto');

        [$status, $display, $projectDir] = $this->execCommand($http, $ai, []);

        try {
            self::assertSame(Command::FAILURE, $status);
            self::assertStringContainsString('WARDROBE_VISION_LOCAL', $this->flatten($display));
            // Гейт согласия проверяется до любого HTTP-похода на прод.
            self::assertSame(0, $http->getRequestsCount());
        } finally {
            $this->cleanup($projectDir);
        }
    }

    public function testWardrobeFilterAppliesClientSideBeforeFetchingPhotos(): void
    {
        $http = new MockHttpClient([
            $this->queueResponse([
                ['id' => 301, 'wardrobe_id' => 5, 'owner_email' => 'a@test.local'],
                ['id' => 302, 'wardrobe_id' => 9, 'owner_email' => 'b@test.local'],
            ]),
            $this->photoResponse(),
            new MockResponse((string) json_encode(['status' => 'ok', 'updated' => 1, 'skipped' => 0, 'rejected' => []])),
        ]);
        $ai = $this->aiServiceReturning(['ok' => true, 'fields' => ['category' => 'Джинсы']]);

        [$status, , $projectDir] = $this->execCommand($http, $ai, ['--wardrobe' => '9']);

        try {
            self::assertSame(Command::SUCCESS, $status);
            // Отфильтрован только wardrobe_id=9 → ровно 1 фото-запрос, не 2.
            self::assertSame(3, $http->getRequestsCount());
        } finally {
            $this->cleanup($projectDir);
        }
    }

    public function testMissingPhotoIsSkippedAndNothingIsPushed(): void
    {
        $http = new MockHttpClient([
            $this->queueResponse([['id' => 404, 'wardrobe_id' => null, 'owner_email' => null]]),
            new MockResponse('', ['http_code' => 404]),
        ]);
        $ai = $this->createMock(WardrobeAiService::class);
        $ai->method('externalPhotoConsentRequired')->willReturn(false);
        $ai->expects(self::never())->method('suggestFromPhoto');

        [$status, $display, $projectDir] = $this->execCommand($http, $ai, []);

        try {
            self::assertSame(Command::SUCCESS, $status);
            self::assertStringContainsString('Нечего отправлять на прод', $this->flatten($display));
            // queue + photo(404) — POST /results не вызывается, отправлять нечего.
            self::assertSame(2, $http->getRequestsCount());
        } finally {
            $this->cleanup($projectDir);
        }
    }

    /** @param array{ok:bool,fields?:array,error?:string} $suggestFromPhotoResult */
    private function aiServiceReturning(array $suggestFromPhotoResult): WardrobeAiService
    {
        $ai = $this->createMock(WardrobeAiService::class);
        $ai->method('externalPhotoConsentRequired')->willReturn(false);
        $ai->expects(self::once())->method('suggestFromPhoto')->with(self::isString(), null)->willReturn($suggestFromPhotoResult);

        return $ai;
    }

    /** @param array<int, array{id:int,wardrobe_id:?int,owner_email:?string}> $items */
    private function queueResponse(array $items): MockResponse
    {
        return new MockResponse((string) json_encode(['items' => $items]));
    }

    private function photoResponse(): MockResponse
    {
        return new MockResponse($this->tinyJpegBytes(), ['http_code' => 200]);
    }

    /** @return array{0:int,1:string,2:string} status, display, projectDir (для cleanup) */
    private function execCommand(MockHttpClient $http, WardrobeAiService $ai, array $input): array
    {
        $projectDir = sys_get_temp_dir().'/wardrobe_prod_prepare_'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/var', 0777, true);

        $command = new PrepareProdItemsCommand($http, $ai, new WardrobeImageSanitizer(), 'https://prod.test', 'agent-token', $projectDir);
        $tester = new CommandTester($command);
        $status = $tester->execute($input);

        return [$status, $tester->getDisplay(), $projectDir];
    }

    /** SymfonyStyle переносит длинные строки по словам — убираем переносы для устойчивых substring-проверок. */
    private function flatten(string $display): string
    {
        return preg_replace('/\s+/u', ' ', $display);
    }

    private function cleanup(string $projectDir): void
    {
        @unlink($projectDir.'/var/wardrobe_ingest_drafts.lock');
        @rmdir($projectDir.'/var');
        @rmdir($projectDir);
    }

    private function tinyJpegBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image, null, 92);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
