<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModerateTickCommand;
use App\Entity\BrandSourceUrl;
use App\Notification\AdminNotifier;
use App\Service\BrandActionSigner;
use App\Service\BrandSourceFinder;
use App\Service\BraveSearchClient;
use App\Service\Moderation\ApplicationMatcher;
use App\Service\NearDuplicateDetector;
use App\Service\SearxClient;
use App\Service\SearxUnavailableException;
use App\Service\UrlFilter;
use App\Service\WebScraperService;
use App\Service\YandexSearchClient;
use App\Service\YandexSearchMeter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Юнит-тесты на два дефекта первого живого прогона `app:brand:moderate-tick --id=3673`
 * (реальный самрег-бренд «Русский бренд АХ!», ahsilk.ru):
 *
 *  Bug 1 — decideVerdict() отклонял ("reject") настоящий бренд из-за identity_match=no_trace/
 *          unconfirmed, хотя по docs/brand_self_service.md §3 автоотказ допустим ТОЛЬКО
 *          при niche_status=off либо origin_status foreign/unknown.
 *  Bug 2 — сайт не находился, потому что discoverTiered() угадывает домен из слага/названия
 *          (провал для патологических названий), хотя элементарно находится по контактам
 *          заявки (телефон/email).
 *
 * Сеть не дёргаем: YandexSearchClient и WebScraperService — моки. ApplicationMatcher — настоящий
 * (детерминированный, без сети — как и в ApplicationMatcherTest), чтобы discoverByContacts()
 * реально прогонял ту же логику подтверждения, что и боевой путь.
 */
