<?php

declare(strict_types=1);

namespace App\Service\Advisor;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Детерминированный сбор текущего KPI-вектора проекта (docs/advisor.md, шаг 1 цикла).
 * БЕЗ LLM — только чтения из уже существующих источников (те же таблицы/эндпоинты, что
 * читают DailyReportCommand и EvaluateExperimentsCommand). Каждый источник best-effort:
 * если недоступен (нет прод-API, синк не залил данные) — метрика просто пропускается,
 * ничего не выдумываем. Возвращает плоский массив metric=>число для StateSnapshot::metrics.
 */
final class SignalCollector
{
    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl = null,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken = null,
    ) {
    }

    /** @return array<string, int|float> metric => число */
    public function collect(): array
    {
        $m = [];

        // --- ФАБРИКА КОНТЕНТА (локальная dev-БД, НЕ живой каталог) ---
        // Каталожные метрики брендов (active/new/disabled/published) берём с ПРОДА ниже:
        // dev — это фабрика (тысячи сырых лидов в 'new'), выдавать их за каталог = путаница.
        // Здесь — только фабричные показатели: контакт-воронка enrichment, стадии RAG,
        // собранные ключевики. Префикс factory_ явно помечает «фабрика (dev)».
        $contacts = $this->row(
            "SELECT
               SUM(contact_status = 'enriched')                   AS enriched,
               SUM(contact_status = 'partial')                    AS partial,
               SUM(contact_status = 'not_found')                  AS not_found,
               SUM((email IS NOT NULL AND email != '')
                   AND status IN ('active','new'))                AS with_email
             FROM brand"
        );
        if ($contacts !== null) {
            $m['factory_contacts_enriched']  = (int) $contacts['enriched'];
            $m['factory_contacts_partial']   = (int) $contacts['partial'];
            $m['factory_contacts_not_found'] = (int) $contacts['not_found'];
            $m['factory_contacts_email']     = (int) $contacts['with_email'];
        }

        // --- Гистограмма стадий RAG-конвейера (brand_rag_pipeline) — фабрика ---
        foreach ($this->all('SELECT status, COUNT(*) c FROM brand_rag_pipeline GROUP BY status') as $r) {
            $m['factory_pipeline_' . $r['status']] = (int) $r['c'];
        }

        // --- Ключевики (brand_keyword) — фабрика ---
        $kw = $this->one('SELECT COUNT(*) FROM brand_keyword');
        if ($kw !== null) {
            $m['factory_keywords_total'] = (int) $kw;
        }

        // --- Яндекс.Вебмастер: индекс + запросы (локальная БД, синк крон Mac) ---
        $yaIdx = $this->row('SELECT COALESCE(SUM(in_search),0) in_search, COUNT(*) checked FROM yandex_index_status');
        if ($yaIdx !== null && (int) $yaIdx['checked'] > 0) {
            $m['yandex_in_search'] = (int) $yaIdx['in_search'];
        }
        $yaQ = $this->row(
            "SELECT COUNT(*) c, COALESCE(SUM(shows),0) shows, COALESCE(SUM(clicks),0) clicks
             FROM yandex_query_stats WHERE date_to = (SELECT MAX(date_to) FROM yandex_query_stats)"
        );
        if ($yaQ !== null && (int) $yaQ['c'] > 0) {
            $m['yandex_queries'] = (int) $yaQ['c'];
            $m['yandex_shows']   = (int) $yaQ['shows'];
            $m['yandex_clicks']  = (int) $yaQ['clicks'];
        }

        // --- GSC: индексация (локальная БД, синк крон .43) ---
        $gsc = $this->row(
            'SELECT COUNT(*) checked, COALESCE(SUM(indexed),0) indexed, COUNT(first_indexed_at) ever FROM gsc_index_status'
        );
        if ($gsc !== null && (int) $gsc['checked'] > 0) {
            $m['gsc_checked'] = (int) $gsc['checked'];
            $m['gsc_indexed'] = (int) $gsc['indexed'];
            $m['gsc_ever']    = (int) $gsc['ever'];
        }

        // --- Живой бизнес с ПРОДА (агент-API; реальные юзеры/подписки/лиды/заказы там) ---
        // Локальная dev-БД содержит только тестовые subscription/landing_lead/newsletter
        // (подписки=1, лиды=1) — не бизнес. Публикации/очередь дрипа тоже живут на проде.
        // Источник — /api/v1/publish-stats. Недоступен ИЛИ поля ещё нет (прод не задеплоен)
        // → метрика просто отсутствует, ничего не выдумываем.
        $m += $this->collectProdBusinessStats();

        return $m;
    }

    /** @return array<string, int> */
    private function collectProdBusinessStats(): array
    {
        if (trim((string) $this->prodApiUrl) === '') {
            return [];
        }
        try {
            $d = $this->httpClient->request(
                'GET',
                rtrim((string) $this->prodApiUrl, '/') . '/api/v1/publish-stats',
                ['headers' => ['X-Agent-Token' => (string) $this->agentToken], 'timeout' => 8],
            )->toArray(false);
        } catch (\Throwable) {
            return []; // прод недоступен — метрики просто отсутствуют
        }

        $out = [];

        // Живой КАТАЛОГ (headline советника): бренды по статусам с ПРОДА.
        // active/new/disabled — из GROUP BY status нового эндпоинта (после деплоя прода).
        // brands_active до деплоя падает на active_total (уже есть в старом контракте),
        // brands_published — из published_total (published_at IS NOT NULL, стабильное поле).
        if (isset($d['brands_active']) && is_numeric($d['brands_active'])) {
            $out['brands_active'] = (int) $d['brands_active'];
        } elseif (isset($d['active_total']) && is_numeric($d['active_total'])) {
            $out['brands_active'] = (int) $d['active_total'];
        }
        foreach (['brands_new', 'brands_disabled'] as $k) {
            if (isset($d[$k]) && is_numeric($d[$k])) {
                $out[$k] = (int) $d[$k];
            }
        }
        if (isset($d['published_total']) && is_numeric($d['published_total'])) {
            $out['brands_published'] = (int) $d['published_total'];
        }

        // Публикации/очередь дрипа (стабильный контракт, префикс prod_ как раньше).
        foreach (['published_total', 'published_today', 'published_yesterday', 'queue_pending'] as $k) {
            if (isset($d[$k]) && is_numeric($d[$k])) {
                $out['prod_' . $k] = (int) $d[$k];
            }
        }
        // Живой бизнес: подписки по статусам, лиды, заказы. Имена уже осмысленные —
        // переносим как есть. Появятся только после деплоя прода с новым эндпоинтом.
        foreach ($d as $k => $v) {
            if (is_numeric($v) && (
                str_starts_with($k, 'subscriptions_')
                || str_starts_with($k, 'leads_')
                || str_starts_with($k, 'orders_')
            )) {
                $out[$k] = (int) $v;
            }
        }

        return $out;
    }

    private function one(string $sql): ?string
    {
        try {
            $v = $this->db->fetchOne($sql);
            return $v === false ? null : (string) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function row(string $sql): ?array
    {
        try {
            $r = $this->db->fetchAssociative($sql);
            return $r === false ? null : $r;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql): array
    {
        try {
            return $this->db->fetchAllAssociative($sql);
        } catch (\Throwable) {
            return [];
        }
    }
}
