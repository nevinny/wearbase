<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CompetitorArticleRepository;
use App\Service\ContentValidator;
use App\Service\LlmService;
use App\Service\NearDuplicateDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Батч из 5 НОВЫХ статей блога про гардероб — по образцу
 * `app:seo:wardrobe-blog-batch` (ветка content/wardrobe-blog-batch, PR #248, ещё не
 * смержена в эту ветку) — та команда здесь недоступна, поэтому quality-gate/промпт-
 * паттерн скопирован (не унаследован), а не расширен. Темы взяты из gap-анализа
 * конкурентов (docs/seo_competitor_content.md): `app:seo:competitor-site-crawl`
 * наполнил `CompetitorArticle` реальными блог-статьями fits-app.com и getwardrobe.com,
 * `LlmService::extractCompetitorTopics` вытащил из них ТОЛЬКО темы/структуру (не
 * факты — юридический риск пересказа запрещён промптом самого метода).
 *
 * ⚠️ Кандидат «Как собрать образ из вещей, которые уже есть» НЕ включён — при
 * ревью выяснилось, что это уже опубликованная статья на ветке
 * content/wardrobe-landing-seo-rewrite (var/seo/blog/wardrobe-obrazy-iz-svoih-veshchej-site.md).
 * Заменён на «Журнал носки» (тоже из gap-анализа: «аналитика использования вещей»
 * встречалась в global/family/packing темах, грунтуется в WEARBASE_FACTS напрямую).
 *
 * Anti-duplicate ОБЯЗАТЕЛЕН (в отличие от исходной команды): после каждой попытки
 * генерации тело сверяется `NearDuplicateDetector::similarity()` с текстами
 * конкурентов, у которых позаимствованы темы этой статьи (topic['competitorUrls'],
 * резолв по URL через `CompetitorArticleRepository::findByUrl` — не по
 * auto-increment id, устойчиво к пересозданию БД) — `NearDuplicateDetector::DROP_THRESHOLD`
 * (0.85) проваливает гейт так же, как остальные проверки (regen с fixHint).
 * Отсутствие/пустой контент хотя бы одного competitorUrl — фейл темы целиком
 * (гейт не должен молча проходить без сравнения).
 *
 *   php bin/console app:seo:wardrobe-competitor-gap-batch
 *   php bin/console app:seo:wardrobe-competitor-gap-batch --only=razbor-garderoba-pered-ocifrovkoj
 *   php bin/console app:seo:wardrobe-competitor-gap-batch --force
 */
#[AsCommand(
    name: 'app:seo:wardrobe-competitor-gap-batch',
    description: 'Батч из 5 статей блога про гардероб на темах из gap-анализа конкурентов (fits-app/getwardrobe) — anti-duplicate обязателен',
)]
class SeoWardrobeCompetitorGapBatchCommand extends Command
{
    private const OUT_DIR = 'var/seo/blog';
    private const MIN_WORDS = 800;
    private const MAX_ATTEMPTS = 3;
    private const CTA_URL = 'https://wearbase.ru/ru/wardrobe';
    private const TEMPS = [0.7, 0.6, 0.5];

    private const FORBIDDEN_ROOTS = ['уникальн', 'инноваци', 'передов', 'лидир', 'новатор', 'беспрецедент', 'несравн'];

