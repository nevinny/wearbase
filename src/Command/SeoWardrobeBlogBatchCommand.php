<?php

namespace App\Command;

use App\Service\ContentValidator;
use App\Service\LlmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Разовый батч из 7 статей блога по теме «гардероб/капсульный гардероб»
 * (docs/wardrobe-content-plan_2026-09-23.md, Фаза 1-2). В отличие от
 * SeoGuideCommand/GenerateListicleCommand — тема НЕ про бренды каталога, поэтому
 * без брендовой механики (нет $brands, нет RAG, нет ItemList JSON-LD): прямой
 * вызов LlmService::generate() с промптом под тему. Топики и все факты про
 * WEARBASE зашиты жёстко — команда одноразовая, не универсальный фреймворк.
 *
 *   php bin/console app:seo:wardrobe-blog-batch
 *   php bin/console app:seo:wardrobe-blog-batch --only=capsule-wardrobe-2026
 *   php bin/console app:seo:wardrobe-blog-batch --force
 */
#[AsCommand(
    name: 'app:seo:wardrobe-blog-batch',
    description: 'Батч из 7 статей блога про (капсульный) гардероб — grounded в факты WEARBASE, без брендовой механики',
)]
class SeoWardrobeBlogBatchCommand extends Command
{
    private const OUT_DIR = 'var/seo/blog';
    private const MIN_WORDS = 800;
    private const MAX_ATTEMPTS = 3;
    private const CTA_URL = 'https://wearbase.ru/ru/wardrobe';
    private const TEMPS = [0.7, 0.6, 0.5];

    /** Корни, запрещённые промптом — ловим протечки (getAiPhrases() хранит словоформы, не корни). */
    private const FORBIDDEN_ROOTS = ['уникальн', 'инноваци', 'передов', 'лидир', 'новатор', 'беспрецедент', 'несравн'];

    /**
     * Единственный источник правды о том, что умеет WEARBASE-гардероб. Взято из
     * уже отредактированной статьи №1 (var/seo/blog/wardrobe-obrazy-iz-svoih-veshchej-site.md,
     * ветка content/wardrobe-landing-seo-rewrite) и docs/wardrobe_roadmap.md — не
     * переоткрываем факты заново, копируем как есть.
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
- Реакция на образ (принял/отклонил/отметил, что понравилось) со временем
  подстраивает подборки под то, что пользователь реально носит.
- Отдельно фиксируется, сколько раз вещь реально надевалась (журнал носки).
- Начать пользоваться сервисом можно бесплатно (кнопка «Начать бесплатно» на
  /ru/wardrobe) — но это НЕ безлимитный тариф на все AI-функции разом, точных
  условий тарификации в эти факты не входит, поэтому про «безлимит»/«без
  ограничений» не пиши.
EOT;

    /**
     * Факты о выездном concierge-сервисе (/ru/wardrobe/concierge,
     * templates/tailwind/landing/wardrobe_concierge.html.twig) — используются
     * ТОЛЬКО в топике 1, как альтернатива self-serve. На странице НЕТ цены —
     * не выдумывай её.
     */
    private const CONCIERGE_FACTS = <<<'EOT'
- Выездная команда приезжает к клиенту (сейчас услуга доступна в Москве) и сама
  бережно фотографирует и оцифровывает вещи — без самостоятельной съёмки.
- Результат — приватный цифровой архив вещей для владельца и его стилиста.
- Обсуждение и запись — через Telegram, конкретная цена на странице услуги не
  указана — не выдумывай её и не называй число.
EOT;

    /**
     * Факты о приложениях-конкурентах для топика 2 (по переписке координатора,
     * 2026-09-23). НЕ проверялось лично — только то, что тут написано; для
     * приложений без верифицированных фактов ниже — общая нейтральная фраза
     * про категорию, без конкретики.
     */
    private const COMPETITOR_FACTS = <<<'EOT'
- Fits — щедрый бесплатный тариф: безлимит вещей, безлимит образов, удаление
  фона у фото вещи бесплатно; платно — только безлимит AI-рекомендаций образов.
  НЕ пиши, что платный вход отталкивает пользователей, — это не так, вход
  бесплатный.
- GetWardrobe (getwardrobe.com) — бесплатный тариф до 100 вещей в гардеробе.
- Acloset — полностью бесплатное приложение (об этом писал Т—Ж).
- Tidy, Outfitly (outfitlyapp.com), N2B (n2b-style.com), Smart Closet —
  проверенных фактов по условиям и тарифам нет: упомяни только название и одно
  нейтральное предложение о категории приложения («приложение для учёта вещей
  гардероба» и т.п.), БЕЗ цифр, цен, платформ, рейтингов и конкретных условий —
  их не выдумывай.
EOT;

