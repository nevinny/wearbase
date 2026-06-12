<?php

namespace App\Command;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
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
 *     дневной таргет T(w) = min(CAP, round(START * (1+G)^w)) — старт 5/день,
 *     +12.5%/нед, потолок 28/день.
 *  4. Самокоррекция: p = (T - published_today) / оставшихся_тиков;
 *     за тик публикуем n = floor(p) + Bernoulli(frac(p)) — иначе CAP недостижим
 *     (15 тиков/день < 28 публикаций при «1 за тик»).
 *  5. Выбор СЛУЧАЙНЫХ готовых брендов (status='new' AND publish_pending=1,
 *     ORDER BY RAND()) → status='active' + published_at (попадает в каталог/sitemap).
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
    private const RATE_START  = 3.0;  // брендов/день на старте (слабый домен: 21/357 наших страниц в индексе — начинаем тише)
    private const RATE_GROWTH = 0.125;// +12.5% в неделю
    private const RATE_CAP    = 28;   // потолок брендов/день

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\IndexNowPinger $indexNow,
        private readonly \App\Service\BrandLinkGraphService $linkGraph,
        private readonly \App\Notification\AdminNotifier $notifier,
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

        $todayStart = (clone $now)->setTime(0, 0);
        $publishedToday = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM brand WHERE published_at >= :start',
            ['start' => $todayStart->format('Y-m-d H:i:s')],
        );

        $remaining = max(0, $target - $publishedToday);
        $ticksLeft = self::HOUR_TO - $hour + 1;
        $p = $ticksLeft > 0 ? $remaining / $ticksLeft : 0.0;
        $n = (int) floor($p) + ((mt_rand() / mt_getrandmax()) < fmod($p, 1.0) ? 1 : 0);

        $io->text(sprintf(
            '[%s МСК] неделя %d · таргет %d/день%s · опубликовано сегодня %d · тиков осталось %d · p=%.2f → n=%d',
            $now->format('H:i'), $week, $target,
            $health < 1.0 ? sprintf(' (заторможен ×%.2f: индексация когорты проседает)', $health) : '',
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

        // --- Случайные готовые бренды ---
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT id FROM brand WHERE status = 'new' AND publish_pending = 1 ORDER BY RAND() LIMIT " . $n,
        );

        if ($ids === []) {
            $io->text('Очередь публикации пуста (нет new + publish_pending).');
            return Command::SUCCESS;
        }

        $published = 0;
        $newUrls = [];
        $tgLines = [];
        foreach ($ids as $id) {
            $brand = $this->em->find(Brand::class, (int) $id);
            if ($brand === null) {
                continue;
            }
            $brand->setStatus(Statuses::Active)
                ->setPublishPending(false)
                // МСК, как и граница дня в published_today — иначе на UTC-проде счёт съезжает на 3ч
                ->setPublishedAt(new \DateTime('now', $tz));
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
            $tgLines[] = sprintf('• <a href="%s">%s</a>', $url, htmlspecialchars((string) $brand->getTitle()));
            $published++;
        }

        // IndexNow: мгновенный пинг Яндексу/Bing о новых URL (Google — через sitemap lastmod).
        // Fail-open: неуспех пинга публикацию не ломает.
        if ($newUrls !== [] && $this->indexNow->ping($newUrls)) {
            $io->text(sprintf('  → IndexNow: %d URL отправлено (Яндекс/Bing)', count($newUrls)));
        }

        // ТГ-уведомление со ссылками — для верификации человеком (fail-open).
        if ($tgLines !== [] && $this->notifier->isEnabled()) {
            try {
                $this->notifier->send("📢 <b>Дрип-публикация</b>\n" . implode("\n", $tgLines));
            } catch (\Throwable) {
                // уведомление не должно ломать публикацию
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
}
