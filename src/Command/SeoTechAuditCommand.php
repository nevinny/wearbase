<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\AdminNotifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Тех-аудит по чек-листу: обходит живой сайт, проверяет каждую страницу и ведёт
 * findings в seo_tech_finding, чтобы в отчёте была ДЕЛЬТА («появилось / исправлено»),
 * а не список из тысячи строк каждую неделю (docs/seo_guide_vasin_gap.md, пробелы 2 и 3).
 *
 * Почему обход, а не проверка по БД: половина правил (два H1, CTA в заголовке, FAQ без
 * JSON-LD, битая внутренняя ссылка, сирота) — свойства ОТРЕНДЕРЕННОЙ страницы. По полям
 * сущностей их не увидеть: их создаёт шаблон, а не данные.
 *
 * Обход: BFS от главной, только ru-локаль (не-ru у нас noindex, см. base.html.twig) и
 * только свой хост, в ДВЕ ФАЗЫ.
 *  1. Хабы (всё, кроме карточек брендов: главная, блог и пагинация, каталог, гео- и
 *     стилевые хабы, авторы) — до исчерпания очереди, потолок --hub-limit. Именно этот
 *     граф и порождает внутренние ссылки, поэтому только его полнота делает вывод о
 *     сиротах достоверным.
 *  2. Карточки брендов — остатком общего --limit, ради их собственных проверок.
 *     Хабы, найденные ТОЛЬКО с карточек, в фазе 2 не крауляются: 3600+ карточек ссылаются
 *     на гео/стилевые хабы, очередь пополнялась бы бесконечно и условие «очередь
 *     исчерпана» стало бы недостижимым. Их число выводится как оговорка.
 *
 * Сироты считаются ТОЛЬКО если фаза 1 исчерпала очередь. Иначе проверка не выдаётся:
 * недообойдённый граф даёт ложных «сирот». Карточки брендов из кандидатов исключены
 * намеренно — их входящие живут в каталоге и подборках, полный обход которых в бюджет
 * не влезает.
 *
 *   php bin/console app:seo:tech-audit --stdout-only --limit=60
 *   php bin/console app:seo:tech-audit --base=http://127.0.0.1:8001   # локально
 *   php bin/console app:seo:tech-audit --notify --no-debug            # крон, сб
 */
#[AsCommand(
    name: 'app:seo:tech-audit',
    description: 'SEO тех-аудит: обход сайта по чек-листу (мета/H1/alt/CTA/FAQ/canonical/битые ссылки/сироты) + дельта к прошлому прогону',
)]
class SeoTechAuditCommand extends Command
{
    /** severity: high — рвёт индексацию или иерархию; medium — теряем сниппет/вес; low — гигиена. */
    private const RULES = [
        'title_missing'        => ['label' => 'Нет <title>',                       'severity' => 'high'],
        'title_duplicate'      => ['label' => 'Одинаковый <title> на разных URL',   'severity' => 'high'],
        'h1_missing'           => ['label' => 'Нет H1',                            'severity' => 'high'],
        'h1_multiple'          => ['label' => 'Больше одного H1',                  'severity' => 'high'],
        'canonical_missing'    => ['label' => 'Нет canonical',                     'severity' => 'high'],
        'internal_link_broken' => ['label' => 'Битая внутренняя ссылка',           'severity' => 'high'],
        'orphan_page'          => ['label' => 'Страница-сирота (нет входящих)',    'severity' => 'high'],
        'description_missing'  => ['label' => 'Нет description',                   'severity' => 'medium'],
        'description_short'    => ['label' => 'Слишком короткий description',      'severity' => 'medium'],
        'cta_in_heading'       => ['label' => 'CTA оформлен заголовком H1–H4',     'severity' => 'medium'],
        'faq_without_schema'   => ['label' => 'Блок FAQ без JSON-LD FAQPage',      'severity' => 'medium'],
        'url_bad_case'         => ['label' => 'Заглавные/кириллица в URL',          'severity' => 'medium'],
        'img_no_alt'           => ['label' => 'Картинки без alt',                  'severity' => 'low'],
    ];

