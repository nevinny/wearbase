<?php

namespace App\Command;

use App\Entity\Brand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsCommand(
    name: 'app:import:brands',
    description: 'Импорт брендов из сайта russianstreetwear.club'
)]
class ImportBrandsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SluggerInterface              $slugger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Читаем HTML файл
        $htmlContent = file_get_contents('file.html');
        if ($htmlContent === false) {
            $io->error('htmlContent empty');
            return Command::FAILURE;
        }

        // Извлекаем JSON из script тега
        preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $htmlContent, $matches);

        if (!isset($matches[1])) {
            $io->error('JSON data not found in HTML file');
            return Command::FAILURE;
        }
        // JSON данные (вы можете получить их из файла или другого источника)


        $data = json_decode($matches[1], true);
        $brands = $data['props']['pageProps']['brands'] ?? [];

        if (empty($brands)) {
            $io->error('No brands found in JSON data');
            return Command::FAILURE;
        }

        $io->progressStart(count($brands));

        $this->importBrands($brands, $io);

        $this->entityManager->flush();
        $io->progressFinish();

        $io->success(sprintf('Successfully imported %d brands', count($brands)));

        return Command::SUCCESS;
    }

    private function importBrands(array $brands, SymfonyStyle $io): int
    {
        $successCount = 0;
        $errorCount = 0;
        $existingBrands = $this->entityManager->getRepository(Brand::class)
            ->findAll();

        $localBrands = [];
        foreach ($existingBrands as $existingBrand)
        {
            $brandName = $existingBrand->getTitle();
            $brandName = trim($brandName);
            $localBrands[$brandName] = $existingBrand;
        }

        foreach ($brands as $index => $brandData) {
            try {
                // Проверяем обязательные поля
                if (empty($brandData['title'])) {
                    $io->warning(sprintf('Brand at index %d has no title, skipping...', $index));
                    $errorCount++;
                    continue;
                }
                $title = $brandData['title'];
                $title = trim($title);

//                $existingBrand = $this->entityManager->getRepository(Brand::class)
//                    ->findOneBy(['title' => $brandData['title']]);

                if(array_key_exists($title, $localBrands)) {
                    $io->note(sprintf('Brand "%s" already exists', $brandData['title']));
//                    continue;
                    $brand = $localBrands[$title];
                } else {
                    $brand = new Brand();
                }
//                if ($existingBrand) {
//                    $io->note(sprintf('Brand "%s" already exists', $brandData['title']));
//                    continue;
//                }

//                $brand = new Brand();


                $brand->setTitle($title);
//                $brand->setSlug((string)strtolower($this->slugger->slug($title)));
                $brand->setSlug($brandData['slug']);
                $brand->setLogo($brandData['logo']['path'] ?? null);
                $brand->setCity($brandData['city'] ?? null);

                $this->entityManager->persist($brand);
                $successCount++;

            } catch (\Exception $e) {
                $io->error(sprintf('Error importing brand "%s": %s',
                    $brandData['title'] ?? 'unknown',
                    $e->getMessage()
                ));
                $errorCount++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Imported: %d, Errors: %d', $successCount, $errorCount));

        return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
