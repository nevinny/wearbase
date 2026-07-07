<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdvisorIdea;
use App\Entity\AdvisorRun;
use App\Entity\StateSnapshot;
use App\Notification\AdminNotifier;
use App\Repository\AdvisorIdeaRepository;
use App\Repository\StateSnapshotRepository;
use App\Service\Advisor\AdvisorRag;
use App\Service\Advisor\AnomalyDetector;
use App\Service\LlmService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Тик советника (docs/advisor.md §Формат дайджеста + §Мозг). Детерминированное ядро —
 * последний StateSnapshot+дельта, сигналы AnomalyDetector, proposed/measuring — рендерятся
 * по фиксированному шаблону и уходят в TG через AdminNotifier. ПОВЕРХ ядра — BEST-EFFORT
 * мозг: при наличии сигналов ретрив принципов каналов (AdvisorRag → topic_chunks) +
 * grounded-генерация идей на gemma (LlmService::generateAdvisorIdeas), дедуп по dedupeHash,
 * ICE-скор. Мозг обёрнут в try/catch: недоступна gemma/пуст ретрив/таймаут → дайджест всё
 * равно выходит детерминированным, без идей. Мозг не роняет дайджест.
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
        private readonly AdvisorRag $rag,
        private readonly LlmService $llm,
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

        // BEST-EFFORT мозг: ретрив принципов + grounded-идеи на gemma. Любой сбой — тихо
        // деградируем к детерминированному дайджесту (мозг не роняет tick).
        $freshIdeas = [];
        $ideaNote   = 'idea-gen пропущен (нет сигналов)';
        if ($signals !== []) {
            try {
                $gen        = $this->generateIdeas($snap, $signals);
                $freshIdeas = $gen['ideas'];
                $ideaNote   = $gen['note'];
            } catch (\Throwable $e) {
                $ideaNote = 'idea-gen best-effort пропущен: ' . $e->getMessage();
            }
        }

        // «💡 Предлагаю» — топ по ICE из свежих + уже лежащих в бэклоге proposed (дедуп по hash).
        $proposedForDigest = $this->topByIce($freshIdeas, $proposed, 3);

        $digest = $this->render($snap, $signals, $proposedForDigest, $shipped, $measuring);

        $io->text(strip_tags($digest));
        $io->note($ideaNote);

        if ($dryRun) {
            foreach ($freshIdeas as $i) {
                $io->writeln(sprintf(
                    '  идея: %s [I%d·C%d·E%d → ICE %d] %s',
                    $i->getTitle(), $i->getImpact(), $i->getConfidence(), $i->getEase(), $i->getIceScore(),
                    implode('; ', $i->getRagCitations() ?? []),
                ));
            }
            $io->success(sprintf(
                'dry-run: дайджест %d знаков, свежих идей %d — не отправлено и не сохранено.',
                mb_strlen($digest), count($freshIdeas),
            ));
            return Command::SUCCESS;
        }

        foreach ($freshIdeas as $i) {
            $this->em->persist($i);
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
                'fresh_ideas'   => array_map(static fn(AdvisorIdea $i) => [
                    'title'     => $i->getTitle(),
                    'ice'       => $i->getIceScore(),
                    'citations' => $i->getRagCitations(),
                ], $freshIdeas),
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
            $rows = array_map($this->proposedRow(...), $proposed);
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
     * Строка идеи в секции «💡 Предлагаю»: заголовок+ICE, краткая гипотеза, провенанс канала.
     */
    private function proposedRow(AdvisorIdea $i): string
    {
        $line = sprintf('• <b>%s</b> (ICE %d)', htmlspecialchars((string) $i->getTitle()), $i->getIceScore());

        $hypo = trim((string) $i->getHypothesis());
        if ($hypo !== '') {
            if (mb_strlen($hypo) > 220) {
                $hypo = mb_substr($hypo, 0, 217) . '…';
            }
            $line .= "\n  " . htmlspecialchars($hypo);
        }

        $cites = $i->getRagCitations() ?? [];
        if ($cites !== []) {
            $line .= "\n  <i>источник: " . htmlspecialchars(implode('; ', array_slice($cites, 0, 2))) . '</i>';
        }

        return $line;
    }

    /**
     * BEST-EFFORT idea-gen: query из топ-сигналов → ретрив принципов (idea/framing/case) →
     * grounded-генерация на gemma → дедуп по dedupeHash (вкл. отклонённые) → AdvisorIdea (proposed).
     * Сущности НЕ persist здесь — это делает execute (dry-run их только печатает).
     *
     * @param list<array{key:string,message:string,severity:string,value:int|float|null,delta:int|float|null}> $signals
     * @return array{ideas: list<AdvisorIdea>, note: string}
     */
    private function generateIdeas(StateSnapshot $snap, array $signals): array
    {
        $query = implode('. ', array_map(
            static fn(array $s) => (string) $s['message'],
            array_slice($signals, 0, 3),
        ));

        $chunks  = $this->rag->retrieve($query, AdvisorRag::IDEA_ROLES, 6);
        $context = $this->rag->formatContext($chunks);
        $raw     = $this->llm->generateAdvisorIdeas($snap->getMetrics(), $signals, $context);

        $ideas = [];
        $seen  = [];
        foreach ($raw as $r) {
            $hash = $this->dedupeHash($r['title']);
            if (isset($seen[$hash]) || $this->ideas->findByDedupeHash($hash) !== null) {
                continue; // уже предлагали (в т.ч. отклонённую) — не по кругу
            }
            $seen[$hash] = true;

            $ideas[] = (new AdvisorIdea())
                ->setTitle($r['title'])
                ->setHypothesis($r['hypothesis'])
                ->setSourceSignal($r['source_signal'])
                ->setRagCitations($this->resolveCitations($r['rag_citations'], $chunks))
                ->setImpact($r['impact'])
                ->setConfidence($r['confidence'])
                ->setEase($r['ease'])
                ->setIceScore($r['impact'] * $r['confidence'] * $r['ease'])
                ->setDedupeHash($hash);
        }

        return [
            'ideas' => $ideas,
            'note'  => sprintf('idea-gen: чанков %d, сгенерировано %d, свежих (после дедупа) %d', count($chunks), count($raw), count($ideas)),
        ];
    }

    /** Нормализованный dedupe-hash заголовка (lower/trim/схлопнуть пробелы). */
    private function dedupeHash(string $title): string
    {
        $norm = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($title)));

        return sha1($norm);
    }

    /**
     * Метки «#N» из rag_citations → «Канал:video_id» по чанкам ретрива (провенанс в дайджест).
     * Нераспознанные метки оставляем как есть.
     *
     * @param list<string> $labels
     * @param list<array{channel:string,role:string,video_id:string,text:string,score:float}> $chunks
     * @return list<string>
     */
    private function resolveCitations(array $labels, array $chunks): array
    {
        $out = [];
        foreach ($labels as $label) {
            if (preg_match('/(\d+)/', $label, $m)) {
                $idx = (int) $m[1] - 1;
                if (isset($chunks[$idx])) {
                    $c = $chunks[$idx];
                    $out[] = AdvisorRag::channelName($c['channel']) . ($c['video_id'] !== '' ? ':' . $c['video_id'] : '');
                    continue;
                }
            }
            $out[] = $label;
        }

        return array_values(array_unique($out));
    }

    /**
     * Топ идей для дайджеста по ICE из свежих + бэклога proposed, дедуп по dedupeHash.
     *
     * @param list<AdvisorIdea> $fresh
     * @param AdvisorIdea[] $existing
     * @return list<AdvisorIdea>
     */
    private function topByIce(array $fresh, array $existing, int $limit): array
    {
        $byHash = [];
        foreach ([...$fresh, ...$existing] as $i) {
            $h = (string) $i->getDedupeHash();
            $byHash[$h] ??= $i; // свежие идут первыми — при коллизии выигрывают
        }
        $list = array_values($byHash);
        usort($list, static fn(AdvisorIdea $a, AdvisorIdea $b) => $b->getIceScore() <=> $a->getIceScore());

        return array_slice($list, 0, $limit);
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
