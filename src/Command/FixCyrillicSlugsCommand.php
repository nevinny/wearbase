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
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Одноразовый фикс: транслитерация кириллических слагов брендов (инцидент
 * «нечегонадеть» 2026-06-04 — ImportBrandsCommand брал слаг сырым из JSON).
 * Кириллица в URL вредит Google (percent-encoding, CTR, дубли).
 *
 * ВАЖНО: запускать с ОДИНАКОВЫМ алгоритмом на dev И на проде — slug является
 * ключом upsert агент-API; расхождение создаст дубли брендов на проде.
 * Алгоритм детерминирован: AsciiSlugger('ru') от title, lower; при коллизии
 * суффикс -{id} (id стабилен в рамках каждой БД, но title→slug совпадёт,
 * а коллизии редки и проверяются).
 *
 *   php bin/console app:brand:fix-slugs --dry-run
 *   php bin/console app:brand:fix-slugs
 */
#[AsCommand(
    name: 'app:brand:fix-slugs',
    description: 'Транслитерация кириллических слагов брендов (идемпотентно)',
)]
class FixCyrillicSlugsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать переименования, не сохранять');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $slugger = new AsciiSlugger('ru');

        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT id FROM brand WHERE slug REGEXP '[а-яА-ЯёЁ]'"
        );
        /** @var Brand[] $brands */
        $brands = $ids === [] ? [] : $this->em->getRepository(Brand::class)->findBy(['id' => $ids]);

        $io->title(sprintf('Кириллических слагов: %d', count($brands)));
        $renamed = 0;

        foreach ($brands as $brand) {
            $source = trim((string) $brand->getTitle()) ?: (string) $brand->getSlug();
            $new = strtolower((string) $slugger->slug($source));
            if ($new === '') {
                $io->warning(sprintf('  %d «%s»: пустой результат — пропуск', $brand->getId(), $brand->getTitle()));
                continue;
            }

            // Коллизия с другим брендом → детерминированный суффикс
            $existing = $this->em->getRepository(Brand::class)->findOneBy(['slug' => $new]);
            if ($existing !== null && $existing->getId() !== $brand->getId()) {
                $new .= '-' . $brand->getId();
            }

            $io->text(sprintf('  %d: %s → %s', $brand->getId(), $brand->getSlug(), $new));
            if (!$dryRun) {
                $brand->setSlug($new);
            }
            $renamed++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }
        $io->success(sprintf('%s: %d', $dryRun ? 'Будет переименовано' : 'Переименовано', $renamed));

        return Command::SUCCESS;
    }
}
