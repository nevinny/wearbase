<?php

namespace App\Service;

use App\Entity\Brand;
use App\Service\Discovery\DiscoveredUrl;
use App\Service\Discovery\SourceTypeClassifier;

/**
 * Находит URL-источники бренда для скрейпа.
 *
 * Многоуровневый discovery (discoverTiered):
 *   T1 own_site  — DB website-ссылка + угадывание {slug}.ru/.com + SearXNG
 *                  «{бренд} одежда официальный сайт»; верификация (verifyUrl)
 *                  только для 1–2 финальных кандидатов; cap 1–2.
 *   T2 corpus    — SearXNG «{бренд} одежда/купить/{город} магазин»;
 *                  marketplace ≤3, catalog/mention ≤4.
 *   T3 mentions  — соц-ссылки из БД + SearXNG «{бренд} отзывы/обзор»;
 *                  social ≤4, article_review ≤3, mention ≤3.
 *
 * Дизамбигуация (MariDeniz→диабет): co-occurrence имени бренда/slug И fashion-термина
 * в title+snippet + relevance_score 0..1; floor 0.35 для поискового корпуса.
 * DB-ссылки и slug-guess — доверенные сиды, floor обходят.
 *
 * Дедуп по URL (глобально между тирами), per-host cap 4. Исключения — UrlFilter.
 *
 * Старый discover():string[] оставлен шимом поверх discoverTiered() — монолитный
 * app:brand:scrape работает без изменений.
 */
class BrandSourceFinder
{
    private const MAX_PER_HOST = 5;    // не больше N страниц с одного хоста (глобально)
    private const PER_QUERY    = 25;   // результатов на поисковый запрос

    private const FLOOR = 0.35;        // ниже — не кладём в очередь (для корпуса)
    private const SEED_SCORE = 0.9;    // доверенные сиды (DB / slug-guess) — высокий baseline

    private const T1_CAP = 2;              // own_site в очередь
    private const T1_VERIFY_BUDGET = 5;    // макс. verifyUrl-вызовов в T1 (10s каждый)

    // T2 (corpus) — побольше товарных источников (особенно для брендов без своего сайта)
    private const T2_MARKETPLACE_CAP = 5;
    private const T2_MENTION_CAP     = 8;   // catalog свёрнут в mention

    // T3 (mentions/social)
    private const T3_SOCIAL_CAP  = 6;
    private const T3_REVIEW_CAP  = 5;
    private const T3_MENTION_CAP = 6;

    /** fashion-термины для co-occurrence (имя бренда + ≥1 термин). */
    private const FASHION_TERMS = [
        'одежд', 'бренд', 'магазин', 'купить', 'коллекц', 'мода', 'fashion',
        'wear', 'shop', 'store', 'платье', 'футболк', 'свитшот', 'худи', 'аксессуар',
    ];

    /** deny-list: омонимы вне fashion-контекста (диабет/медицина) — штраф. */
    private const DENY_TERMS = ['диабет', 'сахар', 'медицин', 'клиника'];

    public function __construct(
        private readonly SearxClient $searx,
        private readonly UrlFilter $urlFilter,
        private readonly SourceTypeClassifier $classifier,
        private readonly ContactVerifier $verifier,
    ) {
    }

    /**
     * Шим обратной совместимости для монолитного app:brand:scrape.
     *
     * @return string[]
     */
    public function discover(Brand $brand, int $max = 50): array
    {
        return array_map(
            static fn (DiscoveredUrl $d): string => $d->url,
            $this->discoverTiered($brand, $max),
        );
    }

