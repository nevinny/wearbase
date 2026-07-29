<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandSourceUrl;
use App\Notification\AdminNotifier;
use App\Service\BrandActionSigner;
use App\Service\BrandSourceFinder;
use App\Service\Discovery\DiscoveredUrl;
use App\Service\Moderation\ApplicationMatcher;
use App\Service\NearDuplicateDetector;
use App\Service\WebScraperService;
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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BrandSourceFinder $sourceFinder,
        private readonly WebScraperService $scraper,
        private readonly ApplicationMatcher $matcher,
        private readonly NearDuplicateDetector $dup,
        private readonly YandexSearchMeter $yandexMeter,
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

        [$pages, $officialDomain, $siteFlags] = $this->collectPages($ownSite);

        $match = $this->matcher->evaluate(
            ['title' => $title, 'email' => $item['email'] ?? null, 'phone' => $item['phone'] ?? null, 'address' => $item['address'] ?? null],
            $pages,
            $officialDomain,
            $item['owner_email'] ?? null,
        );

        [$missing, $checklistFlags] = $this->checklist($item);
        $redFlags = array_merge($siteFlags, $checklistFlags, $this->duplicateFlags($title, $ownSite));

        [$nicheStatus, $originStatus, $note] = $this->classifyOnMacIfMirrored($slug);
        $verdict = $this->decideVerdict($match, $redFlags);
        $summary = $this->buildSummary($match, $missing, $redFlags, $note);

        $io->text(sprintf('  identity=%s control=%s verdict=%s', $match['identity_match'], $match['control_proof'], $verdict));
        if ($redFlags !== []) {
            $io->text('  🚩 ' . implode('; ', $redFlags));
        }
        if ($missing !== []) {
            $io->text('  missing: ' . implode(',', $missing));
        }

        $tgText = $this->buildTgText($title, $match, $missing, $redFlags, $summary);

        if ($dryRun) {
            $io->text('--- TG (dry-run, не отправлено) ---');
            $io->text($tgText);
            return;
        }

        $this->postVerdict($slug, $match, $redFlags, $missing, $summary, $nicheStatus, $originStatus, $verdict);

        try {
            $this->notifier->sendWithButtons($tgText, $this->signedButtons((int) $item['brand_id']));
        } catch (\Throwable $e) {
            $io->warning('TG не отправлен: ' . $e->getMessage());
        }
    }

    /** @return array{0:array<int,array{url:string,html:string}>,1:?string,2:string[]} */
    private function collectPages(?DiscoveredUrl $ownSite): array
    {
        if ($ownSite === null) {
            return [[], null, []];
        }

        $main = $this->scraper->fetch($ownSite->url);
        if ($main === null || $main['html'] === '') {
            return [[], null, ['site_unreachable:' . $ownSite->url]];
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

    /** @param array{identity_match:string,control_proof:string,evidence:mixed} $match @param string[] $redFlags */
    private function decideVerdict(array $match, array $redFlags): string
    {
        if (in_array($match['identity_match'], ['no_trace', 'unconfirmed'], true)) {
            return 'reject';
        }
        if ($redFlags !== [] || $match['identity_match'] === 'weak') {
            return 'request_changes';
        }

        return 'publish';
    }

    /** @param string[] $missing @param string[] $redFlags */
    private function buildSummary(array $match, array $missing, array $redFlags, string $note): string
    {
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

    /** @param string[] $redFlags @param string[] $missing */
    private function postVerdict(
        string $slug,
        array $match,
        array $redFlags,
        array $missing,
        string $summary,
        ?string $nicheStatus,
        ?string $originStatus,
        string $verdict,
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
