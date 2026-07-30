<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandSourceUrl;
use App\Notification\AdminNotifier;
use App\Service\BrandActionSigner;
use App\Service\BrandSourceFinder;
use App\Service\BraveSearchClient;
use App\Service\Discovery\DiscoveredUrl;
use App\Service\Moderation\ApplicationMatcher;
use App\Service\NearDuplicateDetector;
use App\Service\SearxClient;
use App\Service\SearxUnavailableException;
use App\Service\WebScraperService;
use App\Service\YandexSearchClient;
use App\Service\YandexSearchMeter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Агент-конвейер премодерации (Mac): тянет очередь самрег-заявок с прода
 * (GET /api/v1/moderation/queue), детерминированно (БЕЗ LLM — App\Service\Moderation\ApplicationMatcher)
 * матчит заявленные контакты бренда против найденного сайта-кандидата (BrandSourceFinder +
 * WebScraperService, переиспользуются как есть), считает чек-лист полноты и красные флаги,
 * шлёт вердикт на прод (POST /api/v1/moderation/verdict) + TG-уведомление админу с
 * подписанными кнопками approve/request-changes/reject.
 *
 * ⚠️ Кнопки подписывает BrandActionSigner (kernel.secret ЭТОЙ машины — Mac), а проверяет их
 * BrandModerationController НА ПРОДЕ (kernel.secret прод). Если APP_SECRET Mac и прод не
 * совпадают — кнопки не сработают. Для MVP синхронизировать APP_SECRET вручную; на этапе 2
 * рассмотреть переход на AGENT_API_SECRET (он уже гарантированно общий для обеих машин).
 *
 * Ниша/происхождение брендов НЕ зеркалированы на Mac (этап 2) — self-reg бренды живут только
 * на проде, поэтому app:brand:niche-check/origin-check здесь почти всегда не находят локальную
 * копию и остаются null (см. classifyOnMacIfMirrored).
 *
 *   php bin/console app:brand:moderate-tick --dry-run           # показать досье, ничего не писать
 *   php bin/console app:brand:moderate-tick --id=3673            # точечно один бренд (id — прод)
 *   php bin/console app:brand:moderate-tick --max=3 --no-debug   # боевой прогон
 */
#[AsCommand(
    name: 'app:brand:moderate-tick',
    description: 'Премодерация самрег-брендов: детерминированный матчинг контактов + вердикт на прод + TG',
)]
class ModerateTickCommand extends Command
{
    private const MAX_ATTEMPTS = 3; // ≥ — не долбим анализ дальше, красный флаг человеку

    // Зонд доменов-кандидатов (email заявителя + название бренда) — дешёвая альтернатива
    // поиску: просто HTTP-запрос, без движков/квоты/GPU. Зоны в порядке правдоподобия для
    // RU self-reg брендов; кап держит суммарное число запросов небольшим.
    private const DOMAIN_ZONES = ['ru', 'com', 'store', 'shop'];
    private const MAX_DOMAIN_CANDIDATES = 8;

    // Генерик-филлер в самрег-названиях (реальные кейсы: «Русский бренд АХ!», «Новый бренд
    // all4b2b» — см. docs/brand_self_service.md) — не несёт сигнала о домене, транслитерация
    // «brend»/«novyy» дала бы мусорного кандидата.
    private const TITLE_STOPWORDS = ['новый', 'бренд', 'русский', 'российский'];

    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    // Пауза перед контактным поиском — та же механика и тот же темп, что и
    // BrandSourceFinder::QUERY_SLEEP_MS (не долбим SearXNG/Yandex второй раз тем же паттерном).
    private const CONTACT_QUERY_SLEEP_MS  = 1800;
    private const CONTACT_QUERY_JITTER_MS = 1200;
    private const CONTACT_BRAVE_FALLBACK_MIN = 3;

