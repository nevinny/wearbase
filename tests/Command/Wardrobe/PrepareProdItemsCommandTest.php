<?php

declare(strict_types=1);

namespace App\Tests\Command\Wardrobe;

use App\Command\Wardrobe\PrepareProdItemsCommand;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeImageSanitizer;
use App\Service\Wardrobe\WildberriesAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Mac-команда app:wardrobe:prepare-prod-items: забирает очередь с прода (мок HTTP) и
 * прогоняет каждую вещь через приоритетную цепочку источников — WB-карточка
 * (WildberriesAdapter, мок) → фото (WardrobeAiService::suggestFromPhoto, мок) →
 * название/известные данные (WardrobeAiService::suggestAttributesFromNames, мок) —
 * и одним запросом пушит результат обратно. Реальная ollama/сеть/WB нигде не трогаются.
 * WardrobeImageSanitizer — реальный (final, не мокается), фикстура фото — декодируемый JPEG.
 */
final class PrepareProdItemsCommandTest extends TestCase
{
    public function testDryRunRecognizesButDoesNotPushToProd(): void
    {
        $http = new MockHttpClient([
            $this->queueResponse([['id' => 101, 'wardrobe_id' => 5, 'owner_email' => 'owner@test.local', 'has_photo' => true]]),
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
            $this->queueResponse([['id' => 202, 'wardrobe_id' => 5, 'owner_email' => 'owner@test.local', 'has_photo' => true]]),
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
                ['id' => 301, 'wardrobe_id' => 5, 'owner_email' => 'a@test.local', 'has_photo' => true],
                ['id' => 302, 'wardrobe_id' => 9, 'owner_email' => 'b@test.local', 'has_photo' => true],
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

    /**
     * Раньше 404 у фото означало «пропускаем вещь целиком» (0 запросов сверх фото,
     * ничего не отправлено). Теперь фото — не последний рубеж: вещь без фото и без
     * WB-карточки падает в текстовый батч по названию. Если и он не даёт данных
     * (пустой ответ модели) — вещь остаётся ни с чем, POST так и не вызывается.
     */
    public function testMissingPhotoFallsBackToTextBatchAndNothingIsPushedWhenBatchAlsoEmpty(): void
    {
        $http = new MockHttpClient([
            $this->queueResponse([['id' => 404, 'wardrobe_id' => null, 'owner_email' => null, 'has_photo' => true, 'name' => 'Вещь без опознаваемых атрибутов']]),
            new MockResponse('', ['http_code' => 404]),
        ]);
        $ai = $this->createMock(WardrobeAiService::class);
        $ai->method('externalPhotoConsentRequired')->willReturn(false);
        $ai->expects(self::never())->method('suggestFromPhoto');
        $ai->expects(self::once())->method('suggestAttributesFromNames')->willReturn([]);

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

    /**
     * WB-карточка — приоритетный источник: даёт все 4 поля + известную категорию из
     * очереди → вещь БЕЗ похода за фото (suggestFromPhoto ни разу не вызывается), сезон
     * (которого в характеристиках WB нет) добирается текстовым батчем по названию.
     */
    public function testWbCardTakesPriorityAndSkipsPhotoWhenComplete(): void
    {
        $http = new MockHttpClient([
            $this->queueResponse([[
                'id' => 501, 'wardrobe_id' => 5, 'owner_email' => 'owner@test.local',
                'has_photo' => true, 'name' => 'Шёлковое платье', 'category' => 'Платья',
                'material_text' => null, 'product_url' => 'https://www.wildberries.ru/catalog/13578826/detail.aspx',
            ]]),
            new MockResponse((string) json_encode(['status' => 'ok', 'updated' => 1, 'skipped' => 0, 'rejected' => []])),
        ]);
        $wb = $this->createMock(WildberriesAdapter::class);
        $wb->expects(self::once())->method('fetchCard')
            ->with('https://www.wildberries.ru/catalog/13578826/detail.aspx')
            ->willReturn(['colorName' => 'сиреневый', 'materialText' => 'шёлк', 'countryOfOrigin' => 'Россия', 'careText' => 'деликатная стирка']);

        $ai = $this->createMock(WardrobeAiService::class);
        $ai->method('externalPhotoConsentRequired')->willReturn(false);
        $ai->expects(self::never())->method('suggestFromPhoto');
        $ai->expects(self::once())->method('suggestAttributesFromNames')
            ->with([['id' => 501, 'name' => 'Шёлковое платье', 'category' => 'Платья', 'materialText' => 'шёлк']])
            ->willReturn([501 => ['colorName' => null, 'materialText' => null, 'season' => 'summer']]);

        [$status, , $projectDir] = $this->execCommand($http, $ai, [], $wb);

        try {
            self::assertSame(Command::SUCCESS, $status);
            // queue + POST — фото не запрашивалось вовсе (только 2 запроса, не 3).
            self::assertSame(2, $http->getRequestsCount());
        } finally {
            $this->cleanup($projectDir);
        }
    }

    /**
     * WB-карточка не назвала цвет (только состав) — фото всё равно нужно добрать то,
     * чего WB не дал (см. WardrobeDailyController::prepareQueue() докблок про
     * приоритет источников: «фото — для того, чего WB не дал»).
     */
    public function testPhotoStillRunsWhenWbCardDidNotProvideColor(): void
    {
        $http = new MockHttpClient([
            $this->queueResponse([[
                'id' => 601, 'wardrobe_id' => 5, 'owner_email' => 'owner@test.local',
                'has_photo' => true, 'name' => 'Платье', 'category' => 'Платья',
                'material_text' => null, 'product_url' => 'https://www.wildberries.ru/catalog/1/detail.aspx',
            ]]),
            $this->photoResponse(),
            new MockResponse((string) json_encode(['status' => 'ok', 'updated' => 1, 'skipped' => 0, 'rejected' => []])),
        ]);
        $wb = $this->createMock(WildberriesAdapter::class);
        $wb->method('fetchCard')->willReturn(['colorName' => null, 'materialText' => 'шёлк', 'countryOfOrigin' => null, 'careText' => null]);

        $ai = $this->aiServiceReturning(['ok' => true, 'fields' => ['colorName' => 'сиреневый', 'season' => 'summer']]);

        [$status, , $projectDir] = $this->execCommand($http, $ai, [], $wb);

        try {
            self::assertSame(Command::SUCCESS, $status);
            self::assertSame(3, $http->getRequestsCount());
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

    /** @param array<int, array<string,mixed>> $items */
    private function queueResponse(array $items): MockResponse
    {
        return new MockResponse((string) json_encode(['items' => $items]));
    }

    private function photoResponse(): MockResponse
    {
        return new MockResponse($this->tinyJpegBytes(), ['http_code' => 200]);
    }

    /** @return array{0:int,1:string,2:string} status, display, projectDir (для cleanup) */
    private function execCommand(MockHttpClient $http, WardrobeAiService $ai, array $input, ?WildberriesAdapter $wildberries = null): array
    {
        $projectDir = sys_get_temp_dir().'/wardrobe_prod_prepare_'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/var', 0777, true);

        $command = new PrepareProdItemsCommand(
            $http,
            $ai,
            new WardrobeImageSanitizer(),
            $wildberries ?? $this->createStub(WildberriesAdapter::class),
            'https://prod.test',
            'agent-token',
            $projectDir,
        );
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
