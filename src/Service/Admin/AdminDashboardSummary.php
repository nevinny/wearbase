<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Controller\Admin\BrandCrudController;
use App\Controller\Admin\CityHubCrudController;
use App\Controller\Admin\ScheduledCommandCrudController;
use App\Repository\BrandClaimRepository;
use App\Repository\BrandModerationRepository;
use App\Repository\BrandRepository;
use App\Repository\CityHubRepository;
use App\Repository\PipelineQueueRepository;
use App\Repository\ScheduledCommandRepository;
use App\Repository\SellerPaymentAccountRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Собирает плитки главной страницы /admin: то, что владелец должен увидеть за день без
 * обхода десятка разделов (см. tasktracker). Каждая плитка строится независимо и
 * fail-soft — исключение/отсутствующая таблица не должны ронять всю страницу
 * (часть таблиц молодая, появляется миграциями из параллельных веток, а прод-БД
 * и dev-БД — разные окружения).
 *
 * Числа очередей RAG-конвейера идут ТОЛЬКО через PipelineQueueRepository/BrandRepository —
 * не копируем raw-SQL предикаты повторно (комментарии в PipelineQueueRepository объясняют,
 * почему копии уже расходились).
 */
class AdminDashboardSummary
{
    /**
     * Порог «просрочено» для заявок/премодерации — тот же, что ModerationTimeoutsCommand
     * (REVIEWED_REMINDER_AFTER / CLAIM_OVERDUE_AFTER = -2 days, QUEUED_STALLED_AFTER = -48h).
     */
    private const OVERDUE_DAYS = 2;

    /** Порог «протухли данные» для GSC/Яндекс — из формулировки задачи (см. PR admin-dashboard). */
    private const FRESHNESS_STALE_DAYS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly BrandModerationRepository $moderation,
        private readonly BrandClaimRepository $claims,
        private readonly PipelineQueueRepository $pipelineQueue,
        private readonly BrandRepository $brands,
        private readonly CityHubRepository $cityHubs,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SellerPaymentAccountRepository $paymentAccounts,
        private readonly ScheduledCommandRepository $scheduledCommands,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function build(): array
    {
        return [
            'moderation'  => $this->moderationTile(),
            'publications' => $this->publicationsTile(),
            'rag'         => $this->ragTile(),
            'quality'     => $this->qualityTile(),
            'freshness'   => $this->freshnessTile(),
            'closedLoop'  => $this->closedLoopTile(),
            'geoHubs'     => $this->geoHubsTile(),
            'money'       => $this->moneyTile(),
        ];
    }

    private function daysSince(?\DateTimeInterface $at): ?int
    {
        return $at !== null ? (int) floor((time() - $at->getTimestamp()) / 86400) : null;
    }

