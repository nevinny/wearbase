<?php

namespace App\Command;

use App\Notification\AdminNotifier;
use App\Service\SecretCipher;
use App\Service\Seo\AioQueryClassifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ежедневный дайджест в Telegram: публикации на проде + индексация GSC.
 * ⚠️ Запускать ТОЛЬКО с Mac — Telegram заблокирован и с .43, и с прода regru.
 * Публикации тянем с прода по агент-API (/api/v1/publish-stats); GSC — из локальной БД
 * (синк делает крон на .43, данные ложатся сюда). Для крона Mac: 17 9 * * *
 *
 *   php bin/console app:report:daily            # снять + отправить в TG
 *   php bin/console app:report:daily --stdout-only
 */
#[AsCommand(name: 'app:report:daily', description: 'Ежедневный дайджест (публикации прода + GSC + Яндекс) в Telegram — запускать с Mac')]
class DailyReportCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
        private readonly HttpClientInterface $httpClient,
        private readonly SecretCipher $cipher,
        private readonly AioQueryClassifier $aio,
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
        #[Autowire('%env(default::YANDEX_PARTNER_TOKEN)%')]
        private readonly ?string $rsyaToken,
        #[Autowire('%env(default::YANDEX_METRIKA_TOKEN)%')]
        private readonly ?string $metrikaToken,
        #[Autowire('%env(default::YANDEX_METRIKA_COUNTER)%')]
        private readonly ?string $metrikaCounter,
    ) {
        parent::__construct();
    }

    /**
     * VK-сообщество (groups.getById, community-токен): подписчики. stats.get (охваты/реакции)
     * недоступен community-токену (error_code 27, group auth) — не запрашиваем.
     * Возвращает ['members','posts_total'] или null (нет канала/токена/ошибка API).
     */
    private function fetchVkStats(): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT id, target, token_enc FROM social_channel WHERE platform='vk' AND enabled=1 LIMIT 1",
        );
        if (!$row || !$row['token_enc']) {
            return null;
        }
        try {
            $token = $this->cipher->decrypt($row['token_enc']);
            $groupId = ltrim((string) $row['target'], '-');
            $resp = $this->httpClient->request('GET', 'https://api.vk.com/method/groups.getById', [
                'query'   => ['group_id' => $groupId, 'fields' => 'members_count', 'access_token' => $token, 'v' => '5.199'],
                'timeout' => 10,
            ])->toArray(false);
            $members = $resp['response']['groups'][0]['members_count'] ?? null;
            if ($members === null) {
                return null;
            }
            $postsTotal = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM social_post WHERE channel_id = ? AND status = 'published'",
                [$row['id']],
            );

            return ['members' => (int) $members, 'posts_total' => $postsTotal];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * IG (Instagram Login токен, graph.instagram.com): подписчики, число публикаций,
     * охват (account reach, скользящие 28 дней) и сумма лайков/комментов по нашим постам
     * (последние 30, каждый — отдельный запрос). Охват/лайки — best-effort (null при сбое),
     * подписчики/медиа обязательны, иначе null.
     */
    private function fetchIgStats(): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT id, target, token_enc FROM social_channel WHERE platform='ig' AND enabled=1 LIMIT 1",
        );
        if (!$row || !$row['token_enc']) {
            return null;
        }
        try {
            $token = $this->cipher->decrypt($row['token_enc']);
            $igId = (string) $row['target'];
            $resp = $this->httpClient->request('GET', "https://graph.instagram.com/v22.0/{$igId}", [
                'query'   => ['fields' => 'followers_count,media_count', 'access_token' => $token],
                'timeout' => 10,
            ])->toArray(false);
            if (!isset($resp['followers_count'], $resp['media_count'])) {
                return null;
            }
            $out = [
                'followers' => (int) $resp['followers_count'],
                'media'     => (int) $resp['media_count'],
                'reach'     => null,
                'likes'     => null,
                'comments'  => null,
            ];

            // Охват аккаунта (unique reach, скользящие 28 дней) — один запрос.
            try {
                $r = $this->httpClient->request('GET', "https://graph.instagram.com/v22.0/{$igId}/insights", [
                    'query'   => ['metric' => 'reach', 'period' => 'days_28', 'metric_type' => 'total_value', 'access_token' => $token],
                    'timeout' => 10,
                ])->toArray(false);
                $out['reach'] = (int) ($r['data'][0]['total_value']['value'] ?? 0);
            } catch (\Throwable) {
                // охват недоступен — оставляем null
            }

            // Лайки/комменты по нашим опубликованным постам (кап 30 последних).
            try {
                $ids = $this->db->fetchFirstColumn(
                    "SELECT external_id FROM social_post WHERE channel_id = " . (int) $row['id'] . " AND status = 'published' AND external_id IS NOT NULL ORDER BY published_at DESC LIMIT 30",
                );
                $likes = 0;
                $comments = 0;
                foreach ($ids as $mid) {
                    $m = $this->httpClient->request('GET', "https://graph.instagram.com/v22.0/{$mid}", [
                        'query'   => ['fields' => 'like_count,comments_count', 'access_token' => $token],
                        'timeout' => 10,
                    ])->toArray(false);
                    $likes += (int) ($m['like_count'] ?? 0);
                    $comments += (int) ($m['comments_count'] ?? 0);
                }
                $out['likes'] = $likes;
                $out['comments'] = $comments;
            } catch (\Throwable) {
                // лайки недоступны — оставляем null
            }

            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Дзен: нативной статистики (подписчики/дочитывания) в публичном API нет (студия за паспортом).
     * Реальная ценность канала — переходы с dzen.ru на сайт: тянем из Яндекс.Метрики (за 7 дней).
     * При отсутствии токена/сбое — fallback: число статей в Дзен-фиде (что отдаём в /rss/dzen.xml).
     */
    private function fetchDzenStats(): array
    {
        $referral = $this->fetchDzenReferralMetrika();
        if ($referral !== null) {
            return $referral;
        }
        $articlesInFeed = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM article_distribution WHERE platform = 'dzen' AND is_current = 1",
        );

        return ['articles_in_feed' => $articlesInFeed];
    }

    /**
     * Переходы с Дзена на сайт за 7 дней через Яндекс.Метрику (визиты/посетители).
     * Фильтр по домену-рефереру dzen.ru; totals = [visits, users]. null при отсутствии токена/ошибке.
     */
    private function fetchDzenReferralMetrika(): ?array
    {
        if (trim((string) $this->metrikaToken) === '') {
            return null;
        }
        try {
            $counter = trim((string) $this->metrikaCounter) !== '' ? $this->metrikaCounter : '105219484';
            $qs = http_build_query([
                'ids'     => $counter,
                'metrics' => 'ym:s:visits,ym:s:users',
                'filters' => "ym:s:refererDomain=='dzen.ru'",
                'date1'   => '7daysAgo',
                'date2'   => 'yesterday',
            ]);
            $data = $this->httpClient->request('GET', 'https://api-metrika.yandex.net/stat/v1/data?' . $qs, [
                'headers' => ['Authorization' => 'OAuth ' . $this->metrikaToken],
                'timeout' => 15,
            ])->toArray(false);
            $totals = $data['totals'] ?? null;
            if (!is_array($totals) || count($totals) < 2) {
                return null;
            }

            return ['visits' => (int) round((float) $totals[0]), 'users' => (int) round((float) $totals[1])];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Доход/показы РСЯ за вчера через Partner Statistics API.
     * Возвращает ['revenue','impressions','clicks','ecpm'] или null (токен пуст / ошибка / прод-блок).
     * Ответ statistics2: data.totals[<currency_id>][0] — ассоц. по имени поля; RUB = id "2".
     * partner_wo_nds — вознаграждение партнёра без НДС (доход).
     */
    private function fetchRsyaYesterday(): ?array
    {
        if (trim((string) $this->rsyaToken) === '') {
            return null;
        }
        try {
            // API ждёт повторяющийся ?field=…&field=…; Symfony query-массив дал бы field[0]= → 400.
            // Поэтому query-строку собираем сами.
            $fields = ['impressions', 'clicks', 'partner_wo_nds', 'ecpm_partner_wo_nds'];
            $qs = 'lang=ru&period=yesterday&dimension_field=' . rawurlencode('date|day')
                . '&' . implode('&', array_map(fn($f) => 'field=' . $f, $fields));
            $resp = $this->httpClient->request('GET', 'https://partner.yandex.ru/api/statistics2/get.json?' . $qs, [
                'headers' => ['Authorization' => 'OAuth ' . $this->rsyaToken],
                'timeout' => 10,
            ])->toArray(false);

            $totals = $resp['data']['totals'] ?? null;
            if (!is_array($totals) || $totals === []) {
                return null;
            }
            // RUB = "2"; иначе первая доступная валюта
            $row = ($totals['2'][0] ?? null) ?? (reset($totals)[0] ?? null);
            if (!is_array($row)) {
                return null;
            }
            return [
                'impressions' => (int) round((float) ($row['impressions'] ?? 0)),
                'clicks'      => (int) round((float) ($row['clicks'] ?? 0)),
                'revenue'     => (float) ($row['partner_wo_nds'] ?? 0),
                'ecpm'        => (float) ($row['ecpm_partner_wo_nds'] ?? 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * AIO-радар приоритетов: запросы с показами, но нулём кликов (клик забирает
     * AI Overview / выдача), где формат запроса вероятно триггерит AIO
     * (AioQueryClassifier — тот же, что в app:seo:aio-queries). Из gsc_query_stats
     * (локальная БД). Пусто → нет данных/утечки. Кап $limit.
     *
     * @return list<array{query:string,impr:int,pos:float,label:string}>
     */
    private function fetchAioLeakQueries(int $minImpr = 8, int $limit = 5): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT query, SUM(impressions) impr, SUM(clicks) clk, AVG(position) pos
                 FROM gsc_query_stats GROUP BY query
                 HAVING impr >= ? AND clk = 0
                 ORDER BY impr DESC LIMIT 200',
                [$minImpr],
            );
        } catch (\Throwable) {
            return []; // таблицы нет / не синкали — секция просто не покажется
        }
        $out = [];
        foreach ($rows as $r) {
            $q = (string) $r['query'];
            if (!$this->aio->isAioLikely($q)) {
                continue;
            }
            $out[] = [
                'query' => $q,
                'impr'  => (int) $r['impr'],
                'pos'   => round((float) $r['pos'], 1),
                'label' => $this->aio->classify($q)['label'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    protected function configure(): void
    {
        $this->addOption('stdout-only', null, InputOption::VALUE_NONE, 'Не слать в Telegram, только вывести');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $one = fn(string $sql) => (int) $this->db->fetchOne($sql);

        // --- Публикации с прода (агент-API; TG с прода недоступен — поэтому тянем сюда) ---
        $pub = ['published_today' => '—', 'published_yesterday' => '—', 'published_total' => '—', 'queue_pending' => '—', 'last_published' => '—'];
        try {
            if (trim((string) $this->prodApiUrl) !== '') {
                $d = $this->httpClient->request('GET', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/publish-stats', [
                    'headers' => ['X-Agent-Token' => (string) $this->agentToken],
                    'timeout' => 8,
                ])->toArray(false);
                $pub = array_merge($pub, array_intersect_key($d, $pub));
            }
        } catch (\Throwable) {
            // прод недоступен — оставляем «—»
        }

        // --- GSC (локальная БД; синк делает крон .43) ---
        $cohort = $this->db->fetchAssociative(
            "SELECT COUNT(s.id) checked, COALESCE(SUM(s.first_indexed_at IS NOT NULL),0) idx FROM brand b
             JOIN gsc_index_status s ON s.brand_id = b.id
             WHERE b.published_at IS NOT NULL AND b.published_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)",
        ) ?: ['checked' => 0, 'idx' => 0];
        $gscChecked = $one("SELECT COUNT(*) FROM gsc_index_status");
        $gscIndexed = $one("SELECT COALESCE(SUM(indexed),0) FROM gsc_index_status");
        $gscEver    = $one("SELECT COUNT(*) FROM gsc_index_status WHERE first_indexed_at IS NOT NULL");
        $gscLast    = $this->db->fetchOne("SELECT MAX(last_checked_at) FROM gsc_index_status") ?: '—';
        $cohortTxt  = (int) $cohort['checked'] > 0
            ? sprintf('%d/%d (%.0f%%)', $cohort['idx'], $cohort['checked'], 100 * $cohort['idx'] / max(1, (int) $cohort['checked']))
            : '— (нет когорты 14д+)';

        // --- Яндекс.Вебмастер (локальная БД; синк крон Mac 07:00) ---
        $yaByType = $this->db->fetchAllAssociative(
            "SELECT page_type, COUNT(*) c FROM yandex_index_status WHERE in_search = 1 GROUP BY page_type",
        );
        $yaCounts   = array_column($yaByType, 'c', 'page_type');
        $yaInSearch = array_sum($yaCounts);
        $yaTypesTxt = implode(' · ', array_map(
            fn($t, $c) => "{$t} {$c}",
            array_keys($yaCounts), $yaCounts,
        ));
        $yaLast     = $this->db->fetchOne("SELECT MAX(last_checked_at) FROM yandex_index_status") ?: '—';
        $yaQ = $this->db->fetchAssociative(
            "SELECT COUNT(*) c, COALESCE(SUM(shows),0) shows, COALESCE(SUM(clicks),0) clicks
             FROM yandex_query_stats WHERE date_to = (SELECT MAX(date_to) FROM yandex_query_stats)",
        ) ?: ['c' => 0, 'shows' => 0, 'clicks' => 0];
        $yaQtxt = (int) $yaQ['c'] > 0
            ? sprintf('%d фраз · показы %d · клики %d', $yaQ['c'], $yaQ['shows'], $yaQ['clicks'])
            : '—';

        // --- Контакты (локальная БД) ---
        $contacts = $this->db->fetchAssociative(
            "SELECT
               COUNT(*)                                           AS total,
               SUM(b.email IS NOT NULL AND b.email != '')         AS with_email,
               SUM(b.phone IS NOT NULL AND b.phone != '')         AS with_phone,
               SUM(b.contact_status = 'enriched')                 AS enriched,
               SUM(b.contact_status = 'partial')                  AS partial,
               SUM(b.contact_status = 'not_found')                AS not_found,
               SUM(o.bounced_at IS NOT NULL)                      AS bounced,
               SUM(b.contact_enriched_at IS NOT NULL
                   AND b.contact_enriched_at < DATE_SUB(NOW(), INTERVAL 180 DAY)) AS stale
             FROM brand b
             LEFT JOIN brand_outreach o ON o.brand_id = b.id AND o.bounced_at IS NOT NULL
             WHERE b.status IN ('active', 'new')"
        ) ?: [];
        $updated24h = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM brand WHERE contact_enriched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        $contactLine = '';
        if (($contacts['total'] ?? 0) > 0) {
            $t   = (int) $contacts['total'];
            $em  = (int) $contacts['with_email'];
            $ph  = (int) $contacts['with_phone'];
            $en  = (int) $contacts['enriched'];
            $pa  = (int) $contacts['partial'];
            $nf  = (int) $contacts['not_found'];
            $bo  = (int) $contacts['bounced'];
            $st  = (int) $contacts['stale'];
            $u24 = $updated24h;
            $contactLine = sprintf(
                "\n\n<b>📬 Контакты:</b> email %d/%d (%d%%) · тел. %d/%d (%d%%) · " .
                "enr %d · part %d · nf %d · %s · stale %d · +%d/24ч",
                $em, $t, $t > 0 ? round(100 * $em / $t) : 0,
                $ph, $t, $t > 0 ? round(100 * $ph / $t) : 0,
                $en, $pa, $nf,
                $bo > 0 ? "⛔ bounced {$bo}" : 'bounced 0',
                $st, $u24,
            );
        }

        // --- РСЯ (доход/показы за вчера; Partner API, только с Mac) ---
        $rsya    = $this->fetchRsyaYesterday();
        $rsyaLine = "\n\n<b>💰 РСЯ (вчера):</b> ";
        if ($rsya !== null) {
            $rsyaLine .= sprintf(
                'доход %s ₽ · показы %s · клики %d · eCPM %s ₽',
                number_format($rsya['revenue'], 2, '.', ' '),
                number_format($rsya['impressions'], 0, '.', ' '),
                $rsya['clicks'],
                number_format($rsya['ecpm'], 2, '.', ' '),
            );
        } else {
            $rsyaLine .= '— (нет токена или API недоступен)';
        }

        // --- Соцсети (VK/IG API напрямую; Дзен best-effort + fallback из БД) ---
        $vk   = $this->fetchVkStats();
        $ig   = $this->fetchIgStats();
        $dzen = $this->fetchDzenStats();
        $igEngage = $ig !== null
            ? sprintf('охват 28д %s · лайки %s · комм. %s', $ig['reach'] ?? '—', $ig['likes'] ?? '—', $ig['comments'] ?? '—')
            : '—';
        $socialLine = sprintf(
            "\n\n<b>📱 Соцсети:</b> VK %s подписч. (%s постов) · IG %s подписч. (%s публ.)\n<b>IG вовлечённость:</b> %s\n<b>Дзен:</b> %s",
            $vk !== null ? $vk['members'] : '—',
            $vk !== null ? $vk['posts_total'] : '—',
            $ig !== null ? $ig['followers'] : '—',
            $ig !== null ? $ig['media'] : '—',
            $igEngage,
            isset($dzen['visits'])
                ? sprintf('→ сайт за 7 дн: %s визитов, %s посетителей', $dzen['visits'], $dzen['users'])
                : ($dzen['articles_in_feed'] ?? '—') . ' статей в фиде (Метрика недоступна)',
        );

        // --- AIO-радар: запросы с показами, но клик уходит в ИИ-ответ (топ приоритетов на доработку) ---
        $aioLeak = $this->fetchAioLeakQueries();
        $aioLine = "\n\n<b>🔎 AIO-утечка (клик → ИИ-ответ):</b> ";
        if ($aioLeak !== []) {
            $aioLine .= "топ по показам\n" . implode("\n", array_map(
                fn (array $q) => sprintf(
                    '• %s — %d показ. · поз.%s <i>[%s]</i>',
                    htmlspecialchars($q['query']), $q['impr'], $q['pos'], $q['label'],
                ),
                $aioLeak,
            ));
        } else {
            $aioLine .= '— (нет данных gsc_query_stats или утечки)';
        }

        $msg = sprintf(
            "<b>📅 Дайджест · %s</b>\n\n" .
            "<b>Публикации (прод):</b> вчера %s · всего %s · ждут %s\n" .
            "Последняя: %s\n\n" .
            "<b>GSC:</b> проверено %d · в индексе сейчас %d · когда-либо %d\n" .
            "Когорта 14д+ в индексе: %s\n" .
            "Последняя проверка: %s\n\n" .
            "<b>Яндекс:</b> в поиске %d (%s) · запросы: %s\n" .
            "Последняя проверка: %s%s%s%s%s",
            (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m'),
            $pub['published_yesterday'], $pub['published_total'], $pub['queue_pending'],
            $pub['last_published'],
            $gscChecked, $gscIndexed, $gscEver,
            $cohortTxt,
            $gscLast,
            $yaInSearch, $yaTypesTxt, $yaQtxt,
            $yaLast,
            $contactLine,
            $rsyaLine,
            $socialLine,
            $aioLine,
        );

        $io->text(strip_tags($msg));
        if (!$input->getOption('stdout-only')) {
            if (!$this->notifier->isEnabled()) {
                $io->warning('Telegram не настроен (ADMIN_TELEGRAM_CHAT_ID).');
                return Command::SUCCESS;
            }
            $this->notifier->send($msg);

            // Свежеопубликованные (24ч) — отдельным сообщением с кнопкой «🚫 Скрыть с публикации»
            // (TG-callback → BrandUnpublisher). Публикуется на проде, но TG ходит только с Mac,
            // поэтому уведомление-с-кнопкой шлём отсюда (дневной крон Mac). Кап, чтобы не спамить.
            $justPublished = $this->db->fetchAllAssociative(
                "SELECT id, title, slug FROM brand
                 WHERE status='active' AND published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY published_at DESC LIMIT 15"
            );
            foreach ($justPublished as $b) {
                $this->notifier->sendWithButton(
                    sprintf("✅ <b>Опубликован:</b> %s\nhttps://wearbase.ru/ru/brands/%s",
                        htmlspecialchars((string) $b['title']), $b['slug']),
                    '🚫 Скрыть с публикации',
                    'unpub:' . (int) $b['id'],
                );
            }
        }

        return Command::SUCCESS;
    }
}
