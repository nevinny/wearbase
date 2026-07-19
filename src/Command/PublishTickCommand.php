<?php

namespace App\Command;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Дрип-публикация (прод): системный cron раз в час. Постепенный вывод новой базы
 * брендов, имитирующий ручную работу — резкий скачок числа страниц вредит Google
 * (content velocity / SpamBrain).
 *
 *   0 * * * * cd /path && php bin/console app:brand:publish-tick --no-debug >> var/log/publish.log 2>&1
 *
 * Логика тика:
 *  1. Окно бодрствования 9–23 МСК (явная TZ — прод-сервер может жить в UTC).
 *  2. sleep(rand(0..45мин)) — публикации не по ровным часам (--no-wait для теста).
 *  3. Ramp-up БЕЗ хранимого состояния: w = недель с PUBLISH_LAUNCH_DATE (env);
 *     дневной таргет T(w) = min(CAP, round(START * (1+G)^w)) — старт 10/день,
 *     +22%/нед, потолок 80/день (разгон под живой индекс Яндекса, см. RATE_* ниже).
 *  4. Самокоррекция: p = (T - published_today) / оставшихся_тиков;
 *     за тик публикуем n = floor(p) + Bernoulli(frac(p)) — иначе CAP недостижим
 *     (15 тиков/день < 28 публикаций при «1 за тик»).
 *  5. Выбор СЛУЧАЙНЫХ готовых брендов (status='new' AND publish_pending=1,
 *     по СПРОСУ-НА-ПОКУПКУ: имя бренда + коммерч.модификатор) → active + published_at (в каталог/sitemap).
 */
#[AsCommand(
    name: 'app:brand:publish-tick',
    description: 'Дрип-публикация брендов: часовой тик с ramp-up (cron на проде)',
)]
class PublishTickCommand extends Command
{
    private const TZ          = 'Europe/Moscow';
    private const HOUR_FROM   = 9;    // первый тик дня
    private const HOUR_TO     = 22;   // последний тик дня (sleep до 45м удержит публикацию до ~23)
    private const MAX_SLEEP   = 2700; // 45 мин
    // Разгон 02.07: Яндекс усваивает страницы (pages-in-search 339→494 май→июль, показы ×2),
    // покрытие НЕ заморожено (в отличие от Google), а в очереди дрипа ~3000 grounded-брендов.
    // Подняли потолок 28→80 и ускорили ramp — под наблюдением yandex_history/панели «Динамика Яндекс».
    private const RATE_START  = 10.0; // брендов/день на старте
    // Разгон 19.07: очередь дрипа ~2119 grounded-брендов (eligible), Яндекс усваивает
    // (pages-in-search растёт, показы ×5), Google-покрытие заморожено (index-guard inert).
    // Ramp 0.18→0.22: плавно, без разового вброса — сегодня w6 таргет 27→33 (+6/день),
    // потолок 80/день достигается ~на 2 недели раньше (w11 вместо w13). CAP НЕ трогаем —
    // это реальный потолок объёма/день (на 80 Яндекс ещё не наблюдался; фактический темп 27).
    // Предохранитель — yandexDripMultiplier (drip_health, авто-торможение при стагнации).
    private const RATE_GROWTH = 0.22; // +22% в неделю
    private const RATE_CAP    = 80;   // потолок брендов/день

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\IndexNowPinger $indexNow,
        private readonly \App\Service\BrandLinkGraphService $linkGraph,
        private readonly \App\Notification\AdminNotifier $notifier,
        private readonly \App\Service\BrandActionSigner $actionSigner,
        #[Autowire('%env(default::PUBLISH_LAUNCH_DATE)%')]
        private readonly ?string $launchDate,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Посчитать T(w)/p/n, ничего не публиковать')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Без случайной задержки (для теста)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $noWait = (bool) $input->getOption('no-wait');

        if (trim((string) $this->launchDate) === '') {
            $io->error('PUBLISH_LAUNCH_DATE не задан (env, формат YYYY-MM-DD) — дрип выключен.');
            return Command::FAILURE;
        }

        // Защита от перекрытия тиков (cron + 45-минутный sleep)
        $lock = fopen($this->projectDir . '/var/publish_tick.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $io->warning('Предыдущий тик ещё работает — выходим.');
            return Command::SUCCESS;
        }

        $tz  = new \DateTimeZone(self::TZ);
        $now = new \DateTime('now', $tz);
        $hour = (int) $now->format('G');

