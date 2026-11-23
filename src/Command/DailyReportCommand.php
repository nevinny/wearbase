<?php

namespace App\Command;

use App\Notification\AdminNotifier;
use App\Notification\MorningDigestInbox;
use App\Service\Report\CardConversionCalculator;
use App\Service\SecretCipher;
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
 * Утренний дайджест в Telegram (docs/commands.md «Утренний дайджест»): одно отправление вместо
 * пачки информационных сообщений.
 *
 * Ядро: «🚨 Требует внимания» (упавшие/предупредившие задачи Mac-крона за 26ч + модерация,
 * только если есть что сказать) → цифры (публикации прода по агент-API /api/v1/publish-stats,
 * GSC/Яндекс/контакты из локальной БД, РСЯ/соцсети по API; нулевые/пустые строки не выводим)
 * → конверсия карточек. Затем секции из MorningDigestInbox (советник, конкуренты, по
 * понедельникам — недельный блок), всё режется на сообщения ≤4000 знаков по границам секций.
 * Сбой любой отправки → FAILURE и входящие НЕ помечаются отправленными (сработает алерт
 * диспетчера). Запускается с Mac (крон 30 9 * * *).
 *
 *   php bin/console app:report:daily               # собрать + отправить в TG
 *   php bin/console app:report:daily --stdout-only # превью: ядро + секции входящих, ничего не шлёт и не помечает
 */
