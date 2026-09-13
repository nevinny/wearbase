<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Entity\WardrobeOutfit;
use App\Service\LlmService;
use App\Service\Wardrobe\WardrobeOutfitService;
use App\Service\Wardrobe\WardrobeStylistContextBuilder;
use App\Service\Wardrobe\WeatherProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Только Mac, крон в 5:00. Прод физически не достаёт до домашнего GPU-рига (ни LAN,
 * ни Tailscale) — Mac достаёт и до рига, и до прод-API, поэтому направление одно:
 * Mac забирает каталог (GET /api/v1/wardrobe/daily/catalog), для каждого гардероба
 * и повода собирает образы ЛОКАЛЬНОЙ ollama (LlmService::generate(local: true)) и
 * пушит их на прод (POST /api/v1/wardrobe/daily/outfits) — тот проверяет
 * принадлежность вещей гардеробу и идемпотентно заменяет образы за тот же день+повод.
 *
 * Модель большая и висит на PCIe gen1 x1 — таймаут генерации щедрый (батч, не
 * интерактив: торопиться некуда).
 *
 * flock — защита от параллельного второго экземпляра (паттерн IngestWardrobeDraftsCommand).
 */
#[AsCommand(
    name: 'app:wardrobe:daily-outfits',
    description: 'Ночной батч: собрать образы «на утро» для всех гардеробов и запушить на прод',
)]
class GenerateDailyOutfitsCommand extends Command
{
    private const HTTP_TIMEOUT_SEC = 60;
    private const LLM_TIMEOUT_SEC = 600;
    private const MAX_OUTFITS = 6;