    /**
     * Многоуровневый discovery с типизацией, скорингом и cap'ами по тиру.
     *
     * @return DiscoveredUrl[]
     */
    public function discoverTiered(Brand $brand, int $max = 50): array
    {
        $title = trim((string) $brand->getTitle());
        $slug  = (string) $brand->getSlug();
        $city  = trim((string) $brand->getCity());

        $needle = mb_strtolower($title);
        $slugN  = str_replace('-', '', mb_strtolower($slug));

        /** @var DiscoveredUrl[] $out */
        $out = [];
        $seen = [];      // url => true (глобальный дедуп между тирами)
        $perHost = [];   // host => count (глобальный cap по хосту)

        /**
         * Нормализует, проверяет фильтры/дедуп/host-cap. Возвращает нормализованный
         * URL и host, либо null если URL отбракован.
         *
         * @return array{0:string,1:string}|null
         */
        $accept = function (?string $url) use (&$seen, &$perHost): ?array {
            if ($url === null) {
                return null;
            }
            $url = rtrim(trim($url), '/');
            if ($url === '' || isset($seen[$url]) || $this->urlFilter->isExcluded($url)) {
                return null;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') {
                return null;
            }
            if (($perHost[$host] ?? 0) >= self::MAX_PER_HOST) {
                return null;
            }

            return [$url, $host];
        };

        $commit = function (string $url, string $host, DiscoveredUrl $d) use (&$seen, &$perHost, &$out): void {
            $seen[$url] = true;
            $perHost[$host] = ($perHost[$host] ?? 0) + 1;
            $out[] = $d;
        };

        // ── T1: own_site ──────────────────────────────────────────────────────
        // Кандидаты по приоритету (prio, меньше = сильнее сигнал):
        //   0 — DB-ссылка на собственный домен (не marketplace/social),
        //   1 — SearXNG-результат по «официальный сайт» (по score),
        //   2 — угадывание {slug}.ru/.com (самый слабый сигнал).
        // Собираем и скорим без сети; verifyUrl (дорогой, 10s) зовём по порядку
        // приоритета, ограниченно (T1_VERIFY_BUDGET), и предпочитаем live мёртвым:
        // live эмитим первыми до T1_CAP, иначе один лучший dead как provisional.
        /** @var array<int,array{url:string,host:string,score:float,prio:int}> $t1cand */
        $t1cand = [];
        $t1seenUrl = [];

        $addT1 = function (?string $url, float $score, int $prio) use ($accept, &$t1cand, &$t1seenUrl): void {
            $pair = $accept($url);
            if ($pair === null) {
                return;
            }
            [$nUrl, $host] = $pair;
            if (isset($t1seenUrl[$nUrl])) {
                return;
            }
            // Маркетплейс/соцсеть own-site быть не может.
            if ($this->classifier->classify($nUrl, '', '', true) !== 'own_site') {
                return;
            }
            $t1seenUrl[$nUrl] = true;
            $t1cand[] = ['url' => $nUrl, 'host' => $host, 'score' => $score, 'prio' => $prio];
        };

        // DB-ссылки на собственный домен — приоритет 0. Берём не только link_type
        // === 'website' (legacy-ссылки часто без типа): любой не-marketplace/social
        // хост считаем own-site кандидатом (шим-совместимость со старым discover()).
        foreach ($brand->getLinks() as $link) {
            $addT1($link->getLinkUrl(), self::SEED_SCORE, 0);
        }
        // SearXNG «официальный сайт» — приоритет 1, по score; own-сигнал если slug в хосте.
        if ($title !== '' && $this->searx->isConfigured()) {
            foreach ($this->searx->search("{$title} одежда официальный сайт", self::PER_QUERY) as $r) {
                $pair = $accept($r['url']);
                if ($pair === null) {
                    continue;
                }
                [, $host] = $pair;
                $ownSignal = $slugN !== '' && str_contains(str_replace('-', '', $host), $slugN);
                $score = $this->score($needle, $slugN, $r['title'], $r['content'], $ownSignal);
                if ($score < self::FLOOR) {
                    continue;
                }
                $addT1($r['url'], $score, 1);
            }
        }
        // Угадывание {slug}.ru/.com — приоритет 2 (слабейший сигнал).
        if ($slug !== '') {
            $addT1("https://{$slug}.ru", self::SEED_SCORE, 2);
            $addT1("https://{$slug}.com", self::SEED_SCORE, 2);
        }

        // Сортируем: по приоритету, затем по score.
        usort($t1cand, static function (array $a, array $b): int {
            return $a['prio'] <=> $b['prio'] ?: $b['score'] <=> $a['score'];
        });

        // Верифицируем по порядку (бюджет вызовов), предпочитаем live; cap T1_CAP.
        $t1live = [];   // проверенные живые
        $t1dead = [];   // проверенные мёртвые (на случай fallback)
        $verifyCalls = 0;
        foreach ($t1cand as $c) {
            if (count($t1live) >= self::T1_CAP || $verifyCalls >= self::T1_VERIFY_BUDGET) {
                break;
            }
            $verifyCalls++;
            if ($this->verifier->verifyUrl($c['url'])) {
                $t1live[] = $c;
            } else {
                $t1dead[] = $c;
            }
        }

        $t1pick = $t1live;
        // Нет живых own-site, но кандидаты были — кладём один лучший как provisional.
        if ($t1pick === [] && $t1dead !== []) {
            $t1pick[] = $t1dead[0];
        }
        foreach (array_slice($t1pick, 0, self::T1_CAP) as $c) {
            if (count($out) >= $max) {
                break;
            }
            $live = in_array($c, $t1live, true);
            $commit($c['url'], $c['host'], new DiscoveredUrl($c['url'], 'own_site', 1, $c['score'], $live));
        }

        // ── T2: corpus ────────────────────────────────────────────────────────
        if ($title !== '' && $this->searx->isConfigured() && count($out) < $max) {
            $queries = [
                "{$title} одежда",
                "{$title} купить",
                "{$title} интернет-магазин",
                "{$title} бренд",
            ];
            if ($city !== '') {
                $queries[] = "{$title} {$city} магазин";
            }

            $budget = ['marketplace' => self::T2_MARKETPLACE_CAP, 'mention' => self::T2_MENTION_CAP];
            foreach ($queries as $q) {
                if (count($out) >= $max) {
                    break;
                }
                foreach ($this->searx->search($q, self::PER_QUERY) as $r) {
                    if (count($out) >= $max) {
                        break;
                    }
                    $pair = $accept($r['url']);
                    if ($pair === null) {
                        continue;
                    }
                    [$nUrl, $host] = $pair;
                    $score = $this->score($needle, $slugN, $r['title'], $r['content'], false);
                    if ($score < self::FLOOR) {
                        continue;
                    }
                    $type = $this->classifier->classify($nUrl, $r['title'], $r['content'], false);
                    // В T2 интересуют товарные источники: marketplace + mention (catalog).
                    // social/article_review приберегаем для T3.
                    $bucket = $type === 'marketplace' ? 'marketplace' : 'mention';
                    if (!in_array($type, ['marketplace', 'mention'], true)) {
                        continue;
                    }
                    if (($budget[$bucket] ?? 0) <= 0) {
                        continue;
                    }
                    $budget[$bucket]--;
                    $commit($nUrl, $host, new DiscoveredUrl($nUrl, $type, 2, $score, false));
                }
            }
        }

        // ── T3: mentions / social ──────────────────────────────────────────────
        if (count($out) < $max) {
            $t3budget = [
                'social'         => self::T3_SOCIAL_CAP,
                'article_review' => self::T3_REVIEW_CAP,
                'mention'        => self::T3_MENTION_CAP,
            ];

            // Соц-ссылки из БД — доверенные сиды (floor не применяем). Own-site
            // DB-ссылки уже эмитнуты в T1 и отсекаются дедупом ($seen) в accept().
            foreach ($brand->getLinks() as $link) {
                if (count($out) >= $max) {
                    break;
                }
                $pair = $accept($link->getLinkUrl());
                if ($pair === null) {
                    continue;
                }
                [$nUrl, $host] = $pair;
                $type = $this->classifier->classify($nUrl, '', '', false);
                if (($t3budget[$type] ?? 0) <= 0) {
                    continue;
                }
                $t3budget[$type]--;
                $commit($nUrl, $host, new DiscoveredUrl($nUrl, $type, 3, self::SEED_SCORE, false));
            }

            // SearXNG «отзывы/обзор».
            if ($title !== '' && $this->searx->isConfigured() && count($out) < $max) {
                foreach (["{$title} одежда отзывы", "{$title} обзор", "{$title} бренд одежды"] as $q) {
                    if (count($out) >= $max) {
                        break;
                    }
                    foreach ($this->searx->search($q, self::PER_QUERY) as $r) {
                        if (count($out) >= $max) {
                            break;
                        }
                        $pair = $accept($r['url']);
                        if ($pair === null) {
                            continue;
                        }
                        [$nUrl, $host] = $pair;
                        $score = $this->score($needle, $slugN, $r['title'], $r['content'], false);
                        if ($score < self::FLOOR) {
                            continue;
                        }
                        $type = $this->classifier->classify($nUrl, $r['title'], $r['content'], false);
                        if (($t3budget[$type] ?? 0) <= 0) {
                            continue;
                        }
                        $t3budget[$type]--;
                        $commit($nUrl, $host, new DiscoveredUrl($nUrl, $type, 3, $score, false));
                    }
                }
            }
        }

        return array_slice($out, 0, $max);
    }

