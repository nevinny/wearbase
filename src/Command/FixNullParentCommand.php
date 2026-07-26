<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Entity\Trait\DefaultFields;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Заменяет `parent = NULL` на `parent = 0` у сущностей на трейте {@see DefaultFields}.
 *
 * Зачем: листинг админки (`DefaultCrudController::createIndexQueryBuilder`) фильтрует
 * `entity.parent = 0`, а NULL под равенство не попадает — записи есть в БД, но в админке
 * невидимы. На проде так пряталось 3325 брендов из 3669.
 *
 * Идемпотентна: повторный запуск ничего не меняет. Новые записи держит
 * {@see \App\EventListener\DefaultParentSubscriber}.
 */
#[AsCommand(
    name: 'app:fix:null-parent',
    description: 'parent = NULL → 0 (иначе записи не видны в админке)',
)]
class FixNullParentCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, что будет исправлено');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $rows = [];
        $total = 0;

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            $class = $metadata->getName();

            if (!$this->usesDefaultFields($class) || $metadata->hasAssociation('parent')) {
                continue;
            }

            $affected = (int) $this->em->createQuery(
                sprintf('SELECT COUNT(e.id) FROM %s e WHERE e.parent IS NULL', $class)
            )->getSingleScalarResult();

            if ($affected === 0) {
                continue;
            }

            if (!$dryRun) {
                $this->em->createQuery(
                    sprintf('UPDATE %s e SET e.parent = 0 WHERE e.parent IS NULL', $class)
                )->execute();
            }

            $rows[] = [$metadata->getTableName(), $affected];
            $total += $affected;
        }

        if ($rows === []) {
            $io->success('Записей с parent = NULL нет — исправлять нечего.');

            return Command::SUCCESS;
        }

        $io->table(['Таблица', $dryRun ? 'Будет исправлено' : 'Исправлено'], $rows);
        $io->success(sprintf('%s %d записей', $dryRun ? 'К исправлению:' : 'Исправлено:', $total));

        if ($dryRun) {
            $io->note('Это dry-run — запустите без --dry-run, чтобы применить.');
        }

        return Command::SUCCESS;
    }

    private function usesDefaultFields(string $class): bool
    {
        for ($c = $class; $c !== false; $c = get_parent_class($c)) {
            if (in_array(DefaultFields::class, class_uses($c) ?: [], true)) {
                return true;
            }
        }

        return false;
    }
}
