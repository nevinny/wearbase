<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeImageSanitizer;
use App\Service\Wardrobe\WildberriesAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Только Mac. Вещи и фото гардероба живут на проде, а прод физически не
 * достаёт до домашнего GPU-рига (см. класс-докблок WardrobeDailyController) —
 * поэтому Mac инициирует оба конца: забирает очередь вещей без AI-атрибутов
 * (GET /api/v1/wardrobe/daily/prepare/queue) и одним запросом пушит результат
 * (POST .../prepare/results) — тот заполняет ТОЛЬКО пустые поля вещи.
 *
 * Приоритет источников на вещь (структурные данные надёжнее любого распознавания):
 *   1. WB-карточка (product_url на wildberries.ru) — WildberriesAdapter::fetchCard():
 *      состав/цвет/страна/уход прямо с фабричной карточки. Сезона там нет никогда.
 *   2. Фото (обложка галереи ИЛИ legacy-поле photo, has_photo) — ЛОКАЛЬНАЯ ollama,
 *      WardrobeAiService::suggestFromPhoto() — но только для того, чего WB не дал
 *      (или для вещей совсем без WB-ссылки/карточки).
 *   3. Название + то немногое, что уже известно (category/materialText) — тоже
 *      локальная ollama, батчем по TEXT_BATCH_SIZE вещей за один вызов модели.
 *      Это ЕДИНСТВЕННЫЙ источник season для вещей, обогащённых через WB (в
 *      характеристиках WB сезона нет) — и последний резерв colorName/materialText
 *      для вещей без WB-ссылки и без фото.
 *
 * Риг — единственный слот (OLLAMA_NUM_PARALLEL=1, шины PCIe gen1 x1): вызовы модели
 * (фото и текстовые батчи) идут строго последовательно, без параллелизма.
 *
 * flock — общий с app:wardrobe:prepare-existing-items / app:wardrobe:ingest-drafts
 * (var/wardrobe_ingest_drafts.lock): все три шлют запросы в тот же единственный ollama.
 */
#[AsCommand(
    name: 'app:wardrobe:prepare-prod-items',
    description: 'Забрать с прода вещи без AI-атрибутов, обогатить (WB → фото → название), вернуть на прод',
)]
final class PrepareProdItemsCommand extends Command
{
    /** Потолок на прогон: вызовы модели идут в единственный инстанс ollama последовательно. */
    private const MAX_LIMIT = 25;
    private const HTTP_TIMEOUT_SEC = 60;
    /** 8–12 вещей за один вызов модели — золотая середина: заметно дешевле поштучных вызовов, промпт не распухает. */
    private const TEXT_BATCH_SIZE = 10;

