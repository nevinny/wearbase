<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdvisorIdea;
use App\Entity\AdvisorRun;
use App\Entity\StateSnapshot;
use App\Notification\AdminNotifier;
use App\Repository\AdvisorIdeaRepository;
use App\Repository\StateSnapshotRepository;
use App\Service\Advisor\AnomalyDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Тик советника (docs/advisor.md §Формат дайджеста). В этой итерации — ДЕТЕРМИНИРОВАННЫЙ
 * дайджест без LLM/Claude-мозга: последний StateSnapshot+дельта, топ proposed-идей и
 * эксперименты в статусе measuring рендерятся по фиксированному шаблону и уходят в TG
 * через AdminNotifier. Генерация новых идей — следующая итерация (Claude-мозг).
 *
 *   php bin/console app:advisor:tick            # сформировать, сохранить AdvisorRun, отправить в TG
 *   php bin/console app:advisor:tick --dry-run  # только напечатать дайджест, НЕ слать и НЕ сохранять
 */
#[AsCommand(name: 'app:advisor:tick', description: 'Детерминированный дайджест советника в Telegram (шаг рендера, без LLM)')]
class AdvisorTickCommand extends Command
{
    /** Порядок и подписи метрик для Δ-строки и выбора вердикта. */
    private const LABELS = [
        'yandex_clicks'         => 'клики Яндекс',
        'yandex_shows'          => 'показы Яндекс',
        'yandex_in_search'      => 'в поиске Яндекс',
        'gsc_indexed'           => 'в индексе Google',
        'gsc_ever'              => 'индексир. когда-либо',
        'brands_active'         => 'активных брендов',
        'brands_new'            => 'брендов в очереди',
        'contacts_email'        => 'брендов с email',
        'keywords_total'        => 'ключевиков',
        'pipeline_done'         => 'pipeline done',
        'pipeline_review'       => 'pipeline review',
        'prod_published_total'  => 'опубликовано (прод)',
        'prod_queue_pending'    => 'очередь дрипа (прод)',
        'subscriptions_active'  => 'подписок',
        'subscriptions_trial'   => 'подписок (trial)',
    ];

    /** Сколько строк сигналов показываем в дайджесте, остальное — «…и ещё N». */
    private const SIGNALS_SHOWN = 5;

    /** Ранг severity для сортировки high→low. */
    private const SEVERITY_RANK = [
        AnomalyDetector::SEV_HIGH   => 0,
        AnomalyDetector::SEV_MEDIUM => 1,
        AnomalyDetector::SEV_LOW    => 2,
    ];

    public function __construct(
        private readonly StateSnapshotRepository $snapshots,
        private readonly AdvisorIdeaRepository $ideas,
        private readonly AdminNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly AnomalyDetector $detector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только напечатать дайджест, не слать в TG и не сохранять AdvisorRun');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $snap = $this->snapshots->findLatest();
        if ($snap === null) {
            $io->error('Нет ни одного StateSnapshot. Сначала: app:advisor:snapshot.');
            return Command::FAILURE;
        }

        $proposed  = $this->ideas->findTopProposed(3);
        $shipped   = $this->ideas->findBy(['status' => AdvisorIdea::STATUS_SHIPPED], ['updatedAt' => 'DESC'], 5);
        $measuring = $this->ideas->findBy(['status' => AdvisorIdea::STATUS_MEASURING], ['updatedAt' => 'DESC'], 5);

        $signals = $this->detector->detect($snap);
        $this->sortSignals($signals);

        $digest = $this->render($snap, $signals, $proposed, $shipped, $measuring);

        $io->text(strip_tags($digest));

        if ($dryRun) {
            $io->success(sprintf('dry-run: дайджест %d знаков, не отправлен и не сохранён.', mb_strlen($digest)));
            return Command::SUCCESS;
        }

        $run = (new AdvisorRun())
            ->setMode(AdvisorRun::MODE_SCHEDULED)
            ->setInputsSummary(sprintf(
                'snapshot#%d · proposed %d · shipped %d · measuring %d',
                $snap->getId(), count($proposed), count($shipped), count($measuring),
            ))
            ->setDigestText($digest)
            ->setDecisions([
                'snapshot_id'   => $snap->getId(),
                'signals'       => $signals,
                'proposed_ids'  => array_map(static fn(AdvisorIdea $i) => $i->getId(), $proposed),
                'shipped_ids'   => array_map(static fn(AdvisorIdea $i) => $i->getId(), $shipped),
                'measuring_ids' => array_map(static fn(AdvisorIdea $i) => $i->getId(), $measuring),
            ]);
        $this->em->persist($run);
        $this->em->flush();

        if (!$this->notifier->isEnabled()) {
            $io->warning('Telegram не настроен (ADMIN_TELEGRAM_CHAT_ID) — AdvisorRun сохранён, но не отправлен.');
            return Command::SUCCESS;
        }
        $this->notifier->send($digest);
        $io->success(sprintf('AdvisorRun #%d сохранён и отправлен в TG.', $run->getId()));

        return Command::SUCCESS;
    }

