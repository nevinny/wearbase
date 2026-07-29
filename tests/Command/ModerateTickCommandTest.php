<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModerateTickCommand;
use App\Notification\AdminNotifier;
use App\Service\BrandActionSigner;
use App\Service\BrandSourceFinder;
use App\Service\Moderation\ApplicationMatcher;
use App\Service\NearDuplicateDetector;
use App\Service\WebScraperService;
use App\Service\YandexSearchClient;
use App\Service\YandexSearchMeter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
    ): ModerateTickCommand {
        return new ModerateTickCommand(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(BrandSourceFinder::class),
            $scraper ?? $this->createMock(WebScraperService::class),
            new ApplicationMatcher(),
            $this->createMock(NearDuplicateDetector::class),
            $this->createMock(YandexSearchMeter::class),
            $yandex ?? $this->createMock(YandexSearchClient::class),
            $this->createMock(AdminNotifier::class),
            $this->createMock(BrandActionSigner::class),
            $this->createMock(EntityManagerInterface::class),
            'https://example.test',
            'token',
            'secret',
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
}