    /**
     * Тот же блок, что `SeoWardrobeBlogBatchCommand::WEARBASE_FACTS` (см. её докблок
     * с происхождением фактов) — скопирован намеренно, команда исходного батча
     * недоступна на этой ветке (не смержен PR #248).
     */
    private const WEARBASE_FACTS = <<<'EOT'
- Личный цифровой гардероб в приложении WEARBASE: вещь фотографируют на телефон
  или добавляют по ссылке на товар в интернет-магазине (тогда часть данных,
  например название и размер, подтягивается автоматически).
- После загрузки фото AI распознаёт черновик карточки вещи: категорию
  (толстовка, брюки, ботинки и т.п.) и цвет; состав указывается только если на
  вещи видна читаемая бирка — не угадывается «на глаз».
- Бренд по фото AI НЕ определяет — это поле пользователь заполняет вручную.
- Черновик — это предложение, а не финальное решение: прежде чем вещь попадёт в
  активный гардероб, человек проверяет и может поправить любое поле.
- Вещи можно загружать по одной или пачкой.
- У вещи есть статусы: «носится», «на вырост», «мала», «отдана» (часть
  семейного сценария — передача вещей между членами семьи с историей).
- AI-стилист собирает сочетания образов ТОЛЬКО из вещей со статусом «носится»;
  за один раз предлагает до трёх вариантов образа, а не один.
- AI-стилист также учитывает текущую погоду (условие: ясно/облачно/дождь/снег/
  ветер + диапазон температуры), если данные доступны — сейчас погода берётся
  для Москвы (город пользователя пока не настраивается); без погодных данных
  образы всё равно собираются, просто без этого учёта.
- Реакция на образ (принял/отклонил/отметил, что понравилось) со временем
  подстраивает подборки под то, что пользователь реально носит.
- Отдельно фиксируется, сколько раз вещь реально надевалась (журнал носки).
- Начать пользоваться сервисом можно бесплатно (кнопка «Начать бесплатно» на
  /ru/wardrobe) — но это НЕ безлимитный тариф на все AI-функции разом, точных
  условий тарификации в эти факты не входит, поэтому про «безлимит»/«без
  ограничений» не пиши.
EOT;