    // Домен → link_type для соц-/маркетплейс-ссылок, найденных на подтверждённом сайте бренда
    // (docs/brand_self_service.md §4/§6). Тот же набор, что и приватный
    // OutboundClickController::classify() (там — трекер кликов, здесь — заполнение brand_link
    // при вердикте); дублирование дешевле общего сервиса ради 15 строк. Домены без явного типа
    // сюда не попадают — 'other' зарезервирован под известные, но не перечисленные в докблоке
    // BrandLink соцсети, а не под произвольные ссылки со страницы.
    private const SOCIAL_MARKETPLACE_TYPES = [
        'instagram.com'    => 'instagram',
        'vk.com'           => 'vk',
        'vkontakte.ru'     => 'vk',
        't.me'             => 'telegram',
        'telegram.me'      => 'telegram',
        'youtube.com'      => 'youtube',
        'youtu.be'         => 'youtube',
        'tiktok.com'       => 'tiktok',
        'wildberries.ru'   => 'marketplace',
        'ozon.ru'          => 'marketplace',
        'lamoda.ru'        => 'marketplace',
        'market.yandex.ru' => 'marketplace',
        'avito.ru'         => 'marketplace',
        'facebook.com'     => 'other',
        'ok.ru'            => 'other',
        'pinterest.com'    => 'other',
        'twitter.com'      => 'other',
        'x.com'            => 'other',
    ];
    private const MAX_SOCIAL_LINKS = 6;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BrandSourceFinder $sourceFinder,
        private readonly WebScraperService $scraper,
        private readonly ApplicationMatcher $matcher,
        private readonly NearDuplicateDetector $dup,
        private readonly YandexSearchMeter $yandexMeter,
        private readonly YandexSearchClient $yandex,
        private readonly SearxClient $searx,
        private readonly BraveSearchClient $brave,
        private readonly AdminNotifier $notifier,
        private readonly BrandActionSigner $actionSigner,
        private readonly EntityManagerInterface $em,
        private readonly ?string $prodApiUrl,
        private readonly ?string $apiToken,
        private readonly ?string $apiSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Точечный прогон по ID бренда на проде')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать досье + текст TG, не писать вердикт и не отправлять')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Сколько заявок обработать за прогон', 8)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько заявок запросить у очереди прода', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (trim((string) $this->prodApiUrl) === '' || trim((string) $this->apiToken) === '' || trim((string) $this->apiSecret) === '') {
            $io->error('Не заданы PROD_API_URL / AGENT_API_TOKEN / AGENT_API_SECRET в .env.local');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        // Без TG вердикт человеку не увидеть — уходит в никуда (см. докс: грабля ADMIN_TELEGRAM_CHAT_ID).
        if (!$dryRun && !$this->notifier->isEnabled()) {
            $io->error('ADMIN_TELEGRAM_CHAT_ID не задан — вердикт некому показать, останавливаемся.');
            return Command::FAILURE;
        }

        if (!$this->yandexMeter->allowed()) {
            $io->text('Дневная квота Yandex Search исчерпана — оставляем очередь как есть, выходим успешно.');
            return Command::SUCCESS;
        }

        $max   = max(1, (int) $input->getOption('max'));
        $limit = max(1, (int) $input->getOption('limit'));
        $idOpt = $input->getOption('id');

        $query = ['limit' => $limit];
        if ($idOpt !== null) {
            $query['id'] = (int) $idOpt;
        }

        $items = $this->fetchQueue($query, $io);
        if ($items === []) {
            $io->success('Очередь премодерации пуста.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Премодерация: %d заявок в очереди%s', count($items), $dryRun ? ' (dry-run)' : ''));

        $processed = 0;
        foreach ($items as $item) {
            if ($processed >= $max) {
                break;
            }
            try {
                $this->processItem($item, $io, $dryRun);
            } catch (\Throwable $e) {
                $io->warning(sprintf('  ошибка на "%s": %s', $item['title'] ?? '?', $e->getMessage()));
            }
            $processed++;
        }

        return Command::SUCCESS;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchQueue(array $query, SymfonyStyle $io): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/moderation/queue', [
                'query'   => $query,
                'headers' => ['X-Agent-Token' => (string) $this->apiToken],
                'timeout' => 20,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $io->error('Прод недоступен: ' . $e->getMessage());
            return [];
        }

        return is_array($data['items'] ?? null) ? $data['items'] : [];
    }

    private function processItem(array $item, SymfonyStyle $io, bool $dryRun): void
    {
        $title = (string) ($item['title'] ?? '');
        $slug  = (string) ($item['slug'] ?? '');
        $io->section(sprintf('%s (%s)', $title !== '' ? $title : '—', $slug));

        if ((int) ($item['analyze_attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            $io->warning(sprintf('Превышен лимит автопопыток анализа (%d) — нужна ручная модерация, пропуск.', self::MAX_ATTEMPTS));
            return;
        }

        $brandStub = (new Brand())->setTitle($title)->setSlug($slug !== '' ? $slug : 'stub')->setCity((string) ($item['city'] ?? ''));
        $discovered = $this->sourceFinder->discoverTiered($brandStub, 20);

        $ownSite = null;
        foreach ($discovered as $d) {
            if ($d->sourceType === BrandSourceUrl::TYPE_OWN_SITE && ($ownSite === null || $d->relevanceScore > $ownSite->relevanceScore)) {
                $ownSite = $d;
            }
        }

        // Угаданный из слага домен ({slug}.ru/.com — самый слабый T1-сигнал discoverTiered)
        // не факт о бренде, а наша догадка: если он не отвечает, это не site_unreachable.
        $guessedSlugUrls = $slug !== '' ? ["https://{$slug}.ru", "https://{$slug}.com"] : [];
        $isSlugGuess = $ownSite !== null && in_array(rtrim($ownSite->url, '/'), $guessedSlugUrls, true);

        // Bug 2: discoverTiered() строит кандидатов из названия/слага — для патологических
        // названий (мало значащих символов + пунктуация, кейс «Русский бренд АХ!») либо
        // ничего не находит, либо угаданный домен не отвечает. Порядок: сначала ссылки
        // владельца (уже внутри discoverTiered выше) → дешёвый зонд доменов-кандидатов из
        // email/названия (probeDomainCandidates, без поисковика и квоты) → и только если
        // это тоже пусто — поисковые запросы по контактам (discoverByContacts, дороже).
        // Первый подтверждённый ApplicationMatcher кандидат — результат, дальше не ищем.
        if ($ownSite === null || !$ownSite->live) {
            $byDomain = $this->probeDomainCandidates($item, $title);
            if ($byDomain !== null) {
                $ownSite = $byDomain;
                $isSlugGuess = false; // подтверждено матчером, не наша догадка
            } else {
                $byContact = $this->discoverByContacts($item);
                if ($byContact !== null) {
                    $ownSite = $byContact;
                    $isSlugGuess = false; // найден поиском, не нашей догадкой
                }
            }
        }

        [$pages, $officialDomain, $siteFlags] = $this->collectPages($ownSite, $isSlugGuess);

        $match = $this->matcher->evaluate(
            ['title' => $title, 'email' => $item['email'] ?? null, 'phone' => $item['phone'] ?? null, 'address' => $item['address'] ?? null],
            $pages,
            $officialDomain,
            $item['owner_email'] ?? null,
        );

        [$missing, $checklistFlags] = $this->checklist($item);
        $redFlags = array_merge($siteFlags, $checklistFlags, $this->duplicateFlags($title, $ownSite));

        [$nicheStatus, $originStatus, $note] = $this->classifyOnMacIfMirrored($slug);
        $verdict = $this->decideVerdict($match, $redFlags, $nicheStatus, $originStatus);
        $summary = $this->buildSummary($match, $missing, $redFlags, $note);
        $links   = $this->buildLinksPayload($match, $pages);

        $io->text(sprintf('  identity=%s control=%s verdict=%s', $match['identity_match'], $match['control_proof'], $verdict));
        if ($redFlags !== []) {
            $io->text('  🚩 ' . implode('; ', $redFlags));
        }
        if ($missing !== []) {
            $io->text('  missing: ' . implode(',', $missing));
        }
        if ($links !== []) {
            $io->text('  links: ' . implode(', ', array_map(static fn (array $l) => "{$l['link_type']}:{$l['link_url']}", $links)));
        }

        $tgText = $this->buildTgText($title, $match, $missing, $redFlags, $summary);

        if ($dryRun) {
            $io->text('--- TG (dry-run, не отправлено) ---');
            $io->text($tgText);
            return;
        }

        $this->postVerdict($slug, $match, $redFlags, $missing, $summary, $nicheStatus, $originStatus, $verdict, $links);

        try {
            $this->notifier->sendWithButtons($tgText, $this->signedButtons((int) $item['brand_id']));
        } catch (\Throwable $e) {
            $io->warning('TG не отправлен: ' . $e->getMessage());
        }
    }

    /**
     * @param bool $isSlugGuess true — $ownSite->url это {slug}.ru/.com, угаданный нами
     *        (не факт о бренде): недоступность такого домена не флагуем (Bug 2)
     * @return array{0:array<int,array{url:string,html:string}>,1:?string,2:string[]}
     */
    private function collectPages(?DiscoveredUrl $ownSite, bool $isSlugGuess = false): array
    {
        if ($ownSite === null) {
            return [[], null, []];
        }

        $main = $this->scraper->fetch($ownSite->url);
        if ($main === null || $main['html'] === '') {
            return [[], null, $isSlugGuess ? [] : ['site_unreachable:' . $ownSite->url]];
        }

        $pages = [['url' => $main['url'], 'html' => $main['html']]];
        $officialDomain = $this->hostOf($ownSite->url);

        $subUrls = array_values(array_filter(
            $this->scraper->discoverSitePages($ownSite->url, 60),
            static fn (string $u): bool => (bool) preg_match('~contact|kontakt|about|o-nas|company|kontakty~i', $u),
        ));
        foreach (array_slice($subUrls, 0, 5) as $u) {
            $p = $this->scraper->fetch($u);
            if ($p !== null && $p['html'] !== '') {
                $pages[] = ['url' => $p['url'], 'html' => $p['html']];
            }
        }

        return [$pages, $officialDomain, []];
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower(preg_replace('/^www\./', '', $host)) : null;
    }

    /**
     * Ссылки в payload вердикта — только для ПОДТВЕРЖДЁННОГО сайта (identity_match=confirmed):
     * неподтверждённые кандидаты и наши доменные догадки в brand_link не пишем (см. докстринг
     * задачи/docs/brand_self_service.md §4). $pages — уже отфетченные для матчинга страницы
     * (главная + контакты), сайт как website + соц-/маркетплейс-ссылки с тех же страниц —
     * побочный продукт того же обхода, второго похода в сеть нет.
     *
     * @param array{identity_match:string} $match
     * @param array<int,array{url:string,html:string}> $pages
     * @return list<array{link_type:string,link_url:string}>
     */
    private function buildLinksPayload(array $match, array $pages): array
    {
        if ($match['identity_match'] !== 'confirmed' || $pages === []) {
            return [];
        }

        $links = [['link_type' => 'website', 'link_url' => $pages[0]['url']]];

        return array_merge($links, $this->extractSocialLinks($pages));
    }

    /**
     * Соц-/маркетплейс-ссылки со страниц сайта (WebScraperService::extractLinks() уже
     * абсолютизирует href и режет self-домены/job-noise через UrlFilter) — здесь только
     * классификация по хосту + дедуп + кап MAX_SOCIAL_LINKS.
     *
     * @param array<int,array{url:string,html:string}> $pages
     * @return list<array{link_type:string,link_url:string}>
     */
    private function extractSocialLinks(array $pages): array
    {
        $found = [];
        foreach ($pages as $page) {
            foreach ($this->scraper->extractLinks($page['html'], $page['url']) as $url) {
                if (isset($found[$url]) || count($found) >= self::MAX_SOCIAL_LINKS) {
                    continue;
                }
                $host = $this->hostOf($url);
                $type = $host !== null ? $this->classifySocialHost($host) : null;
                if ($type !== null) {
                    $found[$url] = $type;
                }
            }
        }

        $links = [];
        foreach ($found as $url => $type) {
            $links[] = ['link_type' => $type, 'link_url' => $url];
        }

        return $links;
    }

    private function classifySocialHost(string $host): ?string
    {
        foreach (self::SOCIAL_MARKETPLACE_TYPES as $domain => $type) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Bug 2: discoverTiered() строит кандидатов из названия/слага — патологические названия
     * (мало значащих символов, кейс «Русский бренд АХ!») не дают ничего живого. Ищем сайт по
     * КОНТАКТАМ ИЗ ЗАЯВКИ (телефон в нескольких форматах + email) через ту же связку движков,
     * что и BrandSourceFinder::searchPaced() (searchAnyEngine ниже) — НЕ только Yandex (ключ
     * может быть мёртв/не сконфигурирован, живой прогон 2026-07-30 это подтвердил). Кандидата
     * подтверждаем ApplicationMatcher — телефон/email реально на странице, а не просто совпал
     * в выдаче.
     *
     * @param array<string,mixed> $item элемент очереди (phone/email заявителя)
     */
    private function discoverByContacts(array $item): ?DiscoveredUrl
    {
        $phone = trim((string) ($item['phone'] ?? ''));
        $email = trim((string) ($item['email'] ?? ''));

        $queries = [];
        if ($phone !== '') {
            $ten = $this->matcher->normalizePhone($phone);
            if ($ten !== '') {
                $queries[] = '+7' . $ten;
                $queries[] = '8' . $ten;
                $queries[] = $ten;
            }
        }
        if ($email !== '') {
            $queries[] = $email;
        }

        foreach ($queries as $q) {
            foreach ($this->searchAnyEngine($q) as $r) {
                $url = trim((string) ($r['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $page = $this->scraper->fetch($url);
                if ($page === null || $page['html'] === '') {
                    continue;
                }
                // Только против самих контактов заявки (title намеренно не передаём —
                // здесь нужно доказать identity через phone/email, не через совпадение имени).
                $confirm = $this->matcher->evaluate(
                    ['phone' => $phone, 'email' => $email],
                    [['url' => $page['url'], 'html' => $page['html']]],
                    $this->hostOf($url),
                    null,
                );
                if ($confirm['identity_match'] !== 'unconfirmed') {
                    return new DiscoveredUrl($page['url'], BrandSourceUrl::TYPE_OWN_SITE, 1, 0.9, true);
                }
            }
        }

        return null;
    }

    /**
     * Та же механика чередования движков, что и BrandSourceFinder::searchPaced() (Yandex Search
     * API первичный → SearXNG вспомогательный, не фатален → Brave добор при малой выдаче), с той
     * же паузой перед запросом (CONTACT_QUERY_SLEEP_MS). BrandSourceFinder не трогаем — это свой,
     * не расшаренный поисковый путь для контактных запросов. Любой недоступный/неконфигурированный
     * или упавший движок (напр. протухший Yandex-ключ, 401) молча пропускается — не падает.
     *
     * @return array<int,array{url:string,title:string,content:string}>
     */
    private function searchAnyEngine(string $query): array
    {
        usleep((self::CONTACT_QUERY_SLEEP_MS + random_int(0, self::CONTACT_QUERY_JITTER_MS)) * 1000);

        $results = [];
        if ($this->yandex->isConfigured()) {
            try {
                $results = $this->yandex->search($query, 5);
            } catch (\Throwable) {
                // недоступен/протух ключ/квота — пробуем SearXNG ниже
            }
        }

        try {
            $seen = [];
            foreach ($results as $r) {
                $seen[rtrim($r['url'], '/')] = true;
            }
            foreach ($this->searx->search($query, 5) as $r) {
                if (!isset($seen[rtrim($r['url'], '/')])) {
                    $results[] = $r;
                }
            }
        } catch (SearxUnavailableException) {
            // SearXNG лёг — не падаем, отдаём то, что уже есть (или пусто)
        }

        if (count($results) < self::CONTACT_BRAVE_FALLBACK_MIN && $this->brave->isConfigured() && $this->brave->allowed()) {
            $seen = [];
            foreach ($results as $r) {
                $seen[rtrim($r['url'], '/')] = true;
            }
            foreach ($this->brave->search($query, 5) as $r) {
                if (!isset($seen[rtrim($r['url'], '/')])) {
                    $results[] = $r;
                }
            }
        }

        return $results;
    }

    /**
     * Дешёвый зонд доменов-кандидатов (guessDomainCandidates): просто HTTP-запрос через
     * WebScraperService (те же редиректы/таймаут, что и весь конвейер) — без поисковика,
     * без квоты, без GPU. Доступность домена — НЕ факт принадлежности бренду (ловушка
     * ahsilk.com/ahsilk.ru — омоним, другая компания), поэтому живой кандидат обязан пройти
     * ApplicationMatcher. Первый подтверждённый (identity_match !== 'unconfirmed') — результат;
     * неподтверждённые молча отбрасываются — это наши догадки, а не факты о бренде, поэтому
     * НЕ site_unreachable и НЕ red_flag (см. collectPages/$isSlugGuess выше).
     *
     * @param array<string,mixed> $item элемент очереди (email/phone/address заявителя)
     */
    private function probeDomainCandidates(array $item, string $title): ?DiscoveredUrl
    {
        $email   = trim((string) ($item['email'] ?? ''));
        $phone   = trim((string) ($item['phone'] ?? ''));
        $address = $item['address'] ?? null;

        foreach ($this->guessDomainCandidates($title, $email) as $url) {
            $page = $this->scraper->fetch($url);
            if ($page === null || $page['html'] === '') {
                continue;
            }
            $confirm = $this->matcher->evaluate(
                ['title' => $title, 'email' => $email, 'phone' => $phone, 'address' => $address],
                [['url' => $page['url'], 'html' => $page['html']]],
                $this->hostOf($url),
                null,
            );
            if ($confirm['identity_match'] !== 'unconfirmed') {
                return new DiscoveredUrl($page['url'], BrandSourceUrl::TYPE_OWN_SITE, 1, 0.9, true);
            }
        }

        return null;
    }

    /**
     * Кандидаты-домены без похода в сеть: локальная часть email заявителя (без плюс-суффикса,
     * точек, цифрового хвоста) + латинская транслитерация значащих слов названия бренда (генерик-
     * филлер вроде «бренд»/«русский» отфильтрован — см. TITLE_STOPWORDS). Порядок зон —
     * DOMAIN_ZONES. Дедуп баз, кап MAX_DOMAIN_CANDIDATES.
     *
     * @return string[] полные https:// URL
     */
    private function guessDomainCandidates(string $title, string $email): array
    {
        $bases = [];
        if (($emailBase = $this->emailDomainCandidate($email)) !== null) {
            $bases[] = $emailBase;
        }
        if (($titleBase = $this->titleDomainCandidate($title)) !== null && !in_array($titleBase, $bases, true)) {
            $bases[] = $titleBase;
        }

        $urls = [];
        foreach ($bases as $base) {
            foreach (self::DOMAIN_ZONES as $zone) {
                $urls[] = "https://{$base}.{$zone}";
            }
        }

        return array_slice($urls, 0, self::MAX_DOMAIN_CANDIDATES);
    }

    /** Локальная часть email → домен-основа: "ah.silk" из "ah.silk@yandex.ru" → "ahsilk". */
    private function emailDomainCandidate(string $email): ?string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }

        $local = strtolower(substr($email, 0, $at));
        $local = explode('+', $local, 2)[0];                     // без плюс-суффикса
        $local = str_replace('.', '', $local);                   // без точек
        $local = preg_replace('/\d+$/', '', $local) ?? $local;   // без цифрового хвоста
        $local = preg_replace('/[^a-z]/', '', $local) ?? $local;

        return mb_strlen($local) > 3 ? $local : null;
    }

    /** Транслитерация значащих слов названия (генерик-филлер вырезан) — одна база или null. */
    private function titleDomainCandidate(string $title): ?string
    {
        $normalized = str_replace('ё', 'е', mb_strtolower($title));
        preg_match_all('/\p{L}+/u', $normalized, $m);

        $latin = '';
        foreach ($m[0] as $word) {
            if (mb_strlen($word) <= 3 || in_array($word, self::TITLE_STOPWORDS, true)) {
                continue;
            }
            $latin .= $this->transliterate($word);
        }

        return strlen($latin) > 3 ? $latin : null;
    }

    private function transliterate(string $word): string
    {
        $out = '';
        foreach (mb_str_split($word) as $ch) {
            $out .= self::TRANSLIT[$ch] ?? $ch;
        }

        return $out;
    }

    /** @return array{0:string[],1:string[]} [missing, red_flags] */
    private function checklist(array $item): array
    {
        $missing = [];
        if (empty($item['logo'])) {
            $missing[] = 'logo';
        }
        if (empty($item['has_priced_product'])) {
            $missing[] = 'price';
        }
        // ИНН и место производства на этапе самрега не собираются вовсе (юр.лицо оформляется
        // позже, при онбординге оплаты — SellerLegalEntity) — всегда missing на этой стадии.
        $missing[] = 'inn';
        $missing[] = 'production_place';
        if (empty($item['founding_year'])) {
            $missing[] = 'founding_year';
        }
        if (trim((string) ($item['description'] ?? '')) === '') {
            $missing[] = 'description';
        }
        if ((int) ($item['link_count'] ?? 0) === 0) {
            $missing[] = 'links';
        }

        $flags = [];
        if (!empty($item['logo']) && preg_match('~screenshot|scherm|снимок|photo_\d~i', (string) $item['logo'])) {
            $flags[] = 'logo_is_screenshot';
        }
        if ((int) ($item['product_count'] ?? 0) > 0 && empty($item['has_priced_product'])) {
            $flags[] = 'product_without_price';
        }
        if ((int) ($item['link_count'] ?? 0) === 0) {
            $flags[] = 'no_links';
        }
        if (trim((string) ($item['description'] ?? '')) === '' && trim((string) ($item['anons'] ?? '')) === '' && (int) ($item['product_count'] ?? 0) === 0) {
            $flags[] = 'empty_card';
        }

        return [$missing, $flags];
    }

    /** @return string[] */
    private function duplicateFlags(string $title, ?DiscoveredUrl $ownSite): array
    {
        if (trim($title) === '') {
            return [];
        }
        $flags = [];

        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT slug, title FROM brand WHERE status != 'deleted'");
        foreach ($rows as $row) {
            if ($this->dup->similarity($title, (string) $row['title']) >= NearDuplicateDetector::TITLE_THRESHOLD) {
                $flags[] = 'duplicate_candidate:title~' . $row['slug'];
                break;
            }
        }

        if ($ownSite !== null && ($host = $this->hostOf($ownSite->url)) !== null) {
            $existing = $this->em->getConnection()->fetchAssociative(
                'SELECT b.slug FROM brand_link bl JOIN brand b ON b.id = bl.brand_id WHERE bl.link_url LIKE :h LIMIT 1',
                ['h' => '%' . $host . '%'],
            );
            if ($existing !== false) {
                $flags[] = 'duplicate_candidate:domain~' . $existing['slug'];
            }
        }

        return $flags;
    }

    /**
     * Ниша/происхождение работают по Mac-БД (app:brand:niche-check/origin-check) — self-reg
     * бренды живут только на проде, поэтому почти всегда зеркалированной копии на Mac нет и
     * поля остаются null (зеркалирование — этап 2, здесь НЕ делаем).
     *
     * @return array{0:?string,1:?string,2:string}
     */
    private function classifyOnMacIfMirrored(string $slug): array
    {
        if ($slug === '') {
            return [null, null, 'ниша/происхождение: пустой slug — пропущено'];
        }
        $brand = $this->em->getRepository(Brand::class)->findOneBy(['slug' => $slug]);
        if ($brand === null) {
            return [null, null, 'ниша/происхождение: бренд не зеркалирован на Mac — не определены (этап 2)'];
        }

        $app = $this->getApplication();
        if ($app !== null) {
            $app->find('app:brand:niche-check')->run(new ArrayInput(['--id' => $brand->getId(), '--force' => true]), new NullOutput());
            $app->find('app:brand:origin-check')->run(new ArrayInput(['--id' => $brand->getId(), '--force' => true]), new NullOutput());
            $this->em->refresh($brand);
        }

        return [$brand->getNicheStatus(), $brand->getOriginStatus(), 'ниша/происхождение определены по зеркалированной копии на Mac'];
    }

    /**
     * Маппинг вердикта — docs/brand_self_service.md §3 (таблица авто-решений).
     * `reject` допустим ТОЛЬКО при niche_status='off' или origin_status foreign/unknown —
     * это единственный автоматический ОТКАЗ настоящему бренду, поэтому гейт строгий.
     * Отсутствие цифрового следа (identity_match='no_trace') — НЕ повод отклонять: молодой
     * бренд без сайта — нормальное явление, а no_trace может значить лишь то, что мы плохо
     * искали (Bug 2). В MVP отдельного статуса awaiting_facts нет — no_trace, как и любой
     * неподтверждённый/слабый identity_match или красный флаг, уходит в `request_changes`
     * (дубль/red_flags — по сути «решает человек», в MVP это тот же request_changes без
     * авто-действия дальше).
     *
     * @param array{identity_match:string,control_proof:string,evidence:mixed} $match @param string[] $redFlags
     */
    private function decideVerdict(array $match, array $redFlags, ?string $nicheStatus, ?string $originStatus): string
    {
        if ($nicheStatus === 'off' || in_array($originStatus, ['foreign', 'unknown'], true)) {
            return 'reject';
        }
        if ($redFlags !== [] || $match['identity_match'] !== 'confirmed') {
            return 'request_changes';
        }

        return 'publish';
    }

    /** @param string[] $missing @param string[] $redFlags */
    private function buildSummary(array $match, array $missing, array $redFlags, string $note): string
    {
        if ($match['identity_match'] === 'no_trace') {
            $note = trim('нужна ссылка на сайт/соцсеть — кандидата не нашли. ' . $note);
        }

        return sprintf(
            'identity=%s control=%s | missing: %s | red_flags: %s | %s',
            $match['identity_match'],
            $match['control_proof'],
            $missing === [] ? '—' : implode(',', $missing),
            $redFlags === [] ? '—' : implode(',', $redFlags),
            $note,
        );
    }

    /** @param string[] $missing @param string[] $redFlags */
    private function buildTgText(string $title, array $match, array $missing, array $redFlags, string $summary): string
    {
        $lines = [
            sprintf('🔎 <b>Премодерация:</b> %s', htmlspecialchars($title !== '' ? $title : '(без названия)', ENT_QUOTES, 'UTF-8')),
            sprintf('Личность: %s · Контроль сайта: %s', $match['identity_match'], $match['control_proof']),
        ];
        if ($missing !== []) {
            $lines[] = 'Не хватает: ' . implode(', ', $missing);
        }
        if ($redFlags !== []) {
            $lines[] = '🚩 ' . implode('; ', $redFlags);
        }
        $lines[] = htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');

        return implode("\n", $lines);
    }

    /** @return list<array{text:string,url:string}> */
    private function signedButtons(int $brandId): array
    {
        // Одобрение необратимо ставит бренд в очередь публикации — TTL 7 дней, чтобы старая
        // ссылка из TG-истории не сработала спустя месяцы по забытому решению.
        $exp = time() + 7 * 86400;
        $host = 'https://wearbase.ru';

        return [
            ['text' => '✅ Одобрить', 'url' => sprintf('%s/mod/brand-action?action=approve&id=%d&key=%s&exp=%d', $host, $brandId, $this->actionSigner->sign('approve', $brandId, $exp), $exp)],
            ['text' => '✏️ На доработку', 'url' => sprintf('%s/mod/brand-action?action=request-changes&id=%d&key=%s', $host, $brandId, $this->actionSigner->sign('request-changes', $brandId))],
            ['text' => '🚫 Отклонить', 'url' => sprintf('%s/mod/brand-action?action=reject&id=%d&key=%s', $host, $brandId, $this->actionSigner->sign('reject', $brandId))],
        ];
    }

    /**
     * @param string[] $redFlags @param string[] $missing
     * @param list<array{link_type:string,link_url:string}> $links сайт (если подтверждён) +
     *        соц-/маркетплейс-ссылки, см. buildLinksPayload(). Эндпоинт (BrandIngestController::
     *        moderationVerdict) сам не создаёт дублей по уже существующему URL и не трогает
     *        owner-provenance — повторный прогон с тем же payload идемпотентен, дедуп в команде
     *        не нужен.
     */
    private function postVerdict(
        string $slug,
        array $match,
        array $redFlags,
        array $missing,
        string $summary,
        ?string $nicheStatus,
        ?string $originStatus,
        string $verdict,
        array $links,
    ): void {
        $body = json_encode([
            'slug'           => $slug,
            'identity_match' => $match['identity_match'],
            'control_proof'  => $match['control_proof'],
            'verdict'        => $verdict,
            'evidence'       => $match['evidence'],
            'red_flags'      => $redFlags,
            'missing'        => $missing,
            'summary'        => $summary,
            'niche_status'   => $nicheStatus,
            'origin_status'  => $originStatus,
            'links'          => $links,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->httpClient->request('POST', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/moderation/verdict', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'X-Agent-Token' => (string) $this->apiToken,
                'X-Signature'   => hash_hmac('sha256', $body, (string) $this->apiSecret),
            ],
            'body'    => $body,
            'timeout' => 20,
        ]);
    }
}