#[AsCommand(name: 'app:report:daily', description: 'Утренний дайджест (проблемы кронов + цифры + секции информационных кронов) в Telegram — запускать с Mac')]
class DailyReportCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdminNotifier $notifier,
        private readonly HttpClientInterface $httpClient,
        private readonly SecretCipher $cipher,
        private readonly CardConversionCalculator $cardConversion,
        private readonly MorningDigestInbox $inbox,
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
     * Conversion-loop: сквозная конверсия карточки (поисковые клики → клик на сайт бренда)
     * за 7 дней vs пред. неделю + топ-3/анти-топ-3 карточек (мин. 5 входящих кликов).
     * Списки — только когда в рейтинг попало ≥3 карточек (иначе топ и анти-топ — одни и те же
     * бренды); анти-топ не повторяет топ.
     */
    private function buildConversionLine(): string
    {
        $conv = $this->cardConversion->compute();
        if (!$conv['available']) {
            return '<b>🎯 Конверсия карточек (7дн):</b> — (' . $conv['reason'] . ')';
        }

        $this_ = $conv['this_week'];
        $prev  = $conv['prev_week'];
        $delta = $this_['rate'] - $prev['rate'];

        $fmt = static fn(array $r) => sprintf(
            '%s %s%% (%d→%d)',
            htmlspecialchars((string) $r['title']), $r['rate'], $r['incoming'], $r['outgoing'],
        );

        $line = sprintf(
            '<b>🎯 Конверсия карточек (7дн):</b> %s%% (пред. %s%%, Δ%+.2f)',
            $this_['rate'], $prev['rate'], $delta,
        );

        $top = $conv['top'];
        if (count($top) >= 3) {
            $topIds = array_column($top, 'brand_id');
            $bottom = array_values(array_filter(
                $conv['bottom'],
                static fn(array $r) => !in_array($r['brand_id'], $topIds, true),
            ));
            $line .= "\nТоп: " . implode(' · ', array_map($fmt, $top));
            if ($bottom !== []) {
                $line .= ' — Анти-топ: ' . implode(' · ', array_map($fmt, $bottom));
            }
        }

        return $line;
    }

    /**
     * «🚨 Требует внимания»: включённые задачи Mac-крона (environment='dev'), отработавшие за
     * 26ч с exit≠0 или с [WARNING]/[ERROR] в хвосте вывода. Диспетчер алертит только по exit≠0
     * и только на переходе, поэтому предупреждения с exit 0 иначе не видит никто.
     *
     * @return list<string>
     */
    private function buildAttentionLines(): array
    {
        // last_run_at пишет диспетчер московским wall-time — порог считаем в той же зоне.
        $since = (new \DateTime('-26 hours', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT name, last_exit_code, last_output FROM scheduled_command
                 WHERE environment = 'dev' AND enabled = 1 AND last_run_at >= ?
                   AND command NOT LIKE 'app:report:daily%'
                   AND (last_exit_code <> 0 OR last_output LIKE '%[WARNING]%' OR last_output LIKE '%[ERROR]%')
                 ORDER BY name",
                [$since],
            );
        } catch (\Throwable) {
            return [];
        }

        $lines = [];
        foreach ($rows as $r) {
            $issue = self::extractIssue((string) $r['last_output']);
            if ($issue === '') {
                $issue = 'exit ' . (int) $r['last_exit_code'];
            }
            $lines[] = sprintf('• <b>%s</b> — %s', htmlspecialchars((string) $r['name']), htmlspecialchars($issue));
        }

        return $lines;
    }

    /**
     * Первая содержательная строка проблемы из хвоста вывода команды (≤$max знаков):
     * блок [ERROR] (приоритет) или [WARNING] со склейкой строк-продолжений и следующего за
     * ним списка «* item»; без маркера — последняя содержательная строка. Прогресс-бары,
     * рамки таблиц и следующий [TAG] блок обрывают.
     */
    public static function extractIssue(string $output, int $max = 150): string
    {
        $lines = preg_split('/\R/u', $output) ?: [];
        $start = null;
        foreach (['[ERROR]', '[WARNING]'] as $tag) {
            foreach ($lines as $i => $l) {
                if (str_contains($l, $tag)) {
                    $start = $i;
                    break 2;
                }
            }
        }

        if ($start === null) {
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $t = trim($lines[$i]);
                if ($t !== '' && !self::isOutputNoise($t)) {
                    return self::clip($t, $max);
                }
            }

            return '';
        }

        $parts    = [trim((string) preg_replace('/^.*?\[(ERROR|WARNING)\]/u', '', $lines[$start]))];
        $items    = [];
        $sawBlank = false;
        for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
            $t = trim($lines[$i]);
            if ($t === '') {
                if ($items !== [] || $sawBlank) {
                    break;
                }
                $sawBlank = true;
                continue;
            }
            if (self::isOutputNoise($t) || preg_match('/^\[[A-Z]+\]/', $t)) {
                break;
            }
            if (str_starts_with($t, '* ')) {
                $items[] = trim(substr($t, 2));
                continue;
            }
            if ($sawBlank || $items !== []) {
                break;
            }
            $parts[] = $t;
        }

        $text = implode(' ', array_filter($parts, static fn(string $p) => $p !== ''));
        if ($items !== []) {
            $text = rtrim($text, ' :') . ': ' . implode(', ', $items);
        }

        return self::clip($text, $max);
    }

    private static function isOutputNoise(string $line): bool
    {
        return (bool) preg_match('/^\d+\/\d+ \[|[▓░]|^[-=+\s]+$/u', $line);
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /** 'Y-m-d H:i:s' → 'd.m H:i'; пусто/мусор → '—'. */
    private static function shortTs(mixed $ts): string
    {
        if (!is_string($ts) || $ts === '' || $ts === '—') {
            return '—';
        }
        try {
            return (new \DateTime($ts))->format('d.m H:i');
        } catch (\Throwable) {
            return $ts;
        }
    }

    protected function configure(): void
    {
        $this->addOption('stdout-only', null, InputOption::VALUE_NONE, 'Не слать в Telegram и не помечать входящие — только вывести, что ушло бы');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $one = fn(string $sql) => (int) $this->db->fetchOne($sql);

        // --- Публикации с прода (агент-API) ---
        $pub = null;
        try {
            if (trim((string) $this->prodApiUrl) !== '') {
                $pub = $this->httpClient->request('GET', rtrim((string) $this->prodApiUrl, '/') . '/api/v1/publish-stats', [
                    'headers' => ['X-Agent-Token' => (string) $this->agentToken],
                    'timeout' => 8,
                ])->toArray(false);
                if (!isset($pub['published_total'])) {
                    $pub = null; // 401/ошибка API — тело без контракта
                }
            }
        } catch (\Throwable) {
            $pub = null; // прод недоступен
        }
        $moderation = $pub['moderation'] ?? null;

        if ($pub === null) {
            $pubLine = '<b>Публикации:</b> — (прод недоступен)';
        } else {
            $queue = (int) ($pub['queue_pending'] ?? 0);
            if (!array_key_exists('queue_raw', $pub)) {
                // Прод ещё со старым publish-stats: queue_pending там без niche/origin-гейтов.
                $drip = sprintf('очередь без гейтов %d', $queue);
            } elseif ($queue > 0) {
                $drip = sprintf('дрип: кандидатов %d', $queue);
            } else {
                $drip = 'дрип: публиковать нечего (кандидатов 0)';
            }
            $pubLine = sprintf(
                '<b>Публикации:</b> вчера %s · всего %s · %s · последняя %s',
                $pub['published_yesterday'] ?? '—', $pub['published_total'], $drip, self::shortTs($pub['last_published'] ?? null),
            );
        }

        // --- GSC (локальная БД) ---
        $numbers = [$pubLine];
        $gscChecked = $one('SELECT COUNT(*) FROM gsc_index_status');
        if ($gscChecked > 0) {
            $cohort = $this->db->fetchAssociative(
                "SELECT COUNT(s.id) checked, COALESCE(SUM(s.first_indexed_at IS NOT NULL),0) idx FROM brand b
                 JOIN gsc_index_status s ON s.brand_id = b.id
                 WHERE b.published_at IS NOT NULL AND b.published_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)",
            ) ?: ['checked' => 0, 'idx' => 0];
            $cohortTxt = (int) $cohort['checked'] > 0
                ? sprintf(' · когорта 14д+ %d/%d (%.0f%%)', $cohort['idx'], $cohort['checked'], 100 * $cohort['idx'] / max(1, (int) $cohort['checked']))
                : '';
            $numbers[] = sprintf(
                '<b>GSC:</b> в индексе %d из %d · когда-либо %d%s · проверка %s',
                $one('SELECT COALESCE(SUM(indexed),0) FROM gsc_index_status'),
                $gscChecked,
                $one('SELECT COUNT(*) FROM gsc_index_status WHERE first_indexed_at IS NOT NULL'),
                $cohortTxt,
                self::shortTs($this->db->fetchOne('SELECT MAX(last_checked_at) FROM gsc_index_status')),
            );
        }

        // --- Яндекс.Вебмастер (локальная БД; синк крон Mac 07:00) ---
        $yaCounts = array_column($this->db->fetchAllAssociative(
            'SELECT page_type, COUNT(*) c FROM yandex_index_status WHERE in_search = 1 GROUP BY page_type',
        ), 'c', 'page_type');
        $yaInSearch = array_sum($yaCounts);
        $yaQ = $this->db->fetchAssociative(
            'SELECT COUNT(*) c, COALESCE(SUM(shows),0) shows, COALESCE(SUM(clicks),0) clicks
             FROM yandex_query_stats WHERE date_to = (SELECT MAX(date_to) FROM yandex_query_stats)',
        ) ?: ['c' => 0, 'shows' => 0, 'clicks' => 0];
        if ($yaInSearch > 0 || (int) $yaQ['c'] > 0) {
            $numbers[] = sprintf(
                '<b>Яндекс:</b> в поиске %d (%s)%s · проверка %s',
                $yaInSearch,
                implode(' · ', array_map(static fn($t, $c) => "{$t} {$c}", array_keys($yaCounts), $yaCounts)),
                (int) $yaQ['c'] > 0 ? sprintf(' · %d фраз, показы %d, клики %d', $yaQ['c'], $yaQ['shows'], $yaQ['clicks']) : '',
                self::shortTs($this->db->fetchOne('SELECT MAX(last_checked_at) FROM yandex_index_status')),
            );
        }

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
        if (($contacts['total'] ?? 0) > 0) {
            $t  = (int) $contacts['total'];
            $em = (int) $contacts['with_email'];
            $ph = (int) $contacts['with_phone'];
            $bo = (int) $contacts['bounced'];
            $numbers[] = sprintf(
                '<b>📬 Контакты:</b> email %d/%d (%d%%) · тел. %d/%d (%d%%) · enr %d · part %d · nf %d · %s · stale %d · +%d/24ч',
                $em, $t, round(100 * $em / $t),
                $ph, $t, round(100 * $ph / $t),
                (int) $contacts['enriched'], (int) $contacts['partial'], (int) $contacts['not_found'],
                $bo > 0 ? "⛔ bounced {$bo}" : 'bounced 0',
                (int) $contacts['stale'],
                $one('SELECT COUNT(*) FROM brand WHERE contact_enriched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'),
            );
        }

        // --- РСЯ (вчера; нет токена/данных/нули — строку не выводим) ---
        $rsya = $this->fetchRsyaYesterday();
        if ($rsya !== null && ($rsya['revenue'] > 0 || $rsya['impressions'] > 0)) {
            $numbers[] = sprintf(
                '<b>💰 РСЯ (вчера):</b> доход %s ₽ · показы %s · клики %d · eCPM %s ₽',
                number_format($rsya['revenue'], 2, '.', ' '),
                number_format($rsya['impressions'], 0, '.', ' '),
                $rsya['clicks'],
                number_format($rsya['ecpm'], 2, '.', ' '),
            );
        }

        // --- Соцсети (VK/IG API; Дзен — только если были переходы на сайт) ---
        $vk   = $this->fetchVkStats();
        $ig   = $this->fetchIgStats();
        $dzen = $this->fetchDzenStats();
        $social = [];
        if ($vk !== null) {
            $social[] = sprintf('VK %d подписч. (%d постов)', $vk['members'], $vk['posts_total']);
        }
        if ($ig !== null) {
            $social[] = sprintf(
                'IG %d подписч. (%d публ.), охват 28д %s, лайки %s, комм. %s',
                $ig['followers'], $ig['media'], $ig['reach'] ?? '—', $ig['likes'] ?? '—', $ig['comments'] ?? '—',
            );
        }
        if (($dzen['visits'] ?? 0) > 0) {
            $social[] = sprintf('Дзен → сайт 7д: %d визитов, %d посетителей', $dzen['visits'], $dzen['users']);
        }
        if ($social !== []) {
            $numbers[] = '<b>📱 Соцсети:</b> ' . implode(' · ', $social);
        }

        // --- Требует внимания: упавшие/предупредившие кроны + модерация ---
        $attention = $this->buildAttentionLines();
        if ($moderation !== null && (
            ($moderation['queued'] ?? 0) > 0
            || ($moderation['reviewed_awaiting'] ?? 0) > 0
            || ($moderation['claims_pending'] ?? 0) > 0
        )) {
            $attention[] = sprintf(
                '• <b>🛂 Модерация:</b> %d в очереди (старейшая %d дн.), %d ждут решения, %d claim',
                $moderation['queued'] ?? 0,
                $moderation['oldest_queued_days'] ?? 0,
                $moderation['reviewed_awaiting'] ?? 0,
                $moderation['claims_pending'] ?? 0,
            );
        }

        $core = sprintf('<b>📅 Дайджест · %s</b>', (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m'));
        if ($attention !== []) {
            $core .= "\n\n<b>🚨 Требует внимания</b>\n" . implode("\n", $attention);
        }
        $core .= "\n\n" . implode("\n", $numbers) . "\n\n" . $this->buildConversionLine();

        $messages = MorningDigestInbox::pack([$core, ...$this->inbox->sections()]);

        foreach ($messages as $n => $msg) {
            $io->section(sprintf('Сообщение %d/%d · %d знаков', $n + 1, count($messages), mb_strlen($msg)));
            $io->text(strip_tags($msg));
        }

        if ($input->getOption('stdout-only')) {
            return Command::SUCCESS;
        }
        if (!$this->notifier->isEnabled()) {
            $io->warning('Telegram не настроен (ADMIN_TELEGRAM_CHAT_ID).');
            return Command::SUCCESS;
        }
        foreach ($messages as $n => $msg) {
            if (!$this->notifier->sendChecked($msg)) {
                // Входящие не помечаем: секции не потеряются, а exit≠0 поднимет алерт диспетчера.
                $io->error(sprintf('Сообщение %d/%d не доставлено в Telegram (подробности — в логе TelegramNotifier).', $n + 1, count($messages)));
                return Command::FAILURE;
            }
        }
        $this->inbox->markSent();
        $io->success(sprintf('Дайджест отправлен: %d сообщ.', count($messages)));

        return Command::SUCCESS;
    }
}
