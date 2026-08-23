<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Entity\WardrobeItemDraft;
use App\Repository\WardrobeItemDraftRepository;
use App\Service\Wardrobe\WardrobeAiService;
use App\Service\WardrobeAiMeter;
use Doctrine\ORM\EntityManagerInterface;
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
 * уже recognized/failed. flush() по каждому черновику — прогресс виден
 * и прогон резюмируется, если оборвался.
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
        private readonly EntityManagerInterface $em,
        private readonly StorageInterface $vichStorage,
        private readonly WardrobeAiMeter $meter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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

        $drafts = $this->draftRepo->findPending($limit, is_string($batch) ? $batch : null);

        if ($drafts === []) {
            $io->text('Нет ожидающих черновиков.');
            return Command::SUCCESS;
        }

        $recognized = 0;
        $failed = 0;

        foreach ($drafts as $draft) {
            if (!$this->meter->allowed()) {
                // Дневной потолок исчерпан (только remote-ветка WardrobeAiService — при локальной
                // ollama meter никогда не расходуется, этот break не срабатывает). Оставляем
                // остаток pending — доберём на следующем прогоне после сброса потолка.
                $io->warning('Дневной лимит AI-запросов исчерпан — оставляем оставшиеся черновики pending.');
                break;
            }

            $path = $this->vichStorage->resolvePath($draft, 'photoFile');
            if ($path === null || !is_file($path)) {
                $draft->setStatus(WardrobeItemDraft::STATUS_FAILED);
                $draft->setError('Файл фото не найден');
                $this->em->flush();
                $failed++;
                $io->text(sprintf('#%d: файл фото не найден — failed', $draft->getId()));
                continue;
            }

            $result = $this->ai->suggestFromPhoto($path, $draft->getProfileSubject());

            if ($result['ok'] ?? false) {
                $fields = $result['fields'] ?? [];
                $draft->setCategory($fields['category'] ?? null);
                $draft->setName($fields['name'] ?? null);
                $draft->setSize($fields['size'] ?? null);
                $draft->setNotes($fields['notes'] ?? null);
                $draft->setConfidence($result['confidence'] ?? null);
                $draft->setAiRaw($result);
                $draft->setStatus(WardrobeItemDraft::STATUS_RECOGNIZED);
                $recognized++;
                $io->text(sprintf('#%d: распознано (confidence=%s)', $draft->getId(), $result['confidence'] ?? '?'));
            } else {
                $error = mb_substr((string) ($result['error'] ?? 'Ошибка распознавания'), 0, 255);
                $draft->setStatus(WardrobeItemDraft::STATUS_FAILED);
                $draft->setError($error);
                $failed++;
                $io->text(sprintf('#%d: ошибка — %s', $draft->getId(), $error));
            }

            $this->em->flush();
        }

        $io->success(sprintf('Готово: распознано %d, ошибок %d.', $recognized, $failed));

        return Command::SUCCESS;
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
