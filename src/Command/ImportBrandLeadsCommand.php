<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Импорт брендов-лидов из файла имён (по одному на строку) — напр. список конкурента.
 * Создаёт Brand(status=new) с уникальным slug; дедуп по нормализованному имени/слагу
 * против существующих. Новые бренды (status=new, нет discoveredAt) автоматически входят
 * в discover→fetch→embed→generate и становятся целями enrich-contacts/outreach.
 *
 * НЕ трогает существующие бренды и публичный контент (new ≠ публичный статус).
 *
 *   php bin/console app:brand:import-leads var/vitrine-leads.txt --source=vitrine --dry-run
 *   php bin/console app:brand:import-leads var/vitrine-leads.txt --source=vitrine
 */
#[AsCommand(name: 'app:brand:import-leads', description: 'Импорт брендов-лидов из файла имён → Brand(status=new) для discover/outreach')]
class ImportBrandLeadsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SluggerInterface $slugger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Файл с именами брендов (по одному на строку)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Метка источника лидов (для лога)', 'lead')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать, что было бы создано')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $file   = (string) $input->getArgument('file');
        $source = (string) $input->getOption('source');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($file)) {
            $io->error("Файл не найден: {$file}");
            return Command::FAILURE;
        }
        $names = array_values(array_unique(array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES)))));
        $io->section(sprintf('Источник «%s»: имён в файле %d%s', $source, count($names), $dryRun ? ' (dry-run)' : ''));

        // нормализованный индекс существующих (имя/слаг) для дедупа
        $existing = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT title, slug FROM brand') as $b) {
            foreach ([$b['title'], $b['slug']] as $k) {
                $n = $this->norm((string) $k);
                if ($n !== '') { $existing[$n] = true; }
            }
        }

        $created = $skipped = 0;
        foreach ($names as $i => $name) {
            if (isset($existing[$this->norm($name)])) {
                $skipped++;
                continue;
            }
            $created++;
            if ($dryRun) {
                continue;
            }
            $slug = $this->uniqueSlug($name);
            $brand = (new Brand())
                ->setTitle($name)
                ->setSlug($slug)
                ->setStatus(Statuses::New);
            $this->em->persist($brand);
            $existing[$this->norm($name)] = true; // дедуп внутри одного прогона
            $existing[$this->norm($slug)] = true;

            if (($i + 1) % 100 === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('Создано %d, пропущено (уже есть) %d. Новые войдут в discover автоматически.', $created, $skipped));

        return Command::SUCCESS;
    }

    private function norm(string $s): string
    {
        return preg_replace('~[^\p{L}\p{N}]+~u', '', mb_strtolower(trim($s))) ?? '';
    }

    private function uniqueSlug(string $title): string
    {
        $base = strtolower((string) $this->slugger->slug($title));
        if ($base === '') {
            $base = 'brand';
        }
        $slug = $base;
        $i = 1;
        while ($this->em->getRepository(Brand::class)->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