        if ($hour < self::HOUR_FROM || $hour > self::HOUR_TO) {
            $io->text(sprintf('[%s] Вне окна %d–%d МСК — спим.', $now->format('H:i'), self::HOUR_FROM, self::HOUR_TO + 1));
            return Command::SUCCESS;
        }

        // --- Ramp-up ---
        $launch = new \DateTime($this->launchDate, $tz);
        $week   = max(0, (int) floor(($now->getTimestamp() - $launch->getTimestamp()) / (7 * 86400)));
        $target = (int) min(self::RATE_CAP, round(self::RATE_START * (1 + self::RATE_GROWTH) ** $week));

        // Drip-health (СТРОГО fail-open, ТОЛЬКО торможение): если когорта опубликованных
        // 7-21 день назад плохо индексируется (по данным gsc_index_status) — снижаем темп.
        // Нет данных / мало когорты / GSC не настроен → множитель 1.0, публикация не тормозится.
        $health = $this->dripHealthMultiplier();
        if ($health < 1.0) {
            $target = max(1, (int) floor($target * $health));
        }

        // Авто-guard на ЖИВОЙ индекс Яндекса (Mac→прод через drip_health): усваивает ли Яндекс
        // новые страницы (динамика pages-in-search). Растёт → ×1.0, стоит → ×0.5, падает → ×0.25.
        // Заменяет инертный GSC-guard (покрытие Google заморожено). Fail-open: нет/протух сигнал → 1.0.
        $yaHealth = $this->yandexDripMultiplier();
        if ($yaHealth < 1.0) {
            $target = max(1, (int) floor($target * $yaHealth));
        }

        // Hard index-guard (доктрина пакета _seo / LESSONS_FROM_HISTORY: index-guards
        // indexed-ratio <5% → 0 new, <10% → cap 1): когорта показывает динамику свежих,
        // а это — здоровье ВСЕГО домена. На слабом index-rate новые страницы лишь растят
        // «discovered/crawled - not indexed». Жёсткий ПОТОЛОК (не множитель): 0 = полный
        // стоп. Fail-open: нет GSC-данных / проверено мало → null, дрип не трогаем.
        $indexCap = $this->indexHealthCap();
        if ($indexCap !== null && $indexCap < $target) {
            $target = $indexCap;
        }