    /** @var resource|null держим открытым весь прогон (flock) */
    private $lockHandle = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LlmService $llm,
        private readonly WardrobeOutfitService $outfits,
        private readonly WeatherProvider $weather,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $apiToken,
        #[Autowire('%env(WARDROBE_OUTFIT_LOCAL_MODEL)%')]
        private readonly string $localModel,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('wardrobe', null, InputOption::VALUE_REQUIRED, 'Только один гардероб по прод wardrobe_id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать результат, ничего не пушить на прод')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ограничить число гардеробов за прогон')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->acquireLock()) {
            $io->note('Другой экземпляр уже запущен (var/wardrobe_daily_outfits.lock) — выходим.');
            return Command::SUCCESS;
        }

        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->apiToken) === '') {
            $io->error('Не заданы PROD_API_URL / AGENT_API_TOKEN в .env.local — команда только с Mac.');
            return Command::FAILURE;
        }

        $onlyWardrobeId = $input->getOption('wardrobe') !== null ? (int) $input->getOption('wardrobe') : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;

        try {
            $response = $this->httpClient->request('GET', $this->prodUrl('/api/v1/wardrobe/daily/catalog'), [
                'headers' => ['X-Agent-Token' => $this->apiToken],
                'timeout' => self::HTTP_TIMEOUT_SEC,
            ]);
            $wardrobes = $response->toArray(false)['wardrobes'] ?? [];
        } catch (\Throwable $e) {
            $io->error('Не удалось получить каталог с прода: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (!is_array($wardrobes)) {
            $io->error('Прод вернул неожиданный формат каталога.');
            return Command::FAILURE;
        }

        if ($onlyWardrobeId !== null) {
            $wardrobes = array_values(array_filter(
                $wardrobes,
                static fn (array $w): bool => (int) ($w['wardrobe_id'] ?? 0) === $onlyWardrobeId,
            ));
        }
        if ($limit !== null) {
            $wardrobes = array_slice($wardrobes, 0, $limit);
        }

        if ($wardrobes === []) {
            $io->text('Нет гардеробов для генерации.');
            return Command::SUCCESS;
        }

        // Один запрос погоды на весь прогон — город зашит константой (Moscow) в WeatherProvider,
        // одна и та же погода для всех гардеробов. Fail-soft: null = образы без погодного контекста.
        $weatherPair = $this->weather->current();
        $weatherContext = $weatherPair !== null ? sprintf('condition:%s;temperature:%s', $weatherPair[0], $weatherPair[1]) : null;
        if ($weatherContext === null) {
            $io->text('Погода недоступна — образы собираются без погодного контекста.');
        }

        $io->title(sprintf('Ночной батч образов: %d гардеробов%s', count($wardrobes), $dryRun ? ' (dry-run)' : ''));
        $io->progressStart(count($wardrobes) * count(WardrobeOutfit::DAILY_OCCASIONS));

        $created = 0;
        $failed = 0;

        foreach ($wardrobes as $wardrobe) {
            $wardrobeId = (int) ($wardrobe['wardrobe_id'] ?? 0);
            $items = is_array($wardrobe['items'] ?? null) ? $wardrobe['items'] : [];
            if ($wardrobeId === 0 || count($items) < 2) {
                $io->progressAdvance(count(WardrobeOutfit::DAILY_OCCASIONS));
                continue;
            }

            // Ротацию (fresh/recent) считает прод-сторона (WardrobeDailyController::catalog(),
            // тот же WardrobeStylistContextBuilder, что интерактивный путь) — Mac её только читает,
            // без доступа к WardrobeWearEventRepository прода посчитать честно всё равно нельзя.
            $catalogRows = array_values(array_map(static fn (array $item): array => [
                'id' => (int) ($item['id'] ?? 0),
                'category' => $item['category'] ?? null,
                'color' => $item['colorName'] ?? null,
                'season' => $item['season'] ?? null,
                'styles' => is_array($item['styles'] ?? null) ? $item['styles'] : [],
                'rotation' => (string) ($item['rotation'] ?? 'fresh'),
                'name' => $item['name'] ?: 'Без названия',
                'material' => $item['materialText'] ?? null,
            ], $items));
            $preferenceContext = (string) ($wardrobe['preference_context'] ?? '');

            foreach (WardrobeOutfit::DAILY_OCCASIONS as $occasion => $requestText) {
                // Повод батча как event — только если он входит в аллоулист билдера (work/walk);
                // theater/meeting туда не отображаются намеренно — это не отдельный словарь, а
                // тот же request-текст уже несёт повод для промпта.
                $event = in_array($occasion, WardrobeStylistContextBuilder::EVENTS, true) ? $occasion : null;
                try {
                    $prompt = $this->outfits->buildPrompt(
                        $catalogRows,
                        $requestText,
                        $preferenceContext,
                        ['event' => $event, 'weather' => $weatherContext],
                        false,
                        self::MAX_OUTFITS,
                    );
                    $response = $this->llm->generate(
                        $prompt,
                        model: $this->localModel,
                        timeout: self::LLM_TIMEOUT_SEC,
                        local: true,
                        think: false,
                        temperature: 0.4,
                    );
                    $parsed = $this->outfits->parseOutfits($response, self::MAX_OUTFITS);
                } catch (\Throwable $e) {
                    $failed++;
                    $io->text(sprintf('  гардероб #%d / %s: ошибка генерации — %s', $wardrobeId, $occasion, $e->getMessage()));
                    $io->progressAdvance();
                    continue;
                }

                if ($parsed === []) {
                    $io->progressAdvance();
                    continue;
                }

                if ($dryRun) {
                    $io->text(sprintf('  гардероб #%d / %s: %d образов (dry-run)', $wardrobeId, $occasion, count($parsed)));
                    $io->progressAdvance();
                    continue;
                }

                try {
                    $result = $this->push($wardrobeId, $occasion, $requestText, $parsed);
                    if ($result['consentDenied']) {
                        $io->text(sprintf('  гардероб #%d / %s: нет согласия на персонализацию — пропущено', $wardrobeId, $occasion));
                    } else {
                        $created += $result['created'];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $io->text(sprintf('  гардероб #%d / %s: пуш на прод не прошёл — %s', $wardrobeId, $occasion, $e->getMessage()));
                }
                $io->progressAdvance();
            }
        }

        $io->progressFinish();
        $io->success(sprintf('Готово: создано %d образов, ошибок %d', $created, $failed));

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array{title:string, explanation:string, item_ids:int[]}> $outfits
     * @return array{created:int, consentDenied:bool}
     */
    private function push(int $wardrobeId, string $occasion, string $requestText, array $outfits): array
    {
        $response = $this->httpClient->request('POST', $this->prodUrl('/api/v1/wardrobe/daily/outfits'), [
            'headers' => ['X-Agent-Token' => $this->apiToken],
            'json' => [
                'wardrobe_id' => $wardrobeId,
                'occasion' => $occasion,
                'request' => $requestText,
                'outfits' => $outfits,
            ],
            'timeout' => self::HTTP_TIMEOUT_SEC,
        ]);
        $data = $response->toArray(false);

        return [
            'created' => (int) ($data['created'] ?? 0),
            // Прод молча пропускает гардеробы без согласия на персонализацию (200, не 4xx) —
            // это не ошибка пуша, а штатный отказ, который нужно просто отметить в логе.
            'consentDenied' => ($data['status'] ?? null) === 'consent_denied',
        ];
    }

    private function prodUrl(string $path): string
    {
        return rtrim((string) $this->prodApiUrl, '/') . $path;
    }

    /** Эксклюзивный flock — защита от случайного второго экземпляра (паттерн IngestWardrobeDraftsCommand). */
    private function acquireLock(): bool
    {
        $path = $this->projectDir . '/var/wardrobe_daily_outfits.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->lockHandle = $handle; // держим открытым — иначе GC снимет lock

        return true;
    }
}
