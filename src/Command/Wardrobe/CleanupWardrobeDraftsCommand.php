<?php

declare(strict_types=1);

namespace App\Command\Wardrobe;

use App\Entity\WardrobeItemDraft;
use App\Repository\WardrobeItemDraftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vich\UploaderBundle\Storage\StorageInterface;

#[AsCommand(name: 'app:wardrobe:cleanup-drafts', description: 'Удаляет просроченные wardrobe drafts и AI-данные')]
final class CleanupWardrobeDraftsCommand extends Command
{
    public function __construct(private readonly WardrobeItemDraftRepository $drafts, private readonly EntityManagerInterface $em, private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void { $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать объём очистки'); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accepted = $this->drafts->findAcceptedBefore(new \DateTimeImmutable('-7 days'));
        $abandoned = $this->drafts->findAbandonedBefore(new \DateTimeImmutable('-30 days'));
        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Будет очищено: accepted media %d, abandoned drafts %d', count($accepted), count($abandoned)));
            return Command::SUCCESS;
        }
        foreach ($accepted as $draft) {
            $this->removePhoto($draft);
            $draft->clearSensitiveData();
        }
        foreach ($abandoned as $draft) {
            $this->removePhoto($draft);
            $this->em->remove($draft);
        }
        $this->em->flush();
        $io->success(sprintf('Очищено: accepted media %d, abandoned drafts %d', count($accepted), count($abandoned)));
        return Command::SUCCESS;
    }

    private function removePhoto(WardrobeItemDraft $draft): void
    {
        $path = $this->storage->resolvePath($draft, 'photoFile');
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
