<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeImageSanitizer;
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
 * (GET /api/v1/wardrobe/daily/prepare/queue), тянет фото по одному
 * (GET .../prepare/photo/{id}), распознаёт ЛОКАЛЬНОЙ ollama через
 * WardrobeAiService::suggestFromPhoto() и одним запросом пушит результат
 * (POST .../prepare/results) — тот заполняет ТОЛЬКО пустые поля вещи.
 *
 * Риг — единственный слот (OLLAMA_NUM_PARALLEL=1, шины PCIe gen1 x1): фото
 * обрабатываются строго последовательно, без параллелизма.
 *
 * flock — общий с app:wardrobe:prepare-existing-items / app:wardrobe:ingest-drafts
 * (var/wardrobe_ingest_drafts.lock): все три шлют фото в тот же единственный ollama.
 */
#[AsCommand(
    name: 'app:wardrobe:prepare-prod-items',
    description: 'Забрать с прода вещи без AI-атрибутов, распознать на домашнем риге, вернуть на прод',
)]
final class PrepareProdItemsCommand extends Command
{
    /** Потолок на прогон: фото идут в единственный инстанс ollama последовательно (как у prepare-existing-items). */
    private const MAX_LIMIT = 25;
    private const HTTP_TIMEOUT_SEC = 60;

    /** @var resource|null держим открытым весь прогон (flock) */
    private $lockHandle = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly WardrobeAiService $ai,
        private readonly WardrobeImageSanitizer $sanitizer,
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
        // во внешний AI-сервис, а не на домашний риг.
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

        $results = [];
        $recognized = 0;
        $failed = 0;

        foreach ($queue as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id === 0) {
                $io->progressAdvance();
                continue;
            }

            $rawPath = null;
            $sanitizedPath = null;
            try {
                $photoResponse = $this->httpClient->request('GET', $this->prodUrl('/api/v1/wardrobe/daily/prepare/photo/'.$id), [
                    'headers' => ['X-Agent-Token' => $this->apiToken],
                    'timeout' => self::HTTP_TIMEOUT_SEC,
                ]);
                if ($photoResponse->getStatusCode() !== 200) {
                    $failed++;
                    $io->text(sprintf('  вещь #%d: фото не получено (HTTP %d)', $id, $photoResponse->getStatusCode()));
                    $io->progressAdvance();
                    continue;
                }

                $rawPath = tempnam(sys_get_temp_dir(), 'wardrobe_prod_photo_');
                file_put_contents($rawPath, $photoResponse->getContent(false));

                $sanitized = $this->sanitizer->sanitize(
                    new UploadedFile($rawPath, 'item.jpg', (string) mime_content_type($rawPath), null, true),
                );
                $sanitizedPath = $sanitized->getPathname();

                // $user = null: сущности User прода нет в локальной БД Mac — идентификатор
                // тут не нужен, WardrobeAiService при visionLocal=true не трогает
                // consent-репозиторий и не пишет ничего специфичное для юзера (см. её докблок).
                $result = $this->ai->suggestFromPhoto($sanitizedPath, null);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $failed++;
                $io->text(sprintf('  вещь #%d: %s', $id, $e->getMessage()));
                $io->progressAdvance();
                continue;
            } catch (\Throwable $e) {
                $failed++;
                $io->text(sprintf('  вещь #%d: ошибка запроса — %s', $id, $e->getMessage()));
                $io->progressAdvance();
                continue;
            } finally {
                if ($rawPath !== null && is_file($rawPath)) {
                    @unlink($rawPath);
                }
                if ($sanitizedPath !== null && is_file($sanitizedPath)) {
                    @unlink($sanitizedPath);
                }
            }

            if (!($result['ok'] ?? false)) {
                $failed++;
                $io->text(sprintf('  вещь #%d: %s', $id, $result['error'] ?? 'AI недоступен'));
                $io->progressAdvance();
                continue;
            }

            $fields = $result['fields'] ?? [];
            $entry = array_filter([
                'id' => $id,
                'category' => $fields['category'] ?? null,
                'colorName' => $fields['colorName'] ?? null,
                'materialText' => $fields['materialText'] ?? null,
                'season' => $fields['season'] ?? null,
            ], static fn ($value): bool => $value !== null);
            $results[] = $entry;
            $recognized++;

            if ($dryRun) {
                $io->text(sprintf('  вещь #%d: %s', $id, json_encode($entry, JSON_UNESCAPED_UNICODE)));
            }
            $io->progressAdvance();
        }

        $io->progressFinish();

        if ($dryRun) {
            $io->success(sprintf('Готово (dry-run): распознано %d вещей, ошибок %d. На прод ничего не отправлено.', $recognized, $failed));
            return Command::SUCCESS;
        }

        if ($results === []) {
            $io->success(sprintf('Нечего отправлять на прод (распознано 0, ошибок %d).', $failed));
            return Command::SUCCESS;
        }

        try {
            $push = $this->httpClient->request('POST', $this->prodUrl('/api/v1/wardrobe/daily/prepare/results'), [
                'headers' => ['X-Agent-Token' => $this->apiToken],
                'json' => ['items' => $results],
                'timeout' => self::HTTP_TIMEOUT_SEC,
            ]);
            $data = $push->toArray(false);
        } catch (\Throwable $e) {
            $io->error('Не удалось отправить результаты на прод: '.$e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Готово: применено %d, без изменений %d, ошибок распознавания %d.',
            (int) ($data['updated'] ?? 0),
            (int) ($data['skipped'] ?? 0),
            $failed,
        ));

        return Command::SUCCESS;
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
