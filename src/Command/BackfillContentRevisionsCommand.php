<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Service\BrandContentVersioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill: завести legacy-ревизию для каждого бренда с контентом, у которого ещё нет
 * истории. Одноразово — фиксирует текущее состояние как baseline, чтобы при первой
 * перегенерации ничего не потерять. Идемпотентна (ensureBaseline пропускает имеющих историю).
 *
 *   php bin/console app:brand:backfill-content-revisions
 */
#[AsCommand(name: 'app:brand:backfill-content-revisions', description: 'Завести legacy-ревизии контента для текущих брендов')]
class BackfillContentRevisionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandContentVersioner $versioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Размер батча для flush', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $batch = max(1, (int) $input->getOption('batch'));

        // Только бренды с контентом (новым без описания baseline ни к чему).
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT id FROM brand WHERE (description IS NOT NULL AND description <> '')
                OR (meta_title IS NOT NULL AND meta_title <> '') ORDER BY id",
        );
        $io->section(sprintf('Брендов с контентом: %d', count($ids)));

        $done = 0;
        foreach ($ids as $i => $id) {
            $brand = $this->em->find(Brand::class, (int) $id);
            if ($brand !== null) {
                $this->versioner->ensureBaseline($brand);
                $done++;
            }
            if (($i + 1) % $batch === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();

        $io->success(sprintf('Готово. Обработано %d (legacy-ревизии созданы для тех, у кого их не было).', $done));

        return Command::SUCCESS;
    }
}