    public function __construct(
        private readonly LlmService $llm,
        private readonly ContentValidator $validator,
        private readonly CompetitorArticleRepository $articleRepo,
        private readonly NearDuplicateDetector $nearDup,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Сгенерировать только один топик (id)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Перегенерировать, даже если файл уже есть')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Папка для .md', self::OUT_DIR)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('only');
        $force = (bool) $input->getOption('force');
        $outDir = rtrim((string) $input->getOption('out'), '/');

        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            $io->error("Не удалось создать {$outDir}.");
            return Command::FAILURE;
        }

        $topics = $this->topics();
        if ($only !== null) {
            $topics = array_filter($topics, static fn(array $t) => $t['id'] === $only);
            if ($topics === []) {
                $io->error("Топик «{$only}» не найден. Доступны: " . implode(', ', array_column($this->topics(), 'id')));
                return Command::FAILURE;
            }
        }

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($topics as $topic) {
            $path = sprintf('%s/%s', $outDir, $topic['file']);
            $io->section($topic['h1']);

            if (is_file($path) && !$force) {
                $io->text("  уже есть, пропущено (--force для перегенерации): {$path}");
                $skipped++;
                continue;
            }

            $competitorTexts = $this->loadCompetitorTexts($topic['competitorUrls']);
            if (count($competitorTexts) !== count($topic['competitorUrls'])) {
                $io->error(sprintf(
                    '  gap-context неполный: найдено %d из %d competitorUrls — anti-duplicate не может быть гарантирован, тема пропущена.',
                    count($competitorTexts),
                    count($topic['competitorUrls']),
                ));
                $failed++;
                continue;
            }
            [$systemPrompt, $basePrompt] = $this->buildPrompt($topic);

            $fixHint = null;
            $body = null;
            $issues = ['пусто'];
            for ($att = 0; $att < self::MAX_ATTEMPTS; $att++) {
                $prompt = $fixHint === null ? $basePrompt
                    : $basePrompt . "\n\nВАЖНО: предыдущая версия НЕ прошла проверку. Исправь ИМЕННО это и не повторяй (остальное сохрани): {$fixHint}";

                try {
                    $raw = $this->llm->generate($prompt, $systemPrompt, local: true, think: false, timeout: 600, temperature: self::TEMPS[$att] ?? 0.5);
                } catch (\Throwable $e) {
                    $issues = ['LLM ошибка: ' . mb_substr($e->getMessage(), 0, 80)];
                    $io->text(sprintf('  попытка %d/%d → LLM недоступна, пауза 15с…', $att + 1, self::MAX_ATTEMPTS));
                    sleep(15);
                    continue;
                }

                $clean = $this->cleanFormatting(trim($raw));
                if ($clean === '') {
                    $issues = ['LLM вернула пусто'];
                    continue;
                }

                $issues = $this->qualityGate($clean);
                $issues = array_merge($issues, $this->nearDuplicateIssues($clean, $competitorTexts));
                $body = $clean;
                if ($issues === []) {
                    break;
                }
                $fixHint = implode('; ', $issues);
                $io->text(sprintf('  попытка %d/%d → gate: %s', $att + 1, self::MAX_ATTEMPTS, $fixHint));
            }

            if ($body === null || $issues !== []) {
                $io->warning('  Отбраковано после ' . self::MAX_ATTEMPTS . ' попыток: ' . implode('; ', $issues));
                $failed++;
                continue;
            }

            $words = (int) preg_match_all('/\p{L}+/u', $body);
            $document = $this->renderDocument($topic['metaTitle'], $topic['h1'], $body);
            file_put_contents($path, $document);
            $io->success(sprintf('  Сохранено (%d слов): %s', $words, $path));
            $io->text('  ' . $this->nearDuplicateReport($body, $competitorTexts));
            $ok++;
        }

        $io->newLine();
        $io->table(['Результат', 'Кол-во'], [
            ['Готово', $ok],
            ['Пропущено (уже есть)', $skipped],
            ['Отбраковано', $failed],
        ]);

        return $failed > 0 && $ok === 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<int,array{id:string,h1:string,metaTitle:string,file:string,brief:string,gapTopics:string,competitorUrls:string[]}>
     */
    private function topics(): array
    {
        return [
            [
                'id' => 'razbor-garderoba-pered-ocifrovkoj',
                'h1' => 'Разбор гардероба перед оцифровкой: как расхламить шкаф',
                'metaTitle' => 'Разбор гардероба: как расхламить шкаф перед оцифровкой',
                'file' => 'razbor-garderoba-pered-ocifrovkoj-site.md',
                'brief' => <<<'EOT'
Тема — практический разбор гардероба ДО оцифровки: как решить, что оставить,
что отдать/выбросить, как рассортировать вещи по сезону и частоте носки.
Общий метод разбора шкафа (не выдумывай статистику по «сколько вещей мы
обычно не носим»), а затем — как перенести уже отобранные вещи в цифровой
гардероб WEARBASE (используй факты о статусах вещей, включая «отдана», как
логичный итог разбора). CTA на /ru/wardrobe.
EOT,
                'gapTopics' => "- преимущества цифровой систематизации вещей\n- алгоритм каталогизации физических объектов\n- методика маркировки предметов при расхламлении",
                'competitorUrls' => ['https://www.fits-app.com/ru/posts/how-to-clean-out-your-closet-efficiently-with-the-closet-app-fits'],
            ],
            [
                'id' => 'semejnyj-garderob-v-odnom-prilozhenii',
                'h1' => 'Семейный гардероб: как вести вещи всей семьи в одном приложении',
                'metaTitle' => 'Семейный гардероб онлайн — вещи всей семьи в одном месте',
                'file' => 'semejnyj-garderob-v-odnom-prilozhenii-site.md',
                'brief' => <<<'EOT'
Тема — ведение гардероба не одного человека, а всей семьи: детские вещи,
которые донашивают за старшими, вещи, которые передают между членами семьи.
Используй СТРОГО факт WEARBASE о статусах «на вырост»/«мала»/«отдана» как
части семейного сценария передачи вещей с историей. Если тема по своей сути
требует функции, которой нет в блоке «ФАКТЫ О WEARBASE» (например, отдельные
аккаунты членов семьи с общим доступом, синхронизация между их устройствами)
— НЕ упоминай её применительно к WEARBASE вообще, ни как то, что есть, ни как
то, что отсутствует; обсуждай это только как общую практику/подход, не
привязывая к сервису. CTA на /ru/wardrobe.
EOT,
                'gapTopics' => "- управление семейными профилями (пиши только через факт WEARBASE о статусах вещей, не как отдельную функцию доступа)",
                'competitorUrls' => ['https://getwardrobe.com/ru', 'https://getwardrobe.com/ru/compare', 'https://getwardrobe.com/ru/compare/acloset'],
            ],
            [
                'id' => 'garderob-v-poezdke-kak-sobrat-chemodan',
                'h1' => 'Гардероб в поездке: как собрать чемодан по своим вещам',
                'metaTitle' => 'Как собрать чемодан в поездку по цифровому гардеробу',
                'file' => 'garderob-v-poezdke-kak-sobrat-chemodan-site.md',
                'brief' => <<<'EOT'
Тема — как использовать уже оцифрованный гардероб при сборах в поездку:
посмотреть в приложении, что есть из подходящей одежды по сезону/поводу, не
доставая вещи из шкафа заранее, свериться со списком на телефоне в магазине,
если нужно докупить недостающее. Если тема по своей сути требует функции,
которой нет в блоке «ФАКТЫ О WEARBASE» (например, отдельный список для сборов
чемодана, прогноз погоды) — НЕ упоминай её применительно к WEARBASE вообще, ни
как то, что есть, ни как то, что отсутствует; речь только о том, что фото уже
оцифрованного гардероба под рукой само по себе помогает при сборах. CTA на
/ru/wardrobe.
EOT,
                'gapTopics' => "- подготовка вещей к поездке по фотографиям гардероба (общий метод, без привязки к конкретной функции WEARBASE)",
                'competitorUrls' => ['https://getwardrobe.com/ru', 'https://getwardrobe.com/ru/compare', 'https://getwardrobe.com/ru/compare/acloset'],
            ],
            [
                'id' => 'kak-priuchit-ai-stilista-k-svoemu-vkusu',
                'h1' => 'Как приучить AI-стилиста к своему вкусу',
                'metaTitle' => 'Как приучить AI-стилиста WEARBASE к своему вкусу',
                'file' => 'kak-priuchit-ai-stilista-k-svoemu-vkusu-site.md',
                'brief' => <<<'EOT'
Тема — как обратная связь пользователя обучает AI-стилист: почему стоит
отмечать понравившиеся образы и отклонять неподходящие, как это меняет
дальнейшие подборки. Строго на факте WEARBASE: реакция (принял/отклонил/
отметил, что понравилось) со временем подстраивает подборки под то, что
человек реально носит; журнал носки фиксирует, сколько раз вещь надевалась.
Не выдумывай технические детали алгоритма (нейросеть, ML-модель и т.п.) —
только пользовательский эффект. CTA на /ru/wardrobe.
EOT,
                'gapTopics' => "- обзор функциональных возможностей AI-стилиста\n- интеграция цифрового гардероба и рекомендаций",
                'competitorUrls' => ['https://www.fits-app.com/ru/posts/how-to-rate-outfit-ai', 'https://www.fits-app.com/ru/posts/best-ai-stylists-in-2025-top-5-free-paid-services'],
            ],
            [
                'id' => 'zhurnal-noski-skolko-raz-vy-nosite-veshchi',
                'h1' => 'Журнал носки: зачем считать, сколько раз вы носите вещи',
                'metaTitle' => 'Журнал носки — зачем считать, сколько раз вы носите вещи',
                'file' => 'zhurnal-noski-skolko-raz-vy-nosite-veshchi-site.md',
                'brief' => <<<'EOT'
Тема — зачем вообще считать, сколько раз надета вещь: как это помогает увидеть
«мёртвый груз» в шкафу и вещи, которые реально работают. Строго на факте
WEARBASE: отдельно фиксируется, сколько раз вещь реально надевалась (журнал
носки). Расчёт «стоимости одного использования» (цена вещи ÷ число носок)
можно упомянуть ТОЛЬКО как общий метод, который пользователь считает сам в уме
или на бумаге, — WEARBASE в фактах НЕ считает стоимость в деньгах, поэтому не
приписывай сервису такую функцию (ни как то, что есть, ни как то, что
отсутствует). CTA на /ru/wardrobe.
EOT,
                'gapTopics' => "- аналитика использования вещей (пиши только через факт WEARBASE о журнале носки, стоимость в деньгах — только как общий метод пользователя, не функция сервиса)",
                'competitorUrls' => ['https://getwardrobe.com/ru', 'https://getwardrobe.com/ru/compare', 'https://getwardrobe.com/ru/compare/acloset'],
            ],
        ];
    }

    /** @param array{h1:string,brief:string,gapTopics:string} $topic @return array{0:string,1:string} */
    private function buildPrompt(array $topic): array
    {
        $systemPrompt = 'Ты — редактор блога WEARBASE, пишешь статьи на тему гардероба на русском языке. '
            . 'Никогда не выдумывай статистику, проценты, цены, даты и результаты исследований, которых нет '
            . 'в этом промпте. Запрещены слова с корнями: уникальн-, инноваци-, передов-, лидир-, новатор-, '
            . 'беспрецедент-, несравн- — подбирай обычные синонимы. Все конкретные утверждения про сервис '
            . 'WEARBASE (что он умеет, что бесплатно, как работает AI) — СТРОГО из блока «ФАКТЫ О WEARBASE». '
            . 'Если тема просит раскрыть что-то, чего в этом блоке нет, — обсуждай это только как общий '
            . 'метод/чужую практику, вообще не привязывая к WEARBASE (ни как то, что сервис умеет, ни как '
            . 'то, чего в нём нет). Отвечаешь только markdown-текстом статьи, без обёртки ```, без заголовка '
            . 'первого уровня (# …).';

        $prompt = <<<EOT
Напиши статью «{$topic['h1']}» для блога WEARBASE.

{$topic['brief']}

ФАКТЫ О WEARBASE (единственный источник правды о сервисе; не выдумывай сверх этого):
{$this->wearbaseFacts()}

ТЕМЫ, КОТОРЫЕ РАСКРЫВАЮТ КОНКУРЕНТЫ ПО ЭТОЙ ТЕМЕ (раскрой их тоже, где уместно, — но
ТОЛЬКО как общий метод/знание или строго через факты о WEARBASE выше, НЕ приписывай
WEARBASE функции конкурентов):
{$topic['gapTopics']}

Требования:
- ОБЪЁМ: 800–1300 слов.
- НАЧНИ с блока «## Коротко»: 40–60 слов, прямой самодостаточный ответ по теме статьи, понятный БЕЗ чтения остального текста — без слов «ниже/далее/в статье/см./выше/читайте» и подобных отсылок (это лид для сниппета/AI Overview).
- Дальше — обычные разделы «## …» по смыслу темы (сколько нужно для раскрытия).
- В конце — раздел «## С чего начать» (или другой заголовок по смыслу) с ОДНОЙ markdown-ссылкой на https://wearbase.ru/ru/wardrobe вида [текст]({$this->ctaUrl()}) — ссылка ОБЯЗАНА быть абсолютной (начинаться с https://), НЕ относительным путём.
- НЕ добавляй заголовок первого уровня (# …) — начни сразу с «## Коротко».
- Никаких выдуманных цифр, статистики, цен и дат, которых нет в фактах выше.
- Для перечислений используй ТОЛЬКО маркированные списки (пункт начинается с «- »), НЕ нумерованные (1. 2. 3.) — шаблон блога рендерит нумерованные списки одним сплошным абзацем без разрывов строк.

Формат: только markdown-тело статьи.
EOT;

        return [$systemPrompt, $prompt];
    }

    private function wearbaseFacts(): string
    {
        return self::WEARBASE_FACTS;
    }

    private function ctaUrl(): string
    {
        return self::CTA_URL;
    }

    /**
     * @param string[] $urls
     * @return array<string,array{domain:string,content:string}> url => data (только найденные с непустым content)
     */
    private function loadCompetitorTexts(array $urls): array
    {
        $texts = [];
        foreach ($urls as $url) {
            $a = $this->articleRepo->findByUrl($url);
            if ($a !== null && $a->getContent() !== null && trim($a->getContent()) !== '') {
                $texts[$url] = ['domain' => $a->getDomain(), 'content' => $a->getContent()];
            }
        }

        return $texts;
    }

    /**
     * Anti-duplicate (docs/seo_competitor_content.md): сгенерированное тело не должно
     * совпадать со статьями конкурентов, у которых позаимствованы темы этой статьи.
     *
     * @param array<string,array{domain:string,content:string}> $competitorTexts
     * @return string[]
     */
    private function nearDuplicateIssues(string $body, array $competitorTexts): array
    {
        $issues = [];
        $bodyShingles = $this->nearDup->shingles($body);
        foreach ($competitorTexts as $url => $data) {
            $sim = $this->nearDup->jaccard($bodyShingles, $this->nearDup->shingles($data['content']));
            if ($sim >= NearDuplicateDetector::DROP_THRESHOLD) {
                $issues[] = sprintf(
                    'near-duplicate с конкурентом %s (jaccard=%.2f ≥ %.2f) — перепиши своими словами',
                    $data['domain'],
                    $sim,
                    NearDuplicateDetector::DROP_THRESHOLD,
                );
            }
        }

        return $issues;
    }

    /**
     * Отчёт по max jaccard против gap-context конкурентов этой статьи — для консоли,
     * печатается всегда (не только при провале), координатор должен видеть цифры.
     *
     * @param array<string,array{domain:string,content:string}> $competitorTexts
     */
    private function nearDuplicateReport(string $body, array $competitorTexts): string
    {
        if ($competitorTexts === []) {
            return 'anti-duplicate: нет competitorTexts для сравнения';
        }

        $bodyShingles = $this->nearDup->shingles($body);
        $best = 0.0;
        $bestDomain = '';
        foreach ($competitorTexts as $data) {
            $sim = $this->nearDup->jaccard($bodyShingles, $this->nearDup->shingles($data['content']));
            if ($sim > $best) {
                $best = $sim;
                $bestDomain = $data['domain'];
            }
        }

        return sprintf('anti-duplicate: max jaccard=%.3f (%s), порог=%.2f', $best, $bestDomain, NearDuplicateDetector::DROP_THRESHOLD);
    }

    private function cleanFormatting(string $body): string
    {
        $body = (string) preg_replace('/^```\w*\s*|\s*```$/u', '', $body);
        $body = (string) preg_replace('/^#\s+.+?\n+/u', '', trim($body));

        return trim($body);
    }

    /** @return string[] пусто = гейт пройден */
    private function qualityGate(string $body): array
    {
        $issues = [];

        if ($this->validator->isRefusal($body)) {
            $issues[] = 'модель вернула отказ';
        }

        if (str_contains($body, '```')) {
            $issues[] = 'в теле осталась markdown-обёртка ```';
        }

        if (preg_match('/^#\s+/mu', $body)) {
            $issues[] = 'в теле остался заголовок первого уровня (# ...) — H1 добавляет команда';
        }

        $words = (int) preg_match_all('/\p{L}+/u', $body);
        if ($words < self::MIN_WORDS) {
            $issues[] = sprintf('мало слов: %d < %d', $words, self::MIN_WORDS);
        }

        foreach (self::FORBIDDEN_ROOTS as $root) {
            if (preg_match('/' . $root . '\p{L}*/iu', $body)) {
                $issues[] = "запрещённый корень: «{$root}»";
                break;
            }
        }

        if (!str_contains($body, '](' . self::CTA_URL . ')')) {
            $issues[] = 'нет CTA-ссылки на ' . self::CTA_URL . ' в формате markdown';
        }

        if (preg_match('/\]\((?!https:\/\/|#)[^)]*\)/u', $body, $m)) {
            $issues[] = "относительная ссылка (станет мёртвым текстом): «{$m[0]}»";
        }

        if (preg_match('/^\d+\.\s/mu', $body)) {
            $issues[] = 'нумерованный список (1. 2. 3.) — парсер склеит его в один абзац; нужны маркированные списки (- )';
        }

        if (preg_match('/(?<![\p{L}])(?=\p{L}*[а-яёА-ЯЁ])(?=\p{L}*[a-zA-Z])\p{L}+/u', $body, $gm)) {
            $issues[] = "слово-глюк со смешанным алфавитом: «{$gm[0]}»";
        }

        $issues = array_merge($issues, $this->verifyLead($body));

        return $issues;
    }

    /** @return string[] */
    private function verifyLead(string $body): array
    {
        if (!preg_match('/^##\s+(?:Корот|Крат)/mui', $body)) {
            return ['нет лид-блока «## Коротко» (answer-nugget для AI Overview)'];
        }
        if (preg_match('/^##\s+(?:Корот|Крат)\p{L}*\s*(.+?)(?=\n##\s|\z)/smui', $body, $m)) {
            $lead = mb_strtolower($m[1], 'UTF-8');
            if (preg_match('/(?<![\p{L}])(ниже|далее|выше|смотрите|см\.|читайте|в этой статье|в статье|в обзоре|в этом гиде|в гиде|в таблице)(?![\p{L}])/u', $lead, $mm)) {
                return ["лид-блок не самодостаточен: отсылка «{$mm[1]}» (невытаскиваемо в сниппет)"];
            }
        }

        return [];
    }

    private function renderDocument(string $metaTitle, string $h1, string $body): string
    {
        return <<<MD
        <!-- meta-title: {$metaTitle} -->

        # {$h1}

        {$body}
        MD;
    }
}