    /**
     * Co-occurrence + relevance_score 0..1.
     *  +0.5 имя в title, +0.3 имя в snippet (или slug-эквивалент)
     *  +0.25 fashion-термин (co-occur)
     *  +0.15 own-site сигнал
     *  −0.4  deny-term без fashion-контекста
     * Требует co-occurrence: имя бренда И ≥1 fashion-термин — иначе 0 (отсев).
     */
    private function score(string $needle, string $slugN, string $title, string $snippet, bool $ownSignal): float
    {
        $titleL = mb_strtolower($title);
        $snipL  = mb_strtolower($snippet);
        $bothL  = $titleL . ' ' . $snipL;

        $nameInTitle = $this->nameHit($needle, $slugN, $titleL);
        $nameInSnip  = $this->nameHit($needle, $slugN, $snipL);
        if (!$nameInTitle && !$nameInSnip) {
            return 0.0; // имя бренда вообще не встретилось — мусор
        }

        $fashion = false;
        foreach (self::FASHION_TERMS as $t) {
            if (str_contains($bothL, $t)) {
                $fashion = true;
                break;
            }
        }
        // Co-occurrence: имя + fashion-термин обязательны.
        if (!$fashion) {
            return 0.0;
        }

        $score = 0.25; // fashion co-occur
        if ($nameInTitle) {
            $score += 0.5;
        }
        if ($nameInSnip) {
            $score += 0.3;
        }
        if ($ownSignal) {
            $score += 0.15;
        }

        // deny-list: омоним вне fashion (fashion уже есть, но если перевешивает
        // медицинский контекст — штрафуем, чтобы отсечь класс MariDeniz→диабет).
        foreach (self::DENY_TERMS as $d) {
            if (str_contains($bothL, $d)) {
                $score -= 0.4;
                break;
            }
        }

        return max(0.0, min(1.0, $score));
    }

    /** Имя бренда (или slug-эквивалент) встречается в строке. */
    private function nameHit(string $needle, string $slugN, string $hay): bool
    {
        if ($needle !== '' && mb_strlen($needle) >= 3 && str_contains($hay, $needle)) {
            return true;
        }

        return $slugN !== '' && mb_strlen($slugN) >= 3
            && str_contains(str_replace('-', '', $hay), $slugN);
    }
}
