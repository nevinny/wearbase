<?php

namespace App\Command;

use App\Notification\AdminNotifier;
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
        #[Autowire('%env(default::PROD_API_URL)%')]
        private readonly ?string $prodApiUrl,
        #[Autowire('%env(default::AGENT_API_TOKEN)%')]
        private readonly ?string $agentToken,
        #[Autowire('%env(default::YANDEX_PARTNER_TOKEN)%')]
        private readonly ?string $rsyaToken,
    ) {
        parent::__construct();
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

        $msg = sprintf(
            "<b>📅 Дайджест · %s</b>\n\n" .
            "<b>Публикации (прод):</b> вчера %s · всего %s · ждут %s\n" .
            "Последняя: %s\n\n" .
            "<b>GSC:</b> проверено %d · в индексе сейчас %d · когда-либо %d\n" .
            "Когорта 14д+ в индексе: %s\n" .
            "Последняя проверка: %s\n\n" .
            "<b>Яндекс:</b> в поиске %d (%s) · запросы: %s\n" .
            "Последняя проверка: %s%s%s",
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