    /** @var resource|null держим открытым весь прогон (flock) */
    private $lockHandle = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly WardrobeAiService $ai,
        private readonly WardrobeImageSanitizer $sanitizer,
        private readonly WildberriesAdapter $wildberries,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $apiToken,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум вещей за запуск (не больше '.self::MAX_LIMIT.')', 15)
            ->addOption('wardrobe', null, InputOption::VALUE_REQUIRED, 'Только один гардероб по прод wardrobe_id')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Только вещи владельца по email (прод)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Распознать и показать атрибуты, ничего не пушить на прод')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->acquireLock()) {
            $io->note('Другой запуск уже занял ollama (var/wardrobe_ingest_drafts.lock) — выходим.');
            return Command::SUCCESS;
        }

        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->apiToken) === '') {
            $io->error('Не заданы PROD_API_URL / AGENT_API_TOKEN в .env.local — команда только с Mac.');
            return Command::FAILURE;
        }

        // Тот же гейт согласия, что у prepare-existing-items — не изобретаем свою
        // проверку. При WARDROBE_VISION_LOCAL=1 (Mac .env.local) он всегда false;
        // если false — эта команда обязана остановиться: иначе фото прода уйдут
        // во внешний AI-сервис, а не на домашний риг. WB-карточка и текстовый батч
        // фото не трогают вовсе, но фото-тир (шаг 2) — трогает, поэтому гейт общий.
        if ($this->ai->externalPhotoConsentRequired(null)) {
            $io->error('WARDROBE_VISION_LOCAL должен быть включён на Mac — иначе фото прода уйдут во внешний AI-сервис.');
            return Command::FAILURE;
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) $input->getOption('limit')));
        $wardrobeId = $input->getOption('wardrobe') !== null ? (int) $input->getOption('wardrobe') : null;
        $email = $input->getOption('user') !== null ? mb_strtolower(trim((string) $input->getOption('user'))) : null;
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $response = $this->httpClient->request('GET', $this->prodUrl('/api/v1/wardrobe/daily/prepare/queue'), [
                'headers' => ['X-Agent-Token' => $this->apiToken],
                'timeout' => self::HTTP_TIMEOUT_SEC,
            ]);
            $queue = $response->toArray(false)['items'] ?? [];
        } catch (\Throwable $e) {
            $io->error('Не удалось получить очередь с прода: '.$e->getMessage());
            return Command::FAILURE;
        }

        if (!is_array($queue)) {
            $io->error('Прод вернул неожиданный формат очереди.');
            return Command::FAILURE;
        }

        if ($wardrobeId !== null) {
            $queue = array_values(array_filter(
                $queue,
                static fn (array $row): bool => (int) ($row['wardrobe_id'] ?? 0) === $wardrobeId,
            ));
        }
        if ($email !== null) {
            $queue = array_values(array_filter(
                $queue,
                static fn (array $row): bool => mb_strtolower((string) ($row['owner_email'] ?? '')) === $email,
            ));
        }
        $queue = array_slice($queue, 0, $limit);

        if ($queue === []) {
            $io->text('Нет вещей для подготовки.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Подготовка атрибутов: %d вещей%s', count($queue), $dryRun ? ' (dry-run)' : ''));
        $io->progressStart(count($queue));

        /** @var array<int, array<string,mixed>> $results id => поля вещи (без ключа id внутри) */
        $results = [];
        /** @var list<array{id:int,name:?string,category:?string,materialText:?string}> $textQueue */
        $textQueue = [];

        foreach ($queue as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id === 0) {
                $io->progressAdvance();
                continue;
            }

            $entry = [];
            $knownCategory = is_string($row['category'] ?? null) ? trim($row['category']) : '';
            $knownMaterial = is_string($row['material_text'] ?? null) && trim($row['material_text']) !== '' ? $row['material_text'] : null;

            // 1. WB-карточка — приоритетный источник, WardrobeAiService/vision тут не участвуют.
            $productUrl = $row['product_url'] ?? null;
            $wb = (is_string($productUrl) && str_contains(strtolower($productUrl), 'wildberries.ru'))
                ? $this->wildberries->fetchCard($productUrl)
                : null;
            if ($wb !== null) {
                $this->fillIfMissing($entry, 'colorName', $wb['colorName']);
                $this->fillIfMissing($entry, 'materialText', $wb['materialText']);
                $this->fillIfMissing($entry, 'countryOfOrigin', $wb['countryOfOrigin']);
                $this->fillIfMissing($entry, 'careText', $wb['careText']);
            }

            // 2. Фото — только для того, чего WB не дал (или совсем без ссылки/карточки).
            $season = null;
            $needPhoto = ($row['has_photo'] ?? false) === true
                && ($wb === null || ($wb['colorName'] ?? null) === null || $knownCategory === '');
            if ($needPhoto) {
                [$photoFields, $photoError] = $this->recognizeFromPhoto($id);
                if ($photoFields !== null) {
                    $this->fillIfMissing($entry, 'category', $photoFields['category'] ?? null);
                    $this->fillIfMissing($entry, 'colorName', $photoFields['colorName'] ?? null);
                    $this->fillIfMissing($entry, 'materialText', $photoFields['materialText'] ?? null);
                    $season = $photoFields['season'] ?? null;
                } elseif ($dryRun) {
                    $io->text(sprintf('  вещь #%d: фото — %s', $id, $photoError));
                }
            }

            // 3. Название/известные данные — единственный источник season для WB-вещей
            // (в характеристиках WB его нет) и последний резерв colorName/materialText.
            if ($season !== null) {
                $entry['season'] = $season;
            } else {
                $textQueue[] = [
                    'id' => $id,
                    'name' => $row['name'] ?? null,
                    'category' => $entry['category'] ?? ($knownCategory !== '' ? $knownCategory : null),
                    'materialText' => $entry['materialText'] ?? $knownMaterial,
                ];
            }

            $results[$id] = $entry;
            $io->progressAdvance();
        }

        $io->progressFinish();

        foreach (array_chunk($textQueue, self::TEXT_BATCH_SIZE) as $chunk) {
            $batch = $this->ai->suggestAttributesFromNames($chunk);
            foreach ($chunk as $chunkRow) {
                $id = $chunkRow['id'];
                $fields = $batch[$id] ?? null;
                if ($fields === null) {
                    continue;
                }
                $this->fillIfMissing($results[$id], 'colorName', $fields['colorName'] ?? null);
                $this->fillIfMissing($results[$id], 'materialText', $fields['materialText'] ?? null);
                if (($fields['season'] ?? null) !== null) {
                    $results[$id]['season'] = $fields['season'];
                }
            }
        }

        $finalResults = [];
        $recognized = 0;
        $failed = 0;
        foreach ($results as $id => $entry) {
            if ($entry === []) {
                $failed++;
                if ($dryRun) {
                    $io->text(sprintf('  вещь #%d: ничего не удалось определить', $id));
                }
                continue;
            }
            $recognized++;
            $entry = ['id' => $id] + $entry;
            $finalResults[] = $entry;
            if ($dryRun) {
                $io->text(sprintf('  вещь #%d: %s', $id, json_encode($entry, JSON_UNESCAPED_UNICODE)));
            }
        }

        if ($dryRun) {
            $io->success(sprintf('Готово (dry-run): распознано %d вещей, ничего не найдено у %d. На прод ничего не отправлено.', $recognized, $failed));
            return Command::SUCCESS;
        }

        if ($finalResults === []) {
            $io->success(sprintf('Нечего отправлять на прод (распознано 0, ничего не найдено у %d).', $failed));
            return Command::SUCCESS;
        }

        try {
            $push = $this->httpClient->request('POST', $this->prodUrl('/api/v1/wardrobe/daily/prepare/results'), [
                'headers' => ['X-Agent-Token' => $this->apiToken],
                'json' => ['items' => $finalResults],
                'timeout' => self::HTTP_TIMEOUT_SEC,
            ]);
            $data = $push->toArray(false);
        } catch (\Throwable $e) {
            $io->error('Не удалось отправить результаты на прод: '.$e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Готово: применено %d, без изменений %d, ничего не найдено у %d.',
            (int) ($data['updated'] ?? 0),
            (int) ($data['skipped'] ?? 0),
            $failed,
        ));

        return Command::SUCCESS;
    }

    /**
     * Фото-тир: тянет байты с прода, санитайзит, распознаёт ЛОКАЛЬНОЙ ollama.
     * $user = null: сущности User прода нет в локальной БД Mac — идентификатор тут не
     * нужен, WardrobeAiService при visionLocal=true не трогает consent-репозиторий.
     *
     * @return array{0: ?array{category?:?string,colorName?:?string,materialText?:?string,season?:?string}, 1: ?string} [поля, сообщение об ошибке]
     */
    private function recognizeFromPhoto(int $id): array
    {
        $rawPath = null;
        $sanitizedPath = null;
        try {
            $photoResponse = $this->httpClient->request('GET', $this->prodUrl('/api/v1/wardrobe/daily/prepare/photo/'.$id), [
                'headers' => ['X-Agent-Token' => $this->apiToken],
                'timeout' => self::HTTP_TIMEOUT_SEC,
            ]);
            if ($photoResponse->getStatusCode() !== 200) {
                return [null, sprintf('фото не получено (HTTP %d)', $photoResponse->getStatusCode())];
            }

            $rawPath = tempnam(sys_get_temp_dir(), 'wardrobe_prod_photo_');
            file_put_contents($rawPath, $photoResponse->getContent(false));

            $sanitized = $this->sanitizer->sanitize(
                new UploadedFile($rawPath, 'item.jpg', (string) mime_content_type($rawPath), null, true),
            );
            $sanitizedPath = $sanitized->getPathname();

            $result = $this->ai->suggestFromPhoto($sanitizedPath, null);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return [null, $e->getMessage()];
        } catch (\Throwable $e) {
            return [null, 'ошибка запроса — '.$e->getMessage()];
        } finally {
            if ($rawPath !== null && is_file($rawPath)) {
                @unlink($rawPath);
            }
            if ($sanitizedPath !== null && is_file($sanitizedPath)) {
                @unlink($sanitizedPath);
            }
        }

        if (!($result['ok'] ?? false)) {
            return [null, $result['error'] ?? 'AI недоступен'];
        }

        return [$result['fields'] ?? [], null];
    }

    /** Заполняет поле только если оно ещё не установлено этим же прогоном (WB не переигрывает WB, фото не переигрывает WB, и т.д. — приоритет источников). */
    private function fillIfMissing(array &$entry, string $key, mixed $value): void
    {
        if ($value !== null && !array_key_exists($key, $entry)) {
            $entry[$key] = $value;
        }
    }

    private function prodUrl(string $path): string
    {
        return rtrim((string) $this->prodApiUrl, '/').$path;
    }

    /** Общий лок с app:wardrobe:prepare-existing-items / app:wardrobe:ingest-drafts: все шлют фото в единственный ollama. */
    private function acquireLock(): bool
    {
        $path = $this->projectDir.'/var/wardrobe_ingest_drafts.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->lockHandle = $handle;

        return true;
    }
}