class ModerateTickCommandTest extends TestCase
{
    private function makeCommand(
        ?YandexSearchClient $yandex = null,
        ?WebScraperService $scraper = null,
        ?SearxClient $searx = null,
        ?BraveSearchClient $brave = null,
        ?BrandSourceFinder $sourceFinder = null,
        ?EntityManagerInterface $em = null,
        ?HttpClientInterface $httpClient = null,
        ?AdminNotifier $notifier = null,
        ?YandexSearchMeter $yandexMeter = null,
        ?string $projectDir = null,
    ): ModerateTickCommand {
        return new ModerateTickCommand(
            $httpClient ?? $this->createMock(HttpClientInterface::class),
            $sourceFinder ?? $this->createMock(BrandSourceFinder::class),
            $scraper ?? $this->createMock(WebScraperService::class),
            new ApplicationMatcher(),
            $this->createMock(NearDuplicateDetector::class),
            $yandexMeter ?? $this->createMock(YandexSearchMeter::class),
            $yandex ?? $this->createMock(YandexSearchClient::class),
            // По умолчанию неконфигурированы (пустой URL/ключ) — isConfigured() false,
            // searchAnyEngine молча их пропускает, сеть не дёргается.
            $searx ?? new SearxClient($this->createMock(HttpClientInterface::class), ''),
            $brave ?? new BraveSearchClient($this->createMock(HttpClientInterface::class), ''),
            $notifier ?? $this->createMock(AdminNotifier::class),
            $this->createMock(BrandActionSigner::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
            'https://example.test',
            'token',
            'secret',
            $projectDir ?? sys_get_temp_dir(),
        );
    }

    private function invoke(ModerateTickCommand $command, string $method, array $args): mixed
    {
        return (new \ReflectionMethod($command, $method))->invoke($command, ...$args);
    }

    // ── Bug 1: маппинг вердикта — docs/brand_self_service.md §3 ─────────────────

    #[DataProvider('verdictMatrix')]
    public function testVerdictMapping(string $identity, ?string $niche, ?string $origin, array $redFlags, string $expected): void
    {
        $command = $this->makeCommand();
        $match   = ['identity_match' => $identity, 'control_proof' => 'unconfirmed', 'evidence' => []];

        $this->assertSame($expected, $this->invoke($command, 'decideVerdict', [$match, $redFlags, $niche, $origin]));
    }

    public static function verdictMatrix(): iterable
    {
        // Кейс «АХ!»: сайт не найден (no_trace), ниша/происхождение не зеркалированы на Mac
        // (null — обычное дело для self-reg брендов, см. classifyOnMacIfMirrored) — это НЕ
        // должно быть автоотказом настоящему бренду.
        yield 'no_trace + null niche/origin -> request_changes'      => ['no_trace', null, null, [], 'request_changes'];
        yield 'unconfirmed + null niche/origin -> request_changes'   => ['unconfirmed', null, null, [], 'request_changes'];
        yield 'weak + null niche/origin -> request_changes'          => ['weak', null, null, [], 'request_changes'];
        yield 'confirmed + no flags -> publish'                       => ['confirmed', null, null, [], 'publish'];
        yield 'confirmed + red flags -> request_changes (человек)'   => ['confirmed', null, null, ['no_links'], 'request_changes'];
        yield 'confirmed + niche off -> reject (гейт важнее identity)' => ['confirmed', 'off', null, [], 'reject'];
        yield 'no_trace + niche off -> reject (гейт важнее no_trace)'  => ['no_trace', 'off', null, [], 'reject'];
        yield 'no_trace + origin foreign -> reject'                   => ['no_trace', null, 'foreign', [], 'reject'];
        yield 'no_trace + origin unknown -> reject'                   => ['no_trace', null, 'unknown', [], 'reject'];
        yield 'confirmed + niche in + origin ru -> publish'           => ['confirmed', 'in', 'ru', [], 'publish'];
    }

    /**
     * Инвариант из задачи: `no_trace` никогда не даёт `reject`, пока ниша/происхождение
     * (единственный легитимный источник автоотказа) этого не требуют.
     */
    public function testNoTraceNeverRejectsWithoutNicheOrOriginGate(): void
    {
        $command = $this->makeCommand();
        $match   = ['identity_match' => 'no_trace', 'control_proof' => 'unconfirmed', 'evidence' => []];

        foreach ([null, 'in'] as $niche) {
            foreach ([null, 'ru'] as $origin) {
                $verdict = $this->invoke($command, 'decideVerdict', [$match, [], $niche, $origin]);
                $this->assertNotSame('reject', $verdict, "niche={$niche} origin={$origin} не должны давать reject при no_trace");
            }
        }
    }

    public function testBuildSummaryExplainsNoTraceReason(): void
    {
        $command = $this->makeCommand();
        $match   = ['identity_match' => 'no_trace', 'control_proof' => 'unconfirmed', 'evidence' => []];

        $summary = $this->invoke($command, 'buildSummary', [$match, [], [], 'ниша/происхождение: бренд не зеркалирован на Mac — не определены (этап 2)']);

        $this->assertStringContainsString('нужна ссылка на сайт/соцсеть', $summary);
    }

    // ── Bug 2: поиск кандидатов по контактам заявки (телефон в 3 форматах + email) ──

    public function testDiscoverByContactsSearchesPhoneInThreeFormatsAndEmail(): void
    {
        $seenQueries = [];
        $yandex = $this->createMock(YandexSearchClient::class);
        $yandex->method('isConfigured')->willReturn(true);
        $yandex->method('search')->willReturnCallback(function (string $q) use (&$seenQueries): array {
            $seenQueries[] = $q;

            return []; // ни на одном запросе ничего не находим — должны дойти до конца списка
        });

        $command = $this->makeCommand($yandex);
        $item    = ['phone' => '+7 968 614 6174', 'email' => 'ah.silk@yandex.ru'];

        $result = $this->invoke($command, 'discoverByContacts', [$item]);

        $this->assertNull($result);
        $this->assertSame(
            ['+79686146174', '89686146174', '9686146174', 'ah.silk@yandex.ru'],
            $seenQueries,
        );
    }

    public function testDiscoverByContactsConfirmsCandidateAndStopsEarly(): void
    {
        $html = <<<'HTML'
            <html><head><title>AH Silk</title></head>
            <body><p>Тел: <a href="tel:+79686146174">+7 968 614-61-74</a></p></body></html>
            HTML;

        $calls  = 0;
        $yandex = $this->createMock(YandexSearchClient::class);
        $yandex->method('isConfigured')->willReturn(true);
        $yandex->method('search')->willReturnCallback(function () use (&$calls): array {
            $calls++;

            return [['url' => 'https://ahsilk.ru/', 'title' => 'AH Silk', 'content' => '']];
        });

        $scraper = $this->createMock(WebScraperService::class);
        $scraper->method('fetch')->willReturn(['url' => 'https://ahsilk.ru/', 'httpStatus' => 200, 'html' => $html]);

        $command = $this->makeCommand($yandex, $scraper);
        $item    = ['phone' => '+7 968 614 6174', 'email' => 'ah.silk@yandex.ru'];

        $result = $this->invoke($command, 'discoverByContacts', [$item]);

        $this->assertNotNull($result);
        $this->assertSame('https://ahsilk.ru/', $result->url);
        $this->assertTrue($result->live);
        $this->assertSame(1, $calls, 'первый же запрос (+7…) уже нашёл и подтвердил кандидата — остальные форматы не нужны');
    }

    public function testDiscoverByContactsReturnsNullWithoutPhoneOrEmail(): void
    {
        $yandex = $this->createMock(YandexSearchClient::class);
        $yandex->expects($this->never())->method('search');

        $command = $this->makeCommand($yandex);

        $this->assertNull($this->invoke($command, 'discoverByContacts', [[]]));
    }

    // ── Дешёвые кандидаты-домены (email + название) ──────────────────────────────

    public function testGuessDomainCandidatesFromEmailInRightOrder(): void
    {
        $command = $this->makeCommand();

        // «Русский бренд АХ!» — генерик-филлер («русский», «бренд») отфильтрован,
        // «ах» короче 3 символов — из названия кандидатов нет, только из email.
        $candidates = $this->invoke($command, 'guessDomainCandidates', ['Русский бренд АХ!', 'ah.silk@yandex.ru']);

        $this->assertSame(
            ['https://ahsilk.ru', 'https://ahsilk.com', 'https://ahsilk.store', 'https://ahsilk.shop'],
            $candidates,
        );
    }

    public function testProbeDomainCandidatesRejectsLiveButUnconfirmedCandidate(): void
    {
        // Ловушка ahsilk.com: домен отвечает 200, но это другая компания — ни телефон,
        // ни email, ни название заявителя на странице не встречаются.
        $html = '<html><head><title>AH Silk Co., Ltd — Chinese silk manufacturer</title></head><body>Contact us</body></html>';
        $scraper = $this->createMock(WebScraperService::class);
        $scraper->method('fetch')->willReturnCallback(
            static fn (string $url): array => ['url' => $url, 'httpStatus' => 200, 'html' => $html],
        );

        $command = $this->makeCommand(null, $scraper);
        $item    = ['email' => 'ah.silk@yandex.ru', 'phone' => '+7 968 614 6174'];

        $result = $this->invoke($command, 'probeDomainCandidates', [$item, 'Русский бренд АХ!']);

        $this->assertNull($result, 'кандидат живой, но не подтверждён матчером — должен быть отброшен');
    }

    public function testProbeDomainCandidatesConfirmsByPhoneAndBecomesLiveOwnSite(): void
    {
        $html = <<<'HTML'
            <html><head><title>AH Silk</title></head>
            <body><p>Тел: <a href="tel:+79686146174">+7 968 614-61-74</a></p></body></html>
            HTML;
        $scraper = $this->createMock(WebScraperService::class);
        $scraper->method('fetch')->willReturnCallback(
            static fn (string $url): array => ['url' => $url, 'httpStatus' => 200, 'html' => $html],
        );

        $command = $this->makeCommand(null, $scraper);
        $item    = ['email' => 'ah.silk@yandex.ru', 'phone' => '+7 968 614 6174'];

        $result = $this->invoke($command, 'probeDomainCandidates', [$item, 'Русский бренд АХ!']);

        $this->assertNotNull($result);
        $this->assertSame('https://ahsilk.ru', $result->url);
        $this->assertSame(BrandSourceUrl::TYPE_OWN_SITE, $result->sourceType);
        $this->assertTrue($result->live);

        // Тот же матчер, что использует processItem() — телефон подтверждён, identity не ниже weak.
        $match = (new ApplicationMatcher())->evaluate(
            ['title' => 'Русский бренд АХ!', 'email' => $item['email'], 'phone' => $item['phone']],
            [['url' => $result->url, 'html' => $html]],
            'ahsilk.ru',
            null,
        );
        $this->assertContains($match['identity_match'], ['weak', 'confirmed']);
    }

    // ── Многодвижковый поиск для discoverByContacts ──────────────────────────────

    public function testSearchAnyEngineSurvivesDeadYandexKeyAndUnconfiguredEngines(): void
    {
        // Живой факт 2026-07-30: ключ Yandex Search API мёртв (401), SearXNG/Brave здесь
        // не сконфигурированы — прогон не должен падать, просто вернуть [].
        $yandex = $this->createMock(YandexSearchClient::class);
        $yandex->method('isConfigured')->willReturn(true);
        $yandex->method('search')->willThrowException(new \RuntimeException('401 Unknown api key'));

        $command = $this->makeCommand($yandex);

        $result = $this->invoke($command, 'searchAnyEngine', ['ah.silk@yandex.ru']);

        $this->assertSame([], $result);
    }

    // ── Замыкание конвейера: подтверждённый сайт → payload вердикта (brand_link) ────

    public function testBuildLinksPayloadIncludesConfirmedSiteAsWebsite(): void
    {
        $command = $this->makeCommand();
        $match   = ['identity_match' => 'confirmed', 'control_proof' => 'unconfirmed', 'evidence' => []];
        $pages   = [['url' => 'https://ahsilk.ru/', 'html' => '<html><body>no links here</body></html>']];

        $links = $this->invoke($command, 'buildLinksPayload', [$match, $pages]);

        $this->assertSame([['link_type' => 'website', 'link_url' => 'https://ahsilk.ru/']], $links);
    }

    #[DataProvider('unconfirmedIdentityMatrix')]
    public function testBuildLinksPayloadSkipsUnconfirmedCandidate(string $identity): void
    {
        $command = $this->makeCommand();
        $match   = ['identity_match' => $identity, 'control_proof' => 'unconfirmed', 'evidence' => []];
        $pages   = [['url' => 'https://ahsilk.ru/', 'html' => '<html><body>Instagram: <a href="https://instagram.com/ahsilk">ig</a></body></html>']];

        $this->assertSame([], $this->invoke($command, 'buildLinksPayload', [$match, $pages]));
    }

    public static function unconfirmedIdentityMatrix(): iterable
    {
        yield 'weak'        => ['weak'];
        yield 'unconfirmed' => ['unconfirmed'];
        yield 'no_trace'    => ['no_trace'];
    }

    public function testBuildLinksPayloadIsIdempotentAcrossRepeatedRuns(): void
    {
        $command = $this->makeCommand(null, $this->realScraper());
        $match   = ['identity_match' => 'confirmed', 'control_proof' => 'unconfirmed', 'evidence' => []];
        $pages   = [['url' => 'https://ahsilk.ru/', 'html' => '<html><body><a href="https://vk.com/ahsilk">vk</a></body></html>']];

        $first  = $this->invoke($command, 'buildLinksPayload', [$match, $pages]);
        $second = $this->invoke($command, 'buildLinksPayload', [$match, $pages]);

        $this->assertSame($first, $second, 'команда детерминирована — повторный прогон по тем же страницам не должен менять payload');
    }

    public function testExtractSocialLinksClassifiesAndExcludesSelfAndJobDomains(): void
    {
        $html = <<<'HTML'
            <html><body>
                <a href="https://instagram.com/ahsilk">ig</a>
                <a href="https://vk.com/ahsilk">vk</a>
                <a href="https://t.me/ahsilk">tg</a>
                <a href="https://www.wildberries.ru/brands/ahsilk">wb</a>
                <a href="https://wearbase.ru/ru/brand/ahsilk">self</a>
                <a href="https://hh.ru/vacancy/123">job</a>
                <a href="https://example.com/about">unrelated external</a>
                <a href="https://instagram.com/ahsilk">ig dup</a>
            </body></html>
            HTML;

        $command = $this->makeCommand(null, $this->realScraper());
        $pages   = [['url' => 'https://ahsilk.ru/', 'html' => $html]];

        $links = $this->invoke($command, 'extractSocialLinks', [$pages]);

        $byType = array_column($links, 'link_url', 'link_type');
        $this->assertSame(
            [
                'instagram'   => 'https://instagram.com/ahsilk',
                'vk'          => 'https://vk.com/ahsilk',
                'telegram'    => 'https://t.me/ahsilk',
                'marketplace' => 'https://www.wildberries.ru/brands/ahsilk',
            ],
            $byType,
            'self-домен (wearbase.ru) и job-хост (hh.ru) режутся UrlFilter внутри extractLinks; example.com не соцсеть/маркетплейс — не кандидат; дубль instagram схлопнут',
        );
    }

    public function testExtractSocialLinksCapsAtSix(): void
    {
        $hosts = ['instagram.com/a', 'vk.com/a', 't.me/a', 'youtube.com/a', 'tiktok.com/a', 'ozon.ru/a', 'facebook.com/a'];
        $html  = '<html><body>' . implode('', array_map(static fn (string $h) => "<a href=\"https://{$h}\">l</a>", $hosts)) . '</body></html>';

        $command = $this->makeCommand(null, $this->realScraper());
        $links   = $this->invoke($command, 'extractSocialLinks', [[['url' => 'https://ahsilk.ru/', 'html' => $html]]]);

        $this->assertCount(6, $links, 'кап MAX_SOCIAL_LINKS=6, седьмой (facebook.com) отброшен');
    }

    // ── checklist(): inn/production_place не собираются на этапе самрега — не missing ───

    public function testChecklistNoLongerRequiresInnOrProductionPlace(): void
    {
        $command = $this->makeCommand();
        $item = [
            'logo'               => 'logo.png',
            'has_priced_product' => true,
            'founding_year'      => 2020,
            'description'        => 'Бренд одежды ручной работы',
            'link_count'         => 1,
        ];

        [$missing, ] = $this->invoke($command, 'checklist', [$item]);

        $this->assertSame([], $missing, 'все прочие поля заполнены — missing должен быть пуст, inn/production_place больше не требуются');
    }

    // ── Деградация при недоступном поиске: SearxUnavailableException не роняет анализ ───

    public function testBuildSummaryFlagsSearchDown(): void
    {
        $command = $this->makeCommand();
        $match   = ['identity_match' => 'weak', 'control_proof' => 'unconfirmed', 'evidence' => []];

        $summary = $this->invoke($command, 'buildSummary', [$match, [], [], '', true]);

        $this->assertStringContainsString('поиск недоступен', $summary);
    }

    public function testBuildTgTextFlagsSearchDown(): void
    {
        $command = $this->makeCommand();
        $match   = ['identity_match' => 'weak', 'control_proof' => 'unconfirmed', 'evidence' => []];

        $tgText = $this->invoke($command, 'buildTgText', ['Бренд', $match, [], [], 'summary', true]);

        $this->assertStringContainsString('поиск недоступен', $tgText);
    }

    public function testProcessItemPostsVerdictViaProbeWhenDiscoverTieredThrows(): void
    {
        $html = <<<'HTML'
            <html><head><title>AH Silk</title></head>
            <body><p>Тел: <a href="tel:+79686146174">+7 968 614-61-74</a></p></body></html>
            HTML;

        $sourceFinder = $this->createMock(BrandSourceFinder::class);
        $sourceFinder->method('discoverTiered')->willThrowException(new SearxUnavailableException('SearXNG down'));

        $scraper = $this->createMock(WebScraperService::class);
        $scraper->method('fetch')->willReturnCallback(
            static fn (string $url): array => ['url' => $url, 'httpStatus' => 200, 'html' => $html],
        );
        $scraper->method('discoverSitePages')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchAssociative')->willReturn(false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $capturedBody = null;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())->method('request')->willReturnCallback(
            function (string $method, string $url, array $options = []) use (&$capturedBody) {
                $capturedBody = $options['body'] ?? null;

                return $this->createMock(ResponseInterface::class);
            },
        );

        $command = $this->makeCommand(null, $scraper, null, null, $sourceFinder, $em, $httpClient);
        $item = ['title' => 'Русский бренд АХ!', 'email' => 'ah.silk@yandex.ru', 'phone' => '+7 968 614 6174', 'brand_id' => 123];

        $io     = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $result = $this->invoke($command, 'processItem', [$item, $io, false]);

        $this->assertTrue($result, 'вердикт должен постится даже когда discoverTiered упал по недоступности поиска');
        $this->assertNotNull($capturedBody, 'постVerdict должен был улететь на прод');

        $payload = json_decode((string) $capturedBody, true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('поиск недоступен', $payload['summary']);
    }

    // ── Алертинг простоя: очередь непуста, вердиктов 0 → FAILURE + троттлированный TG ──

    public function testExecuteFailsAndAlertsWhenQueueNonEmptyButZeroVerdictsPosted(): void
    {
        $projectDir = sys_get_temp_dir() . '/moderate_tick_test_' . uniqid('', true);
        mkdir($projectDir . '/var', 0777, true);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['items' => [
            ['title' => 'A', 'slug' => 'a', 'analyze_attempts' => 3],
            ['title' => 'B', 'slug' => 'b', 'analyze_attempts' => 3],
        ]]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())->method('request')->willReturn($response);

        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('isEnabled')->willReturn(true);
        $notifier->expects($this->once())->method('send');

        $yandexMeter = $this->createMock(YandexSearchMeter::class);
        $yandexMeter->method('allowed')->willReturn(true);

        $command = $this->makeCommand(
            httpClient: $httpClient,
            notifier: $notifier,
            yandexMeter: $yandexMeter,
            projectDir: $projectDir,
        );

        $tester = new CommandTester($command);
        $exit   = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exit, 'оба элемента очереди упёрлись в лимит попыток — 0 вердиктов должны давать красный exit');
    }

    public function testStalledAlertIsThrottledOncePerDayButStillFails(): void
    {
        $projectDir = sys_get_temp_dir() . '/moderate_tick_test_' . uniqid('', true);
        mkdir($projectDir . '/var', 0777, true);
        $today = (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        file_put_contents($projectDir . '/var/moderate_tick_state.json', json_encode(['alerted_on' => $today]));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['items' => [
            ['title' => 'A', 'slug' => 'a', 'analyze_attempts' => 3],
        ]]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())->method('request')->willReturn($response);

        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('isEnabled')->willReturn(true);
        $notifier->expects($this->never())->method('send');

        $yandexMeter = $this->createMock(YandexSearchMeter::class);
        $yandexMeter->method('allowed')->willReturn(true);

        $command = $this->makeCommand(
            httpClient: $httpClient,
            notifier: $notifier,
            yandexMeter: $yandexMeter,
            projectDir: $projectDir,
        );

        $tester = new CommandTester($command);
        $exit   = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exit, 'троттлится только TG, exit код остаётся красным «пока не починят»');
    }

    private function realScraper(): WebScraperService
    {
        return new WebScraperService($this->createMock(HttpClientInterface::class), new UrlFilter(''));
    }
}