    public function __construct(
        private readonly LlmService $llm,
        private readonly ContentValidator $validator,
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
     * @return array<int,array{id:string,h1:string,metaTitle:string,file:string,brief:string,extraFacts:?string}>
     */
    private function topics(): array
    {
        return [
            [
                'id' => 'digitizing-wardrobe-start',
                'h1' => 'Оцифровка гардероба: с чего начать',
                'metaTitle' => 'Оцифровка гардероба: с чего начать — гайд 2026',
                'file' => 'wardrobe-ocifrovka-s-chego-nachat-site.md',
                'brief' => <<<'EOT'
Тема — практический план для тех, кто решил оцифровать свой гардероб
самостоятельно (self-serve): с чего начать, как фотографировать вещи, что
делать с AI-черновиком карточки, зачем нужны статусы вещей. Основной CTA
статьи — self-serve-сервис WEARBASE (/ru/wardrobe). Отдельно, ОДНИМ-ДВУМЯ
предложениями во вступлении или в отдельном небольшом разделе, упомяни, что
для тех, кто не хочет фотографировать вещи сам, есть альтернатива — выездная
услуга-концьерж (используй факты о ней ниже). НЕ давай на концьерж отдельную
markdown-ссылку и не делай его вторым CTA — только текстовое упоминание как
альтернативы, чтобы не путать два разных интента на одной странице.
EOT,
                'extraFacts' => "ФАКТЫ О ВЫЕЗДНОЙ УСЛУГЕ-КОНЦЬЕРЖ (только для одного упоминания-альтернативы, БЕЗ ссылки на неё):\n" . self::CONCIERGE_FACTS,
            ],
            [
                'id' => 'wardrobe-apps-comparison',
                'h1' => 'Топ приложений для гардероба: честное сравнение',
                'metaTitle' => 'Топ приложений для гардероба: честное сравнение 2026',
                'file' => 'wardrobe-top-prilozhenij-sravnenie-site.md',
                'brief' => <<<'EOT'
Тема — обзорное и честное сравнение приложений для ведения личного гардероба:
Fits, Tidy, GetWardrobe, Acloset, Outfitly, N2B, Smart Closet и WEARBASE. Пиши
НЕЙТРАЛЬНО и осторожно: ты НЕ проверял реальные условия каждого приложения
лично — используй строго факты из блока «ФАКТЫ О ПРИЛОЖЕНИЯХ-КОНКУРЕНТАХ»
ниже, для остальных — только нейтральная общая фраза без конкретики (см.
правила в этом блоке). WEARBASE включи в список честно как один из вариантов
(self-serve, встроен в каталог брендов, есть бесплатный старт) — БЕЗ
агрессивного продвижения и превосходных степеней, наравне с остальными.
Если не уверен в каком-то факте — лучше не пиши его вообще, короче но точнее.
EOT,
                'extraFacts' => "ФАКТЫ О ПРИЛОЖЕНИЯХ-КОНКУРЕНТАХ (единственный источник конкретики о них; ты сам их не проверял):\n" . self::COMPETITOR_FACTS,
            ],
            [
                'id' => 'capsule-wardrobe-woman',
                'h1' => 'Капсульный гардероб для женщины',
                'metaTitle' => 'Капсульный гардероб для женщины — как собрать',
                'file' => 'kapsulnyj-garderob-dlya-zhenshchiny-site.md',
                'brief' => <<<'EOT'
Тема — как женщине собрать капсульный гардероб: принципы отбора базовых вещей,
сочетаемость по цвету и силуэту, типичное количество вещей в капсуле, для
каких ситуаций формировать капсулы (офис/повседневность/выход). Это общее
фэшн-знание, НЕ требует опоры на факты конкретных брендов. Если приводишь
числа (например, сколько вещей входит в капсулу) — формулируй как
распространённый подход («часто советуют ограничиться...», «многие стилисты
рекомендуют...»), а НЕ как результат исследования, статистику или ссылку на
конкретный источник.
EOT,
                'extraFacts' => null,
            ],
            [
                'id' => 'capsule-wardrobe-autumn',
                'h1' => 'Капсульный гардероб на осень',
                'metaTitle' => 'Капсульный гардероб на осень 2026',
                'file' => 'kapsulnyj-garderob-na-osen-site.md',
                'brief' => <<<'EOT'
Тема — как собрать капсульный гардероб именно на осенний сезон: базовые
сезонные вещи (верхняя одежда, слои, обувь), сочетание тёплого и лёгкого,
переход от лета к зиме. Общее фэшн-знание, без опоры на факты конкретных
брендов. Числа (количество вещей и т.п.) подавай как распространённый подход,
а не статистику или ссылку на источник — конкретных исследований не выдумывай.
EOT,
                'extraFacts' => null,
            ],
            [
                'id' => 'capsule-wardrobe-2026',
                'h1' => 'Капсульный гардероб 2026',
                'metaTitle' => 'Капсульный гардероб 2026 — как собрать',
                'file' => 'kapsulnyj-garderob-2026-site.md',
                'brief' => <<<'EOT'
Тема — статья-МЕТОД «как обновить/собрать капсульный гардероб именно сейчас,
в этом году»: принципы базы + акцентных вещей, ревизия старого гардероба перед
сборкой капсулы, практичный подход без гонки за модой. Это НЕ репортаж с
показов мод и НЕ список «трендов сезона» от изданий — НЕ приписывай советы
конкретным дизайнерам, показам, журналам или исследованиям («по данным Vogue»
и подобное строго запрещено). Год 2026 упоминай только как контекст «сейчас»,
без конкретных модных тенденций, которые ты не можешь подтвердить.
EOT,
                'extraFacts' => null,
            ],
            [
                'id' => 'capsule-wardrobe-men',
                'h1' => 'Капсульный мужской гардероб',
                'metaTitle' => 'Капсульный мужской гардероб — как собрать',
                'file' => 'kapsulnyj-muzhskoj-garderob-site.md',
                'brief' => <<<'EOT'
Тема — как мужчине собрать капсульный гардероб: базовые вещи (брюки, рубашки/
футболки, верхняя одежда, обувь), принцип сочетаемости по цвету, минимум вещей
для максимума комбинаций. Общее фэшн-знание, без опоры на факты конкретных
брендов. Числа подавай как распространённый подход, а не статистику.
EOT,
                'extraFacts' => null,
            ],
            [
                'id' => 'capsule-wardrobe-online',
                'h1' => 'Как составить капсульный гардероб онлайн',
                'metaTitle' => 'Как составить капсульный гардероб онлайн',
                'file' => 'kak-sostavit-kapsulnyj-garderob-onlajn-site.md',
                'brief' => <<<'EOT'
Тема — мост между капсульным гардеробом и инструментом: как использовать
приложение для цифрового гардероба, чтобы собрать капсулу онлайн, не раскладывая
вещи на столе. Опиши общий метод (сфотографировать вещи, посмотреть, что уже
есть, выделить в списке базовые вещи капсулы) и, где уместно, обопрись на
факты о том, как именно это устроено в WEARBASE (блок «ФАКТЫ О WEARBASE» ниже:
AI-черновик карточки, подтверждение человеком, статусы, AI-стилист до трёх
образов из вещей «носится»). CTA статьи — на /ru/wardrobe, это основной
инструмент темы.
EOT,
                'extraFacts' => null,
            ],
        ];
    }

    /** @param array{h1:string,brief:string,extraFacts:?string} $topic @return array{0:string,1:string} */
    private function buildPrompt(array $topic): array
    {
        $systemPrompt = 'Ты — редактор блога WEARBASE, пишешь статьи на тему гардероба и капсульного '
            . 'гардероба на русском языке. Никогда не выдумывай статистику, проценты, цены, даты и '
            . 'результаты исследований, которых нет в этом промпте. Запрещены слова с корнями: '
            . 'уникальн-, инноваци-, передов-, лидир-, новатор-, беспрецедент-, несравн- — подбирай '
            . 'обычные синонимы. Все конкретные утверждения про сервис WEARBASE (что он умеет, что '
            . 'бесплатно, как работает AI) — СТРОГО из блока «ФАКТЫ О WEARBASE»; ничего сверх них про '
            . 'сервис не придумывай. Отвечаешь только markdown-текстом статьи, без обёртки ```, без '
            . 'заголовка первого уровня (# …).';

        $extra = $topic['extraFacts'] !== null ? "\n\n{$topic['extraFacts']}" : '';

        $prompt = <<<EOT
Напиши статью «{$topic['h1']}» для блога WEARBASE.

{$topic['brief']}

ФАКТЫ О WEARBASE (единственный источник правды о сервисе; не выдумывай сверх этого):
{$this->wearbaseFacts()}{$extra}

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
     * Снимает обёртку ``` (если модель всё же добавила) и посторонний H1 в начале
     * ответа (H1 добавляет команда, не модель) — тот же приём, что
     * LlmService::generateStyleHub.
     */
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

        // Парсер (ArticleMarkdownParser) рендерит только маркированные списки («- »);
        // нумерованные (1. 2. 3.) склеиваются в один сплошной абзац без переносов строк.
        if (preg_match('/^\d+\.\s/mu', $body)) {
            $issues[] = 'нумерованный список (1. 2. 3.) — парсер склеит его в один абзац; нужны маркированные списки (- )';
        }

        // Глюк модели (иной, чем «му»/«ло»/«лан» из ContentValidator): целое слово с
        // ЛАТИНСКИМИ буквами внутри русского текста (напр. «остаkiego» вместо
        // «остального») — брак генерации, не опечатка. Латиница легитимна только в
        // названиях брендов из COMPETITOR_FACTS/URL — там слова целиком латиницей,
        // не «пол-кириллица-пол-латиница» внутри одного токена.
        if (preg_match('/(?<![\p{L}])(?=\p{L}*[а-яёА-ЯЁ])(?=\p{L}*[a-zA-Z])\p{L}+/u', $body, $gm)) {
            $issues[] = "слово-глюк со смешанным алфавитом: «{$gm[0]}»";
        }

        $issues = array_merge($issues, $this->verifyLead($body));

        return $issues;
    }

    /**
     * verify-standalone (тот же паттерн, что GenerateListicleCommand/ReplaceListicleCommand/
     * SeoGuideCommand): лид-блок «## Коротко» обязан быть и быть самодостаточным.
     * @return string[]
     */
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