    // ------------------------------------------------------------------
    // 1. Модерация и заявки
    // ------------------------------------------------------------------
    private function moderationTile(): array
    {
        try {
            $snap   = $this->moderation->dashboardSnapshot();
            $claims = $this->claims->dashboardSnapshot();
            $queuedDays = $this->daysSince($snap['oldestQueuedAt']);
            $claimsDays = $this->daysSince($claims['oldestAt']);

            return [
                'available'        => true,
                'queued'           => $snap['queued'],
                'queuedOverdue'    => $queuedDays !== null && $queuedDays >= self::OVERDUE_DAYS,
                'reviewedAwaiting' => $snap['reviewedAwaiting'],
                'redFlagged'       => $snap['redFlagged'],
                'claimsPending'    => $claims['pending'],
                'claimsOverdue'    => $claimsDays !== null && $claimsDays >= self::OVERDUE_DAYS,
                'link'             => $this->router->generate('admin_brand_claims'),
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    // ------------------------------------------------------------------
    // 2. Публикации
    // ------------------------------------------------------------------
    private function publicationsTile(): array
    {
        try {
            // «Опубликовано» = публично доступно ПРЯМО СЕЙЧАС — тот же предикат, что каталог
            // (BrandRepository::countPubliclyVisible), а НЕ published_at IS NOT NULL: тот
            // одновременно недосчитывает легаси (status=active без published_at, залиты до
            // дрипа) и досчитывает снятые с публикации (status=disabled, published_at остался).
            $publishedTotal = $this->brands->countPubliclyVisible();
            $since7d = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');
            $published7d = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM brand WHERE published_at >= ?',
                [$since7d],
            );
            $lastPublishedAt = $this->db->fetchOne('SELECT MAX(published_at) FROM brand') ?: null;
            $lastPublishedDays = $lastPublishedAt !== null ? $this->daysSince(new \DateTimeImmutable((string) $lastPublishedAt)) : null;
            // Отдельные сигналы, которых раньше не было видно вообще:
            $unpublished = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM brand WHERE status = 'disabled' AND published_at IS NOT NULL",
            );
            $legacyNoDate = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM brand WHERE status = 'active' AND published_at IS NULL",
            );

            return [
                'available'         => true,
                'publishedTotal'    => $publishedTotal,
                'published7d'       => $published7d,
                'unpublished'       => $unpublished,
                'legacyNoDate'      => $legacyNoDate,
                'lastPublishedAt'   => $lastPublishedAt,
                'lastPublishedStale' => $lastPublishedDays !== null && $lastPublishedDays >= self::OVERDUE_DAYS,
                // Реально НОВЫЕ карточки (никогда не пушенные) — не смешивать с re-push уже
                // опубликованных (см. countRePushPending); именно эта цифра падает в ноль,
                // когда дрип новых карточек встал, пока re-push его маскирует.
                'readyNeverPushed'  => $this->pipelineQueue->countNeverPushed(),
                'readyRePush'       => $this->pipelineQueue->countRePushPending(),
                // Очередь дрип-крона (app:brand:publish-tick) — ТОТ ЖЕ предикат, что
                // BrandRepository::findDripCandidateIds (с niche/origin-гейтами), а НЕ
                // queue_pending из /api/v1/publish-stats (тот без гейтов и раздут мусором).
                'dripQueue'         => $this->brands->countDripCandidates(),
                'link'              => $this->adminUrlGenerator->setController(BrandCrudController::class)->generateUrl(),
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    // ------------------------------------------------------------------
    // 3. RAG-конвейер (остатки очередей — только через PipelineQueueRepository/BrandRepository)
    // ------------------------------------------------------------------
    private function ragTile(): array
    {
        try {
            return [
                'available'       => true,
                'awaitingDiscover' => $this->pipelineQueue->countAwaitingDiscover(),
                'awaitingFetch'    => $this->pipelineQueue->countAwaitingFetch(),
                'awaitingEmbed'    => $this->pipelineQueue->countByStatus(\App\Entity\BrandRagPipeline::STATUS_SCRAPED),
                'awaitingGenerate' => $this->pipelineQueue->countByStatus(\App\Entity\BrandRagPipeline::STATUS_EMBEDDED),
                'awaitingKeywords' => $this->pipelineQueue->countForKeywords(),
                'deferred'         => $this->pipelineQueue->countByStatus(\App\Entity\BrandRagPipeline::STATUS_DEFERRED),
                'generateFailed'   => $this->pipelineQueue->countByStatus(\App\Entity\BrandRagPipeline::STATUS_GENERATE_FAILED),
                'link'             => $this->router->generate('admin_rag'),
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    // ------------------------------------------------------------------
    // 4. Качество каталога (один запрос — только опубликованные, status='active')
    // ------------------------------------------------------------------
    private function qualityTile(): array
    {
        try {
            $row = $this->db->fetchAssociative(
                "SELECT
                    COUNT(*) AS total,
                    SUM((email IS NULL OR email = '') AND (phone IS NULL OR phone = '')) AS no_contacts,
                    SUM(logo IS NULL OR logo = '') AS no_logo,
                    SUM(description IS NULL OR description = '') AS no_description,
                    SUM(niche_status = 'off') AS niche_off,
                    SUM(origin_status IN ('foreign', 'unknown')) AS origin_bad
                 FROM brand WHERE status = 'active'",
            ) ?: [];

            return [
                'available'    => true,
                'total'        => (int) ($row['total'] ?? 0),
                'noContacts'   => (int) ($row['no_contacts'] ?? 0),
                'noLogo'       => (int) ($row['no_logo'] ?? 0),
                'noDescription' => (int) ($row['no_description'] ?? 0),
                'nicheOff'     => (int) ($row['niche_off'] ?? 0),
                'originBad'    => (int) ($row['origin_bad'] ?? 0),
                'link'         => $this->adminUrlGenerator->setController(BrandCrudController::class)->generateUrl(),
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    // ------------------------------------------------------------------
    // 5. Свежесть данных
    // ------------------------------------------------------------------
    private function freshnessTile(): array
    {
        $out = ['available' => true];

        try {
            $out['gscLastDay'] = $this->db->fetchOne('SELECT MAX(day) FROM gsc_page_stats') ?: null;
        } catch (\Throwable) {
            $out['gscLastDay'] = null; // таблица только на проде/после синка
        }
        $out['gscStale'] = $this->isStale($out['gscLastDay']);

        try {
            $out['yandexLastCheck'] = $this->db->fetchOne('SELECT MAX(last_checked_at) FROM yandex_index_status') ?: null;
        } catch (\Throwable) {
            $out['yandexLastCheck'] = null;
        }
        $out['yandexStale'] = $this->isStale($out['yandexLastCheck']);

        try {
            $failing = $this->scheduledCommands->findFailing();
            $out['failingCrons'] = array_map(static fn ($c) => $c->getName(), $failing);
            $out['link'] = $this->adminUrlGenerator->setController(ScheduledCommandCrudController::class)->generateUrl();
        } catch (\Throwable) {
            $out['failingCrons'] = [];
        }

        return $out;
    }

    private function isStale(?string $dateStr): bool
    {
        if ($dateStr === null || trim($dateStr) === '') {
            return false; // нет данных вообще — отдельный сигнал, не «протухло»
        }
        $days = $this->daysSince(new \DateTimeImmutable($dateStr));

        return $days !== null && $days >= self::FRESHNESS_STALE_DAYS;
    }

    // ------------------------------------------------------------------
    // 6. Closed-loop (brand_content_revision + защищённо city_hub_revision)
    // ------------------------------------------------------------------
    private function closedLoopTile(): array
    {
        $out = ['available' => true];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $row = $this->db->fetchAssociative(
                "SELECT
                    SUM(verdict = 'pending') AS pending,
                    SUM(verdict = 'pending' AND measure_after IS NOT NULL AND measure_after < ?) AS expired
                 FROM brand_content_revision",
                [$now],
            ) ?: [];
            $out['brandPending'] = (int) ($row['pending'] ?? 0);
            $out['brandExpired'] = (int) ($row['expired'] ?? 0);
        } catch (\Throwable) {
            $out['brandPending'] = null;
            $out['brandExpired'] = null;
        }

        // city_hub_revision появляется миграцией из другой ветки (feat/city-hub-generator) —
        // здесь нет Entity-класса, поэтому только сырой SQL, и только если таблица есть.
        try {
            $row = $this->db->fetchAssociative(
                "SELECT
                    SUM(verdict = 'pending') AS pending,
                    SUM(verdict = 'pending' AND measure_after IS NOT NULL AND measure_after < ?) AS expired
                 FROM city_hub_revision",
                [$now],
            ) ?: [];
            $out['cityPending'] = (int) ($row['pending'] ?? 0);
            $out['cityExpired'] = (int) ($row['expired'] ?? 0);
        } catch (\Throwable) {
            $out['cityPending'] = null; // таблицы нет — блок просто не покажется (шаблон это учитывает)
            $out['cityExpired'] = null;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 7. Гео-хабы
    // ------------------------------------------------------------------
    private function geoHubsTile(): array
    {
        try {
            $curated = $this->cityHubs->count(['isActive' => true]);
            $totalCities = (int) $this->db->fetchOne(
                "SELECT COUNT(DISTINCT city) FROM brand WHERE status = 'active' AND city IS NOT NULL AND city != ''",
            );

            return [
                'available'   => true,
                'curated'     => $curated,
                'totalCities' => $totalCities,
                'link'        => $this->adminUrlGenerator->setController(CityHubCrudController::class)->generateUrl(),
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    // ------------------------------------------------------------------
    // 8. Деньги/подписки
    // ------------------------------------------------------------------
    private function moneyTile(): array
    {
        try {
            return [
                'available'      => true,
                'subsByTariff'   => $this->subscriptions->countActiveGroupedByTariff(),
                'saleGated'      => $this->paymentAccounts->countSaleGatedBrands(),
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }
}
