<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Entity\WardrobeItemDraft;
use App\Repository\WardrobeItemDraftRepository;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\Wardrobe\WardrobeActivationService;
use App\Service\WardrobeAiMeter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Стадия конвейера авто-инжеста гардероба: распознаёт ожидающие черновики
 * (WardrobeItemDraft::STATUS_PENDING) через WardrobeAiService::suggestFromPhoto.
 * Идемпотентно — обрабатывает только pending, повторный запуск не трогает
 * уже recognized/failed. Результат сохраняется атомарно только владельцем
 * актуального worker claim, поэтому устаревший worker не затирает повторный запуск.
 *
 * flock — защита от параллельного второго экземпляра (паттерн RagDaemonCommand).
 */
#[AsCommand(
    name: 'app:wardrobe:ingest-drafts',
    description: 'Гардероб: распознать ожидающие черновики фото (авто-инжест)',
)]
class IngestWardrobeDraftsCommand extends Command
{
    /** @var resource|null держим открытым весь прогон (flock) */
    private $lockHandle = null;

    public function __construct(
        private readonly WardrobeItemDraftRepository $draftRepo,
        private readonly WardrobeAiService $ai,
        private readonly StorageInterface $vichStorage,
        private readonly WardrobeAiMeter $meter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?WardrobeActivationService $activation = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Ограничить черновиками одного batch_id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько черновиков обработать за прогон', '15')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->acquireLock()) {
            $io->note('Другой экземпляр уже запущен (var/wardrobe_ingest_drafts.lock) — выходим.');
            return Command::SUCCESS;
        }

        $batch = $input->getOption('batch');
        $limit = max(1, (int) $input->getOption('limit'));

        $workerId = sprintf('%s:%d', gethostname() ?: 'worker', getmypid());
        $drafts = $this->draftRepo->claimPending($limit, $workerId, is_string($batch) ? $batch : null);

        if ($drafts === []) {
            $io->text('Нет ожидающих черновиков.');
            return Command::SUCCESS;
        }

        $recognized = 0;
        $failed = 0;
        $batches = [];

        foreach ($drafts as $draft) {
            $subject = $draft->getProfileSubject();
            $actor = $draft->getActor();
            $batchId = $draft->getBatchId();
            if ($subject !== null && $actor !== null && $batchId !== null) {
                $batchKey = $subject->getId().':'.$batchId;
                if (!isset($batches[$batchKey])) {
                    $batches[$batchKey] = [$actor, $subject, $batchId];
                    $this->activation?->batchRecognitionStarted($actor, $subject, $batchId);
                }
            }
            $draftId = $draft->getId();
            if ($draftId === null) {
                continue;
            }
            if (!$this->meter->allowed()) {
                $this->draftRepo->releaseClaimForRetry($draftId, $workerId, 'Дневной лимит AI-запросов исчерпан');
                $io->warning('Дневной лимит AI-запросов исчерпан — черновик возвращён в очередь.');
                continue;
            }

            $path = $this->vichStorage->resolvePath($draft, 'photoFile');
            if ($path === null || !is_file($path)) {
                if ($this->draftRepo->finishClaim($draftId, $workerId, WardrobeItemDraft::STATUS_FAILED, error: 'Файл фото не найден')) {
                    $failed++;
                    $io->text(sprintf('#%d: файл фото не найден — failed', $draftId));
                }
                continue;
            }

            if (!$this->draftRepo->extendLease($draftId, $workerId)) {
                $io->note(sprintf('#%d: claim уже передан другому worker.', $draftId));
                continue;
            }

            try {
                $result = $this->ai->suggestFromPhoto($path, $draft->getProfileSubject());
            } catch (\Throwable $exception) {
                if ($draft->getAttempts() >= 3) {
                    if ($this->draftRepo->finishClaim($draftId, $workerId, WardrobeItemDraft::STATUS_FAILED, error: $exception->getMessage())) {
                        $failed++;
                    }
                } else {
                    $this->draftRepo->releaseClaimForRetry($draftId, $workerId, $exception->getMessage());
                }
                $io->text(sprintf('#%d: временная ошибка распознавания', $draftId));
                continue;
            }

            if ($result['ok'] ?? false) {
                $fields = $result['fields'] ?? [];
                $saved = $this->draftRepo->finishClaim($draftId, $workerId, WardrobeItemDraft::STATUS_RECOGNIZED, [
                    'category' => $this->nullableString($fields['category'] ?? null),
                    'name' => $this->nullableString($fields['name'] ?? null),
                    'size' => $this->nullableString($fields['size'] ?? null),
                    'notes' => $this->nullableString($fields['notes'] ?? null),
                    'confidence' => $this->nullableString($result['confidence'] ?? null),
                    'aiRaw' => array_filter([
                        'confidence' => $this->nullableString($result['confidence'] ?? null),
                        'model' => $this->nullableString($result['model'] ?? null),
                    ], static fn (mixed $value): bool => $value !== null),
                ]);
                if ($saved) {
                    $recognized++;
                    $io->text(sprintf('#%d: распознано (confidence=%s)', $draftId, $result['confidence'] ?? '?'));
                }
            } else {
                $error = mb_substr((string) ($result['error'] ?? 'Ошибка распознавания'), 0, 255);
                if ($this->draftRepo->finishClaim($draftId, $workerId, WardrobeItemDraft::STATUS_FAILED, error: $error)) {
                    $failed++;
                    $io->text(sprintf('#%d: ошибка — %s', $draftId, $error));
                }
            }
        }

        foreach ($batches as [$actor, $subject, $batchId]) {
            $counts = $this->draftRepo->countsByBatch($subject, $batchId);
            if ($counts['total'] === $counts['recognized'] + $counts['failed']) {
                $this->activation?->batchRecognitionCompleted($actor, $subject, $batchId);
            }
        }

        $io->success(sprintf('Готово: распознано %d, ошибок %d.', $recognized, $failed));

        return Command::SUCCESS;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** Эксклюзивный flock — защита от случайного второго экземпляра (паттерн RagDaemonCommand). */
    private function acquireLock(): bool
    {
        $path = $this->projectDir . '/var/wardrobe_ingest_drafts.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->lockHandle = $handle; // держим открытым — иначе GC снимет lock

        return true;
    }
}