        $todayStart = (clone $now)->setTime(0, 0);
        $publishedToday = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM brand WHERE published_at >= :start',
            ['start' => $todayStart->format('Y-m-d H:i:s')],
        );

        $remaining = max(0, $target - $publishedToday);
        $ticksLeft = self::HOUR_TO - $hour + 1;
        $p = $ticksLeft > 0 ? $remaining / $ticksLeft : 0.0;
        $n = (int) floor($p) + ((mt_rand() / mt_getrandmax()) < fmod($p, 1.0) ? 1 : 0);

        $note = '';
        if ($indexCap !== null) {
            $note = sprintf(' (index-guard: потолок %d — индексация домена < %d%%)', $indexCap, $indexCap === 0 ? 5 : 10);
        } elseif ($health < 1.0) {
            $note = sprintf(' (заторможен ×%.2f: индексация когорты проседает)', $health);
        } elseif ($yaHealth < 1.0) {
            $note = sprintf(' (Яндекс-guard ×%.2f: страницы в поиске не растут)', $yaHealth);
        }

        $io->text(sprintf(
            '[%s МСК] неделя %d · таргет %d/день%s · опубликовано сегодня %d · тиков осталось %d · p=%.2f → n=%d',
            $now->format('H:i'), $week, $target, $note,
            $publishedToday, $ticksLeft, $p, $n,
        ));

        if ($dryRun || $n === 0) {
            $dryRun ? $io->note('dry-run — без публикации') : $io->text('В этот тик не публикуем.');
            return Command::SUCCESS;
        }

        // Человеческий паттерн: публикация не по ровным часам
        if (!$noWait) {
            $delay = random_int(0, self::MAX_SLEEP);
            $io->text(sprintf('Задержка %d мин %d сек…', intdiv($delay, 60), $delay % 60));
            sleep($delay);
        }

        // --- Готовые бренды ПО СПРОСу (drip-by-demand) ---
        // niche_status='off' (app:brand:niche-check) НЕ публикуем — чужая ниша. NULL/'in' проходят
        // (иначе гейт застопорит дрип до прогона классификатора → порядок cron: niche-check → publish-tick).
        // origin_status 'foreign'/'unknown' (app:brand:origin-check, docs/foreign_brands_policy.md)
        // НЕ публикуем — иностранный бренд или сомнение (ручной review). NULL/'ru' проходят.
        // Порядок: спрос НА ПОКУПКУ бренда = SUM(monthly_shows) по ключам, где имя бренда СОЧЕТАЕТСЯ
        // с коммерческим модификатором (одежда/бренд/купить/магазин/сайт). Так отсекается фейковый
        // спрос общесловных имён («яндекс браузер», «форма для выпечки»), а distinctive-бренды
        // (LIME/Sela/Zarina/Befree) выходят в индекс первыми — максимум трафика на страницу.
        // Раньше был чистый RAND() → публиковали вслепую. RAND() теперь рвёт ничьи.
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT b.id FROM brand b
              LEFT JOIN brand_keyword k ON k.brand_id = b.id
             WHERE b.status = 'new' AND b.publish_pending = 1
               AND (b.niche_status IS NULL OR b.niche_status <> 'off')
               AND (b.origin_status IS NULL OR b.origin_status NOT IN ('foreign', 'unknown'))
             GROUP BY b.id
             ORDER BY SUM(CASE WHEN LOWER(k.keyword) LIKE CONCAT('%', LOWER(b.title), '%')
                        AND (k.keyword LIKE '%одежд%' OR k.keyword LIKE '%бренд%' OR k.keyword LIKE '%купить%'
                          OR k.keyword LIKE '%магазин%' OR k.keyword LIKE '%официальн%' OR k.keyword LIKE '%сайт%')
                       THEN k.monthly_shows ELSE 0 END) DESC, RAND()
             LIMIT " . $n,
        );

        if ($ids === []) {
            $io->text('Очередь публикации пуста (нет new + publish_pending).');
            return Command::SUCCESS;
        }

        $published = 0;
        $newUrls = [];
        $tgLines = [];
        $tgButtons = []; // по одной кнопке-ссылке «🚫 Скрыть» на бренд (подписанный URL → BrandModerationController)
        foreach ($ids as $id) {
            $brand = $this->em->find(Brand::class, (int) $id);
            if ($brand === null) {
                continue;
            }
            // Доменный переход new → active. МСК, как и граница дня в published_today —
            // иначе на UTC-проде счёт published_today съезжает на 3ч.
            $brand->publish(new \DateTime('now', $tz));
            $this->em->flush();

            // Вплетение в жёсткий граф перелинковки: исходящие рёбра + гарантия
            // входящих (страница не рождается сиротой). Fail-open: SQL-fallback
            // источники (style/city/fill), эмбеддинг-рёбра доуточнит локальный
            // app:brand:build-link-graph. Сбой графа публикацию не ломает.
            try {
                $this->linkGraph->weave($brand->getId());
            } catch (\Throwable) {
            }

            $io->text(sprintf('  ✓ опубликован: %s (id %d)', $brand->getTitle(), $brand->getId()));
            $url = 'https://wearbase.ru/ru/brands/' . rawurlencode((string) $brand->getSlug());
            $newUrls[] = $url;
            // Сниппет описания (несколько предложений) — чтобы сразу было видно, про что бренд
            // (ловим чужие сущности глазами: «…пневмоэлементы автоподвески» → жмём «Скрыть»).
            $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string) $brand->getDescription())));
            $snippet = mb_substr($desc, 0, 280);
            if (mb_strlen($desc) > 280) {
                $snippet .= '…';
            }
            $tgLines[] = sprintf(
                '• <a href="%s">%s</a>%s',
                $url, htmlspecialchars((string) $brand->getTitle()),
                $snippet !== '' ? "\n<i>" . htmlspecialchars($snippet) . '</i>' : '',
            );
            // URL-кнопка на прод: клик открывает подписанную ссылку (?action=unpublish&id&key),
            // BrandModerationController скрывает бренд. Не зависит от callback-вебхука (тот таймаутит).
            $hideUrl = sprintf(
                'https://wearbase.ru/mod/brand-action?action=unpublish&id=%d&key=%s',
                $brand->getId(),
                $this->actionSigner->sign('unpublish', $brand->getId()),
            );
            $tgButtons[] = ['text' => '🚫 Скрыть: ' . mb_substr((string) $brand->getTitle(), 0, 40), 'url' => $hideUrl];
            $published++;
        }

        // IndexNow: мгновенный пинг Яндексу/Bing о новых URL (Google — через sitemap lastmod).
        // Fail-open: неуспех пинга публикацию не ломает.
        if ($newUrls !== [] && $this->indexNow->ping($newUrls)) {
            $io->text(sprintf('  → IndexNow: %d URL отправлено (Яндекс/Bing)', count($newUrls)));
        }

        // ТГ-уведомление со ссылками + кнопки «🚫 Скрыть» по каждому бренду (верификация человеком,
        // callback → unpublish). Fail-open: уведомление не должно ломать публикацию.
        if ($tgLines !== [] && $this->notifier->isEnabled()) {
            try {
                $this->notifier->sendWithButtons("📢 <b>Дрип-публикация</b>\n" . implode("\n", $tgLines), $tgButtons);
            } catch (\Throwable) {
            }
        }

        $io->success(sprintf('Опубликовано брендов: %d', $published));

        return Command::SUCCESS;
    }

    /**
     * Множитель темпа по здоровью индексации (внедрено по анализу 2026-06-04,
     * только в сторону замедления — авто-разгон запрещён по дизайну):
     * когорта published_at 7-21 день назад, из неё берём ПРОВЕРЕННЫХ в
     * gsc_index_status; если проверено ≥10 и indexed-доля < 10% → ×0.25,
     * < 30% → ×0.5. Любые отсутствующие данные → 1.0 (fail-open).
     */
    /**
     * Множитель темпа по ЖИВОМУ индексу Яндекса (drip_health, пуш Mac-синка app:yandex:sync).
     * Свежесть сигнала < 4 дней, иначе fail-open (1.0) — бэкап-предохранитель человек (панель
     * «Динамика Яндекс» + дневной TG-отчёт). Это рабочая замена инертному GSC-guard'у.
     */
    private function yandexDripMultiplier(): float
    {
        try {
            $row = $this->em->getConnection()->fetchAssociative('SELECT multiplier, updated_at FROM drip_health WHERE id = 1');
        } catch (\Throwable) {
            return 1.0; // таблицы нет / БД-сбой — не тормозим
        }
        if (!$row || ($row['updated_at'] ?? null) === null) {
            return 1.0;
        }
        if (strtotime((string) $row['updated_at']) < time() - 4 * 86400) {
            return 1.0; // сигнал протух — доверяем ramp'у, человек видит в панели/отчёте
        }

        return max(0.0, min(1.0, (float) $row['multiplier']));
    }

    private function dripHealthMultiplier(): float
    {
        try {
            $row = $this->em->getConnection()->fetchAssociative(
                'SELECT COUNT(s.id) checked, COALESCE(SUM(s.indexed),0) idx
                 FROM brand b
                 JOIN gsc_index_status s ON s.brand_id = b.id
                 WHERE b.published_at BETWEEN :from AND :to',
                [
                    'from' => (new \DateTime('-21 days'))->format('Y-m-d H:i:s'),
                    'to'   => (new \DateTime('-7 days'))->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable) {
            return 1.0; // таблиц GSC нет / БД-сбой — не тормозим
        }

        $checked = (int) ($row['checked'] ?? 0);
        if ($checked < 10) {
            return 1.0; // мало данных — не делаем выводов
        }

        $ratio = (int) $row['idx'] / $checked;

        return match (true) {
            $ratio < 0.10 => 0.25,
            $ratio < 0.30 => 0.5,
            default       => 1.0,
        };
    }

    /**
     * Жёсткий потолок дрипа по здоровью индексации ВСЕГО домена (доктрина пакета _seo,
     * index-guards): доля active-брендов в индексе среди проверенных GSC.
     *   < 5%  → 0 (полный стоп новых), < 10% → 1/день, иначе — без ограничения (null).
     *
     * Отличие от dripHealthMultiplier: тот смотрит динамику когорты 7-21д (множитель
     * темпа), этот — общий index-rate домена (жёсткий потолок). Применяются совместно.
     *
     * Fail-open: нет таблиц GSC / проверено < 20 → null (дрип не ограничиваем).
     * ⚠️ На проде gsc_index_status сейчас пуст (GSC синкается на Mac/.43) → guard
     * inert до появления данных на проде. См. docs/seo_adoption_plan.md п.4.
     */
    private function indexHealthCap(): ?int
    {
        try {
            $row = $this->em->getConnection()->fetchAssociative(
                "SELECT COUNT(s.id) checked, COALESCE(SUM(s.indexed),0) idx
                 FROM brand b
                 JOIN gsc_index_status s ON s.brand_id = b.id
                 WHERE b.status = 'active'",
            );
        } catch (\Throwable) {
            return null; // таблиц GSC нет / БД-сбой — не ограничиваем
        }

        $checked = (int) ($row['checked'] ?? 0);
        if ($checked < 20) {
            return null; // мало данных по домену — выводов не делаем
        }

        $ratio = (int) $row['idx'] / $checked;

        return match (true) {
            $ratio < 0.05 => 0,
            $ratio < 0.10 => 1,
            default       => null,
        };
    }
}