    /**
     * @param list<array{key:string,message:string,severity:string,value:int|float|null,delta:int|float|null}> $signals
     * @param AdvisorIdea[] $proposed
     * @param AdvisorIdea[] $shipped
     * @param AdvisorIdea[] $measuring
     */
    private function render(StateSnapshot $snap, array $signals, array $proposed, array $shipped, array $measuring): string
    {
        $metrics = $snap->getMetrics();
        $delta   = $snap->getDelta();

        $parts = [];
        $parts[] = '<b>🧭 Советник · ' . $this->moscowDate() . '</b>';
        $parts[] = $this->verdict($metrics, $delta, $signals);  // вывод-вердикт первым абзацем
        $signalsBlock = $this->signalsSection($signals);        // ⚠️ Сигналы — сразу после вердикта
        if ($signalsBlock !== null) {
            $parts[] = $signalsBlock;
        }
        $parts[] = $this->deltaLine($metrics, $delta);         // Δ-строка метрик

        if ($shipped !== []) {
            $rows = array_map(
                static fn(AdvisorIdea $i) => '• ' . htmlspecialchars((string) $i->getTitle()),
                $shipped,
            );
            $parts[] = "<b>✅ Сделано:</b>\n" . implode("\n", $rows);
        }

        if ($proposed !== []) {
            $rows = array_map(
                static fn(AdvisorIdea $i) => sprintf(
                    '• %s (ICE %d)',
                    htmlspecialchars((string) $i->getTitle()),
                    $i->getIceScore(),
                ),
                $proposed,
            );
            $parts[] = "<b>💡 Предлагаю:</b>\n" . implode("\n", $rows);
        }

        if ($measuring !== []) {
            $rows = array_map(
                static fn(AdvisorIdea $i) => '• ' . htmlspecialchars((string) $i->getTitle()),
                $measuring,
            );
            $parts[] = "<b>🧪 Идут эксперименты:</b>\n" . implode("\n", $rows);
        }

        if ($proposed === [] && $shipped === [] && $measuring === []) {
            $parts[] = '<i>Бэклог пуст — идеи появятся, когда подключим Claude-мозг (следующая итерация).</i>';
        }

        $text = implode("\n\n", $parts);

        // Гард ≤4000 знаков (TG-лимит на одно сообщение).
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3990) . '…';
        }

        return $text;
    }

    /**
     * Секция «⚠️ Сигналы»: детерминированные аномалии, отсортированы high→low, максимум
     * SIGNALS_SHOWN строк, остальное — «…и ещё N». Пусто → секции нет (null).
     * @param list<array{key:string,message:string,severity:string,value:int|float|null,delta:int|float|null}> $signals
     */
    private function signalsSection(array $signals): ?string
    {
        if ($signals === []) {
            return null;
        }

        $icons = [
            AnomalyDetector::SEV_HIGH   => '🔴',
            AnomalyDetector::SEV_MEDIUM => '🟠',
            AnomalyDetector::SEV_LOW    => '🟡',
        ];

        $shown = array_slice($signals, 0, self::SIGNALS_SHOWN);
        $rows  = array_map(
            static fn(array $s) => ($icons[$s['severity']] ?? '•') . ' ' . htmlspecialchars($s['message']),
            $shown,
        );
        $rest = count($signals) - count($shown);
        if ($rest > 0) {
            $rows[] = sprintf('…и ещё %d', $rest);
        }

        return "<b>⚠️ Сигналы:</b>\n" . implode("\n", $rows);
    }

    /**
     * Стабильная сортировка сигналов по severity high→low (PHP 8 usort стабилен).
     * @param list<array{key:string,message:string,severity:string,value:int|float|null,delta:int|float|null}> $signals
     */
    private function sortSignals(array &$signals): void
    {
        usort(
            $signals,
            static fn(array $a, array $b) =>
                (self::SEVERITY_RANK[$a['severity']] ?? 9) <=> (self::SEVERITY_RANK[$b['severity']] ?? 9),
        );
    }

    /**
     * Вывод-вердикт: при наличии high-аномалии — про неё; иначе главное изменение дельты
     * (метрика с максимальным |Δ| среди подписанных).
     * @param array<string,int|float> $metrics
     * @param array<string,int|float>|null $delta
     * @param list<array{key:string,message:string,severity:string,value:int|float|null,delta:int|float|null}> $signals
     */
    private function verdict(array $metrics, ?array $delta, array $signals): string
    {
        foreach ($signals as $s) {
            if ($s['severity'] === AnomalyDetector::SEV_HIGH) {
                return 'Главное: ' . $s['message'] . '.';
            }
        }

        if ($delta === null) {
            return 'Первый снимок — базовая точка, дельты пока нет.';
        }

        $bestKey = null;
        $bestAbs = 0;
        foreach (self::LABELS as $key => $_) {
            if (!isset($delta[$key])) {
                continue;
            }
            $abs = abs((int) $delta[$key]);
            if ($abs > $bestAbs) {
                $bestAbs = $abs;
                $bestKey = $key;
            }
        }

        if ($bestKey === null || $bestAbs === 0) {
            return 'Без заметных изменений с прошлого снимка.';
        }

        $d    = (int) $delta[$bestKey];
        $now  = (int) ($metrics[$bestKey] ?? 0);
        $prev = $now - $d;

        return sprintf(
            'Главное: %s %s на %d (%d → %d).',
            self::LABELS[$bestKey],
            $d > 0 ? 'выросли' : 'снизились',
            abs($d),
            $prev,
            $now,
        );
    }

    /**
     * Δ-строка: подписанные метрики со значением и дельтой (если есть).
     * @param array<string,int|float> $metrics
     * @param array<string,int|float>|null $delta
     */
    private function deltaLine(array $metrics, ?array $delta): string
    {
        $chunks = [];
        foreach (self::LABELS as $key => $label) {
            if (!array_key_exists($key, $metrics)) {
                continue;
            }
            $val = (int) $metrics[$key];
            $d   = $delta[$key] ?? null;
            $chunks[] = $d === null || (int) $d === 0
                ? sprintf('%s %d', $label, $val)
                : sprintf('%s %d (%+d)', $label, $val, (int) $d);
        }

        return '<b>Δ метрик:</b> ' . ($chunks === [] ? '—' : implode(' · ', $chunks));
    }

    private function moscowDate(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('d.m H:i');
    }
}