    /** Гайдовый минимум: «допиши description до 200–250 символов». Ниже этого — считаем недописанным. */
    private const DESCRIPTION_MIN = 70;

    /** Заголовок-призыв рвёт иерархию H1→H2→H3: такие блоки должны быть <div>, а не H2/H3. */
    private const CTA_PATTERN = '/^(оставить заявку|подобрать|забрать карточку|получить|заказать|купить сейчас|связаться|написать нам|зарегистрироваться|войти|подписаться|добавить бренд)/iu';

    /** Маркеры FAQ-блока в тексте страницы — если есть, ждём JSON-LD FAQPage. */
    private const FAQ_PATTERN = '/частые вопросы|часто задаваемые вопросы|вопросы и ответы/iu';

    /** Не крауленные секции: админка, редиректор кликов, служебное. */
    private const SKIP_PATH_PATTERN = '#^/(admin|_profiler|_wdt|go|e/|api/|login|logout|register)#';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
        private readonly string $siteBaseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Базовый URL обхода (по умолчанию app.site_base_url)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Общий бюджет страниц (хабы + карточки)', '300')
            ->addOption('hub-limit', null, InputOption::VALUE_REQUIRED, 'Потолок фазы хабов; её исчерпание — условие проверки сирот', '400')
            ->addOption('link-check-cap', null, InputOption::VALUE_REQUIRED, 'Сколько не-обойдённых внутренних ссылок проверить на 404', '150')
            ->addOption('delay-ms', null, InputOption::VALUE_REQUIRED, 'Пауза между запросами, мс (robots.txt: Crawl-delay 1)', '150')
            ->addOption('stdout-only', null, InputOption::VALUE_NONE, 'Только вывод: без записи findings и без TG')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Отправить дельту в Telegram')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести findings в JSON')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $base         = rtrim((string) ($input->getOption('base') ?: $this->siteBaseUrl), '/');
        $limit        = max(1, (int) $input->getOption('limit'));
        $hubLimit     = max(1, (int) $input->getOption('hub-limit'));
        $linkCap      = max(0, (int) $input->getOption('link-check-cap'));
        $delayMs      = max(0, (int) $input->getOption('delay-ms'));
        $stdoutOnly   = (bool) $input->getOption('stdout-only');
        $notify       = (bool) $input->getOption('notify');
        $json         = (bool) $input->getOption('json');

        $host = parse_url($base, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $io->error(sprintf('Не разобрать хост из --base=%s', $base));
            return Command::INVALID;
        }

        $io->title(sprintf('SEO тех-аудит · %s (бюджет %d страниц)', $base, $limit));

        $crawl = $this->crawl($io, $base, $host, $limit, $hubLimit, $delayMs);
        if ($crawl['pages'] === []) {
            $io->error('Не удалось получить ни одной страницы — сайт недоступен?');
            return Command::FAILURE;
        }

        $findings = $this->checkPages($crawl['pages']);
        $findings = array_merge($findings, $this->checkDuplicateTitles($crawl['pages']));
        $findings = array_merge($findings, $this->checkBrokenLinks($io, $crawl, $base, $linkCap, $delayMs));

        if ($crawl['hub_queue_drained']) {
            $findings = array_merge($findings, $this->checkOrphans($io, $crawl, $base));
        } else {
            $io->warning(sprintf(
                'Проверка сирот пропущена: фаза хабов упёрлась в --hub-limit=%d, в очереди осталось %d. '
                . 'На недообойдённом графе хабов «сироты» — ложные. Повысь --hub-limit.',
                $hubLimit,
                $crawl['hub_queue_left'],
            ));
        }

        if ($json) {
            $output->writeln(json_encode(['base' => $base, 'crawled' => count($crawl['pages']), 'findings' => $findings], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->renderFindings($io, $crawl, $findings);
        }

        if ($stdoutOnly) {
            $io->note('--stdout-only: findings не записаны, дельта не считалась.');
            return Command::SUCCESS;
        }

        $delta = $this->persistFindings($findings);
        $io->section('Дельта к прошлому прогону');
        $io->text(sprintf('появилось: %d · сохраняется: %d · исправлено: %d', count($delta['new']), $delta['kept'], count($delta['fixed'])));

        if ($notify && $this->notifier->isEnabled()) {
            $this->notifier->send($this->formatDigest($crawl, $findings, $delta));
        }

        return Command::SUCCESS;
    }

    /**
     * BFS с приоритетом: хабы (всё, кроме карточек брендов) вперёд, карточки — остатком.
     * Возвращает страницы, карту входящих ссылок и признак «очередь хабов исчерпана».
     *
     * @return array{pages:array<string,array{title:?string,description:?string,h1:int,imgs_no_alt:int,canonical:?string,cta_headings:list<string>,faq_without_schema:bool,noindex:bool,status:int}>,inbound:array<string,int>,seen_links:array<string,true>,hub_queue_drained:bool,hub_queue_left:int,late_hubs:int}
     */
    private function crawl(SymfonyStyle $io, string $base, string $host, int $limit, int $hubLimit, int $delayMs): array
    {
        $hubQueue  = ['/'];
        $cardQueue = [];
        $queued    = ['/' => true];

        $pages       = [];
        $inbound     = [];
        $seenLinks   = [];
        $aliases     = []; // запрошенный путь → путь после редиректов ('/' → '/ru/')
        $hubsCrawled = 0;
        $lateHubs    = 0;  // хабы, найденные уже в фазе карточек (см. ниже)
        $cardPhase   = false;

        while (true) {
            // Фаза 1 — хабы до исчерпания очереди (свой потолок $hubLimit): только их
            // граф и определяет, достоверен ли вывод о сиротах.
            // Фаза 2 — карточки брендов остатком общего бюджета, ради их собственных
            // проверок. Хабы, найденные только со карточек, в фазе 2 не крауляются:
            // иначе очередь хабов пополнялась бы бесконечно (3600+ карточек ссылаются
            // на гео/стилевые хабы) и условие «очередь исчерпана» было бы недостижимо.
            if (!$cardPhase && ($hubQueue === [] || $hubsCrawled >= $hubLimit)) {
                $cardPhase = true;
            }

            if (!$cardPhase) {
                $path = array_shift($hubQueue);
                $hubsCrawled++;
            } elseif ($cardQueue !== [] && count($pages) < $limit) {
                $path = array_shift($cardQueue);
            } else {
                break;
            }

            $html = $this->fetchHtml($base . $path, $status, $finalUrl);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            // Страницу знаем по КОНЕЧНОМУ пути: иначе '/' и '/ru/' (редирект на локаль)
            // выглядят как два URL с одинаковым title — ложный дубль на каждом прогоне.
            $finalPath = $finalUrl !== null ? (string) (parse_url($finalUrl, PHP_URL_PATH) ?: $path) : $path;
            if ($finalPath !== $path) {
                $aliases[$path] = $finalPath;
                $queued[$finalPath] = true;
            }
            if (isset($pages[$finalPath])) {
                continue; // уже обойдено под конечным адресом
            }

            if ($html === null) {
                $pages[$finalPath] = $this->emptyPage($status);
                continue;
            }

            $crawler           = new Crawler($html, $base . $finalPath);
            $pages[$finalPath] = $this->extractPage($crawler, $html, $status);
            $path              = $finalPath;

            foreach ($this->extractInternalLinks($crawler, $base, $host) as $link) {
                $seenLinks[$link] = true;
                // Сам себя страницей-источником не считаем: self-ссылки (логотип в шапке,
                // «наверх») иначе спасали бы от статуса сироты любую страницу.
                if ($link !== $path) {
                    $inbound[$link] = ($inbound[$link] ?? 0) + 1;
                }
                if (!isset($queued[$link])) {
                    $queued[$link] = true;
                    if ($this->isBrandCard($link)) {
                        $cardQueue[] = $link;
                    } elseif ($cardPhase) {
                        $lateHubs++; // найден с карточки — учитываем как оговорку, не крауляем
                    } else {
                        $hubQueue[] = $link;
                    }
                }
            }

            if (count($pages) % 25 === 0) {
                $io->text(sprintf('  обойдено %d (хабов %d), в очереди хабов %d, карточек %d', count($pages), $hubsCrawled, count($hubQueue), count($cardQueue)));
            }
        }

        $io->text(sprintf(
            'Обойдено страниц: %d (хабов %d, карточек %d)%s',
            count($pages),
            $hubsCrawled,
            count($pages) - $hubsCrawled,
            $lateHubs > 0 ? sprintf('; хабов, найденных только с карточек и не обойдённых: %d', $lateHubs) : '',
        ));

        // Входящие на алиас — это входящие на конечный адрес: ссылка на '/' поддерживает
        // '/ru/', иначе главная попала бы в сироты.
        foreach ($aliases as $from => $to) {
            if (isset($inbound[$from])) {
                $inbound[$to] = ($inbound[$to] ?? 0) + $inbound[$from];
                unset($inbound[$from]);
            }
        }

        return [
            'pages'             => $pages,
            'inbound'           => $inbound,
            'seen_links'        => $seenLinks,
            'hub_queue_drained' => $hubQueue === [],
            'hub_queue_left'    => count($hubQueue),
            'late_hubs'         => $lateHubs,
        ];
    }

    /** @return array{title:?string,description:?string,h1:int,imgs_no_alt:int,canonical:?string,cta_headings:list<string>,faq_without_schema:bool,noindex:bool,status:int} */
    private function emptyPage(int $status): array
    {
        return ['title' => null, 'description' => null, 'h1' => 0, 'imgs_no_alt' => 0, 'canonical' => null, 'cta_headings' => [], 'faq_without_schema' => false, 'noindex' => false, 'status' => $status];
    }

    /** @return array{title:?string,description:?string,h1:int,imgs_no_alt:int,canonical:?string,cta_headings:list<string>,faq_without_schema:bool,noindex:bool,status:int} */
    private function extractPage(Crawler $crawler, string $html, int $status): array
    {
        $title = $crawler->filter('title')->count() > 0 ? trim($crawler->filter('title')->first()->text('')) : null;

        $description = null;
        $metaDesc    = $crawler->filter('meta[name="description"]');
        if ($metaDesc->count() > 0) {
            $description = trim((string) $metaDesc->first()->attr('content'));
        }

        $canonical = null;
        $link      = $crawler->filter('link[rel="canonical"]');
        if ($link->count() > 0) {
            $canonical = trim((string) $link->first()->attr('href'));
        }

        // Нарушение — ОТСУТСТВИЕ атрибута alt. Пустой alt="" по спеке HTML легален и
        // означает «картинка декоративная» (так помечен, например, пиксель Метрики) —
        // считать его ошибкой значило бы ловить корректную разметку.
        $imgsNoAlt = 0;
        foreach ($crawler->filter('img') as $img) {
            if (!$img->hasAttribute('alt')) {
                $imgsNoAlt++;
            }
        }

        $ctaHeadings = [];
        foreach ($crawler->filter('h1, h2, h3, h4') as $heading) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $heading->textContent) ?? '');
            if ($text !== '' && preg_match(self::CTA_PATTERN, $text) === 1) {
                $ctaHeadings[] = sprintf('<%s> %s', strtolower($heading->nodeName), mb_substr($text, 0, 60));
            }
        }

        // FAQ ищем в тексте body, а не во всём HTML: иначе слово из JSON-LD или из
        // скрипта аналитики само себя «подтверждало» бы.
        $bodyText = $crawler->filter('body')->count() > 0 ? $crawler->filter('body')->text('') : '';
        $hasFaq   = preg_match(self::FAQ_PATTERN, $bodyText) === 1;
        $hasFaqLd = stripos($html, 'FAQPage') !== false;

        // noindex — это корзина, чекаут, авторизация и ЛК (tailwind/app.html.twig).
        // Чек-лист гайда — про индексируемые страницы: у noindex нет ни сниппета,
        // ни позиций, поэтому мета-правила к ним не применяем (иначе вечный шум).
        $noindex   = false;
        $metaRobots = $crawler->filter('meta[name="robots"]');
        if ($metaRobots->count() > 0) {
            $noindex = stripos((string) $metaRobots->first()->attr('content'), 'noindex') !== false;
        }

        return [
            'title'              => $title !== '' ? $title : null,
            'description'        => $description !== '' ? $description : null,
            'h1'                 => $crawler->filter('h1')->count(),
            'imgs_no_alt'        => $imgsNoAlt,
            'canonical'          => $canonical !== '' ? $canonical : null,
            'cta_headings'       => $ctaHeadings,
            'faq_without_schema' => $hasFaq && !$hasFaqLd,
            'noindex'            => $noindex,
            'status'             => $status,
        ];
    }

    /**
     * Внутренние ссылки как нормализованные пути. Отбрасываем: чужие хосты, не-ru локали
     * (они noindex), query/фрагменты, служебные секции и не-HTML.
     *
     * @return list<string>
     */
    private function extractInternalLinks(Crawler $crawler, string $base, string $host): array
    {
        $links = [];
        foreach ($crawler->filter('a[href]') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto|tel|javascript):#i', $href) === 1) {
                continue;
            }

            $abs = $this->absolutize($href, $base);
            if ($abs === null || parse_url($abs, PHP_URL_HOST) !== $host) {
                continue;
            }

            $path = (string) (parse_url($abs, PHP_URL_PATH) ?: '/');
            if (parse_url($abs, PHP_URL_QUERY) !== null) {
                continue; // фасеты/метки не крауним — они и не должны быть в индексе
            }
            if (preg_match(self::SKIP_PATH_PATTERN, $path) === 1) {
                continue;
            }
            if (preg_match('/\.(xml|txt|json|jpg|jpeg|png|webp|svg|ico|pdf|css|js)$/i', $path) === 1) {
                continue;
            }
            // Только ru-локаль и корень: остальные локали помечены noindex, аудит по ним не нужен.
            if (preg_match('#^/(en|zh|ar|tr|de|fr|es|ko)(/|$)#', $path) === 1) {
                continue;
            }

            $links[] = rtrim($path, '/') === '' ? '/' : $path;
        }

        return array_values(array_unique($links));
    }

    private function absolutize(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $base . $href;
        }

        return null; // относительные без слеша у нас не генерируются — не угадываем
    }

    private function isBrandCard(string $path): bool
    {
        return preg_match('#^/ru/brands/[^/]+$#', $path) === 1;
    }

    /**
     * @param array<string,array{title:?string,description:?string,h1:int,imgs_no_alt:int,canonical:?string,cta_headings:list<string>,faq_without_schema:bool,status:int}> $pages
     * @return list<array{url:string,rule:string,detail:string}>
     */
    private function checkPages(array $pages): array
    {
        $findings = [];
        foreach ($pages as $path => $p) {
            if ($p['status'] >= 400 || $p['status'] === 0) {
                continue; // недоступную страницу не проверяем по мете — она попадёт в битые ссылки
            }

            // URL проверяем всегда: кривой адрес ловит 404 и на noindex-странице.
            if (preg_match('/[A-Z]/', $path) === 1 || preg_match('/[^\x20-\x7E]/', rawurldecode($path)) === 1) {
                $findings[] = ['url' => $path, 'rule' => 'url_bad_case', 'detail' => ''];
            }
            if ($p['noindex']) {
                continue;
            }

            if ($p['title'] === null) {
                $findings[] = ['url' => $path, 'rule' => 'title_missing', 'detail' => ''];
            }
            if ($p['description'] === null) {
                $findings[] = ['url' => $path, 'rule' => 'description_missing', 'detail' => ''];
            } elseif (mb_strlen($p['description']) < self::DESCRIPTION_MIN) {
                $findings[] = ['url' => $path, 'rule' => 'description_short', 'detail' => sprintf('%d симв.', mb_strlen($p['description']))];
            }
            if ($p['h1'] === 0) {
                $findings[] = ['url' => $path, 'rule' => 'h1_missing', 'detail' => ''];
            } elseif ($p['h1'] > 1) {
                $findings[] = ['url' => $path, 'rule' => 'h1_multiple', 'detail' => sprintf('%d шт.', $p['h1'])];
            }
            if ($p['canonical'] === null) {
                $findings[] = ['url' => $path, 'rule' => 'canonical_missing', 'detail' => ''];
            }
            if ($p['imgs_no_alt'] > 0) {
                $findings[] = ['url' => $path, 'rule' => 'img_no_alt', 'detail' => sprintf('%d шт.', $p['imgs_no_alt'])];
            }
            if ($p['cta_headings'] !== []) {
                $findings[] = ['url' => $path, 'rule' => 'cta_in_heading', 'detail' => implode(' | ', array_slice($p['cta_headings'], 0, 2))];
            }
            if ($p['faq_without_schema']) {
                $findings[] = ['url' => $path, 'rule' => 'faq_without_schema', 'detail' => ''];
            }
        }

        return $findings;
    }

    /**
     * Одинаковый title на разных URL — сигнал каннибализации на уровне меты.
     *
     * @param array<string,array{title:?string,description:?string,h1:int,imgs_no_alt:int,canonical:?string,cta_headings:list<string>,faq_without_schema:bool,status:int}> $pages
     * @return list<array{url:string,rule:string,detail:string}>
     */
    private function checkDuplicateTitles(array $pages): array
    {
        $byTitle = [];
        foreach ($pages as $path => $p) {
            if ($p['title'] !== null && $p['status'] < 400 && !$p['noindex']) {
                $byTitle[$p['title']][] = $path;
            }
        }

        $findings = [];
        foreach ($byTitle as $title => $paths) {
            if (count($paths) < 2) {
                continue;
            }
            foreach ($paths as $path) {
                $findings[] = [
                    'url'    => $path,
                    'rule'   => 'title_duplicate',
                    'detail' => sprintf('%d URL с title «%s»', count($paths), mb_substr((string) $title, 0, 60)),
                ];
            }
        }

        return $findings;
    }

    /**
     * Битые внутренние ссылки: проверяем те, что найдены в разметке, но не были обойдены
     * (обойдённые уже имеют статус). Cap намеренный и оглашается в отчёте — молча урезать
     * проверку нельзя, иначе «0 битых» читается как «битых нет».
     *
     * @param array{pages:array<string,array<string,mixed>>,inbound:array<string,int>,seen_links:array<string,true>,hub_queue_drained:bool,hub_queue_left:int,late_hubs:int} $crawl
     * @return list<array{url:string,rule:string,detail:string}>
     */
    private function checkBrokenLinks(SymfonyStyle $io, array $crawl, string $base, int $cap, int $delayMs): array
    {
        $findings = [];

        // 1) уже обойдённые страницы с плохим статусом
        foreach ($crawl['pages'] as $path => $p) {
            if ($p['status'] >= 400) {
                $findings[] = ['url' => $path, 'rule' => 'internal_link_broken', 'detail' => sprintf('HTTP %d', $p['status'])];
            }
        }

        // 2) ссылки, до которых обход не дошёл
        $unchecked = array_diff(array_keys($crawl['seen_links']), array_keys($crawl['pages']));
        $toCheck   = array_slice(array_values($unchecked), 0, $cap);
        if ($unchecked !== []) {
            $io->text(sprintf('Проверка на 404: %d из %d необойдённых ссылок%s', count($toCheck), count($unchecked), count($toCheck) < count($unchecked) ? sprintf(' (cap --link-check-cap=%d, остаток НЕ проверен)', $cap) : ''));
        }

        foreach ($toCheck as $path) {
            $this->fetchHtml($base . $path, $status);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            if ($status >= 400) {
                $findings[] = ['url' => $path, 'rule' => 'internal_link_broken', 'detail' => sprintf('HTTP %d', $status)];
            }
        }

        return $findings;
    }

    /**
     * Сироты: URL есть в sitemap.xml, но ни одна обойдённая страница на него не ссылается.
     * Карточки брендов исключены — их входящие живут в каталоге/подборках, полный обход
     * которых в бюджет не влезает, и они дали бы шум вместо сигнала.
     *
     * @param array{pages:array<string,array<string,mixed>>,inbound:array<string,int>,seen_links:array<string,true>,hub_queue_drained:bool,hub_queue_left:int,late_hubs:int} $crawl
     * @return list<array{url:string,rule:string,detail:string}>
     */
    private function checkOrphans(SymfonyStyle $io, array $crawl, string $base): array
    {
        $sitemapPaths = $this->fetchSitemapPaths($base);
        if ($sitemapPaths === []) {
            $io->warning('sitemap.xml не прочитан — проверка сирот пропущена.');
            return [];
        }

        $candidates = array_filter($sitemapPaths, fn (string $p) => !$this->isBrandCard($p));
        $io->text(sprintf('Кандидатов в сироты (sitemap без карточек брендов): %d', count($candidates)));

        $findings = [];
        foreach ($candidates as $path) {
            if (($crawl['inbound'][$path] ?? 0) === 0) {
                $findings[] = ['url' => $path, 'rule' => 'orphan_page', 'detail' => 'в sitemap, но входящих внутренних ссылок не найдено'];
            }
        }

        return $findings;
    }

    /** @return list<string> ru-пути из sitemap.xml */
    private function fetchSitemapPaths(string $base): array
    {
        $xml = $this->fetchHtml($base . '/sitemap.xml', $status);
        if ($xml === null || $status >= 400) {
            return [];
        }

        if (preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m) === false) {
            return [];
        }

        $paths = [];
        foreach ($m[1] ?? [] as $loc) {
            $path = (string) (parse_url(trim($loc), PHP_URL_PATH) ?: '');
            if ($path === '' || preg_match('#^/(en|zh|ar|tr|de|fr|es|ko)(/|$)#', $path) === 1) {
                continue;
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /** $finalUrl — адрес после редиректов (по нему страница и учитывается). */
    private function fetchHtml(string $url, ?int &$status = null, ?string &$finalUrl = null): ?string
    {
        $finalUrl = null;
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout'          => 15,
                'max_duration'     => 20,
                'headers'          => ['User-Agent' => 'WearbaseTechAudit/1.0 (+https://wearbase.ru)'],
            ]);
            $status   = $response->getStatusCode();
            $info     = $response->getInfo('url');
            $finalUrl = is_string($info) ? $info : $url;

            return $status < 400 ? $response->getContent(false) : null;
        } catch (\Throwable) {
            $status = 0;

            return null;
        }
    }

    /**
     * @param array{pages:array<string,array<string,mixed>>,inbound:array<string,int>,seen_links:array<string,true>,hub_queue_drained:bool,hub_queue_left:int,late_hubs:int} $crawl
     * @param list<array{url:string,rule:string,detail:string}> $findings
     */
    private function renderFindings(SymfonyStyle $io, array $crawl, array $findings): void
    {
        if ($findings === []) {
            $io->success(sprintf('Чисто: %d страниц, ни одного нарушения чек-листа.', count($crawl['pages'])));
            return;
        }

        $byRule = [];
        foreach ($findings as $f) {
            $byRule[$f['rule']][] = $f;
        }

        $rows = [];
        foreach (self::RULES as $rule => $meta) {
            if (isset($byRule[$rule])) {
                $rows[] = [$meta['severity'], $meta['label'], count($byRule[$rule])];
            }
        }
        $io->section(sprintf('Нарушений: %d на %d страницах', count($findings), count($crawl['pages'])));
        $io->table(['Важность', 'Правило', 'Страниц'], $rows);

        foreach (self::RULES as $rule => $meta) {
            if (!isset($byRule[$rule])) {
                continue;
            }
            $io->text(sprintf('<info>%s</info> (%d):', $meta['label'], count($byRule[$rule])));
            foreach (array_slice($byRule[$rule], 0, 10) as $f) {
                $io->text(sprintf('  • %s%s', $f['url'], $f['detail'] !== '' ? ' — ' . $f['detail'] : ''));
            }
            if (count($byRule[$rule]) > 10) {
                $io->text(sprintf('  … ещё %d', count($byRule[$rule]) - 10));
            }
        }
    }

    /**
     * Upsert findings и расчёт дельты. Физического DELETE нет: исправленное помечается
     * fixed_on (soft-delete по правилам проекта), поэтому история нарушений сохраняется.
     * Повторное появление после исправления = снова «появилось» (fixed_on → NULL,
     * first_seen_on → сегодня).
     *
     * @param list<array{url:string,rule:string,detail:string}> $findings
     * @return array{new:list<array{url:string,rule:string}>,fixed:list<array{url:string,rule:string}>,kept:int}
     */
    private function persistFindings(array $findings): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');

        $open = [];
        foreach ($this->db->fetchAllAssociative('SELECT url, rule FROM seo_tech_finding WHERE fixed_on IS NULL') as $r) {
            $open[$r['url'] . '|' . $r['rule']] = true;
        }

        $new  = [];
        $kept = 0;
        $seen = [];
        foreach ($findings as $f) {
            $key        = $f['url'] . '|' . $f['rule'];
            $seen[$key] = true;

            if (isset($open[$key])) {
                $kept++;
            } else {
                $new[] = ['url' => $f['url'], 'rule' => $f['rule']];
            }

            $this->db->executeStatement(
                'INSERT INTO seo_tech_finding (url, rule, detail, first_seen_on, last_seen_on, fixed_on)
                 VALUES (:url, :rule, :detail, :day, :day, NULL)
                 ON DUPLICATE KEY UPDATE
                    detail = VALUES(detail),
                    last_seen_on = VALUES(last_seen_on),
                    first_seen_on = IF(fixed_on IS NULL, first_seen_on, VALUES(first_seen_on)),
                    fixed_on = NULL',
                [
                    'url'    => mb_substr($f['url'], 0, 512),
                    'rule'   => $f['rule'],
                    'detail' => mb_substr($f['detail'], 0, 255),
                    'day'    => $today,
                ],
            );
        }

        $fixed = [];
        foreach (array_keys($open) as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            [$url, $rule] = explode('|', $key, 2);
            $fixed[] = ['url' => $url, 'rule' => $rule];
            $this->db->executeStatement(
                'UPDATE seo_tech_finding SET fixed_on = ? WHERE url = ? AND rule = ? AND fixed_on IS NULL',
                [$today, $url, $rule],
            );
        }

        return ['new' => $new, 'fixed' => $fixed, 'kept' => $kept];
    }

    /**
     * @param array{pages:array<string,array<string,mixed>>,inbound:array<string,int>,seen_links:array<string,true>,hub_queue_drained:bool,hub_queue_left:int,late_hubs:int} $crawl
     * @param list<array{url:string,rule:string,detail:string}> $findings
     * @param array{new:list<array{url:string,rule:string}>,fixed:list<array{url:string,rule:string}>,kept:int} $delta
     */
    private function formatDigest(array $crawl, array $findings, array $delta, int $charCap = 1800): string
    {
        $lines = [
            sprintf('<b>🔧 SEO тех-аудит · %s</b>', (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m')),
            sprintf('Обойдено %d стр. · нарушений %d (новых %d, исправлено %d)', count($crawl['pages']), count($findings), count($delta['new']), count($delta['fixed'])),
        ];

        if (!$crawl['hub_queue_drained']) {
            $lines[] = sprintf('⚠️ сироты не считались: бюджет обхода кончился (осталось %d хабов)', $crawl['hub_queue_left']);
        }

        if ($delta['new'] !== []) {
            $lines[] = "\n<b>Появилось:</b>";
            foreach (array_slice($delta['new'], 0, 10) as $f) {
                $lines[] = sprintf('• %s — %s', htmlspecialchars(self::RULES[$f['rule']]['label'] ?? $f['rule']), htmlspecialchars($f['url']));
            }
            if (count($delta['new']) > 10) {
                $lines[] = sprintf('… ещё %d', count($delta['new']) - 10);
            }
        }

        if ($delta['fixed'] !== []) {
            $lines[] = sprintf("\n<b>Исправлено:</b> %d", count($delta['fixed']));
        }

        if ($delta['new'] === [] && $delta['fixed'] === []) {
            $lines[] = "\nБез изменений к прошлому прогону.";
        }

        $msg = implode("\n", $lines);

        return mb_strlen($msg) > $charCap ? mb_substr($msg, 0, $charCap - 1) . '…' : $msg;
    }
}
