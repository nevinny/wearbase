<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandImage;
use App\Entity\BrandLink;
use App\Service\AlphabetManagerService;
use DateTime;
use Nevinny\AdminCoreBundle\Service\ImageDownloadService;
use Nevinny\AdminCoreBundle\Service\WebPageCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:import:brand-media',
    description: 'Импорт brand images and links из сайта russianstreetwear.club'
)]
class ImportBrandMediaCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private WebPageCacheService $cacheService;
    private AlphabetManagerService $alphabetManagerService;
    private bool $useCache;
    private string $uploadDir;

    public function __construct(
        EntityManagerInterface       $entityManager,
        HttpClientInterface          $httpClient,
        LoggerInterface              $logger,
        WebPageCacheService          $cacheService,
        private ImageDownloadService $imageDownloader,
        AlphabetManagerService     $alphabetManagerService,
        KernelInterface     $kernel,
    )
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->cacheService = $cacheService;
        $this->useCache = true;
        $this->alphabetManagerService = $alphabetManagerService;
        $this->uploadDir = $kernel->getProjectDir() . '/public_html/images/';
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Лимит обработки записей', 100)
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Размер батча для флаша', 25)
            ->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'Запись с указанным id', '')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Отключить кеширование')
            ->addOption('no-images', null, InputOption::VALUE_NONE, 'Не загружать изображения')
            ->addOption('update-logos', null, InputOption::VALUE_NONE, 'Обновить логотипы')
            ->addOption('clear-cache', null, InputOption::VALUE_OPTIONAL, 'Очистить кеш для домена (или весь)', '')
            ->addOption('cache-stats', null, InputOption::VALUE_NONE, 'Показать статистику кеша')
            ->addOption('cache-ttl', null, InputOption::VALUE_OPTIONAL, 'TTL кеша в секундах', (60 * 60 * 4)) // ttl = 4 hour
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Обработка опций управления кешем
        if ($input->getOption('clear-cache') !== '') {
            $domain = $input->getOption('clear-cache') ?: null;
            $this->cacheService->clearCache($domain);
            $io->success($domain ? "Кеш для домена {$domain} очищен" : "Весь кеш очищен");
            return Command::SUCCESS;
        }

        if ($input->getOption('cache-stats')) {
            $stats = $this->cacheService->getCacheStats();
            $io->title('Статистика кеша');
            $io->text(sprintf('Всего файлов: %d', $stats['total_files']));
            $io->text(sprintf('Общий размер: %.2f MB', $stats['total_size_mb']));

            if ($stats['domains']) {
                $io->section('По доменам:');
                foreach ($stats['domains'] as $domain => $domainStats) {
                    $io->text(sprintf('  %s: %d файлов (%.2f MB)', $domain, $domainStats['files'], $domainStats['size_mb']));
                }
            }
            return Command::SUCCESS;
        }

        $this->useCache = !$input->getOption('no-cache');
        $downloadImages = !$input->getOption('no-images');
        $updateLogos = $input->getOption('update-logos');
        $limit = (int)$input->getOption('limit');
        $batchSize = (int)$input->getOption('batch-size');
        $cacheTtl = (int)$input->getOption('cache-ttl');
//        dd($updateLogos);
        $io->title('Обработка необработанных брендов');
        $io->note(sprintf(
            'Лимит: %d, Размер батча: %d, Кеширование: %s, TTL: %d сек',
            $limit, $batchSize, $this->useCache ? 'вкл' : 'выкл', $cacheTtl
        ));
        $id = (int)$input->getOption('id');
        if ($id > 0) {
            $brand = $this->entityManager->getRepository(Brand::class)->findOneBy(['id' => $id]);
            $brands = [$brand];
        } else {
            if($updateLogos)
            {
                $brands = $this->entityManager->getRepository(Brand::class)->findBy(
                    ['status' => Statuses::Active],
                    [],
                    $limit
                );
            } else {
                $brands = $this->entityManager->getRepository(Brand::class)->findBy(
                    ['status' => Statuses::New],
                    [],
                    $limit
                );
            }

        }

        if (empty($brands)) {
            $io->success('Нет необработанных брендов для обработки.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Найдено %d необработанных брендов.', count($brands)));

        $processed = 0;
        $errors = 0;
        $cached = 0;
        foreach ($brands as $i => $brand) {
            $io->section(sprintf('Обработка бренда %d/%d: %s', $i + 1, count($brands), $brand->getTitle()));

            try {
                $result = $this->processBrand($brand, $io, $cacheTtl, $downloadImages, $updateLogos);
                $processed++;

                if ($result['from_cache']) {
                    $cached++;
                }

                // Флашим каждые batchSize записей
                if (($i + 1) % $batchSize === 0) {
                    $this->entityManager->flush();
//                    $this->entityManager->clear();
                    $io->note('Выполнен flush и clear EntityManager');
                }
            } catch (\Exception $e) {
                $errors++;
                $this->logger->error('Ошибка обработки бренда', [
                    'product_id' => $brand->getId(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $io->error(sprintf('Ошибка обработки бренда %d: %s', $brand->getId(), $e->getMessage()));
            }
            sleep(1);
        }
        // Флашим оставшиеся изменения
        $this->entityManager->flush();

        $io->success(sprintf(
            'Обработка завершена. Успешно: %d, Из кеша: %d, Ошибок: %d',
            $processed, $cached, $errors
        ));

        return Command::SUCCESS;
    }

    private function processBrand(Brand $brand, SymfonyStyle $io, int $cacheTtl, bool $downloadImages, bool $updateLogos)
    {
        $url = sprintf("https://russianstreetwear.club/%s",$brand->getSlug());
        $result = ['from_cache' => false];

        $io->text("Получаем данные с: {$url}");

        // Получаем HTML страницы (из кеша или по сети)
        $htmlContent = $this->fetchPageContent($url, $cacheTtl, $result, $io);

        if (!$htmlContent) {
            throw new \Exception('Не удалось получить содержимое страницы');
        }

        $productData = $this->parseData($htmlContent, $url, $io);

        if($updateLogos)
        {
            // Обрабатываем логотип
//            $this->processBrandLogo($brand, $productData['props']['pageProps']['brand'] ?? [], $io, $downloadImages);
        } else {
            // Обрабатываем ссылки
            $this->processBrandLinks($brand, $productData['props']['pageProps']['brand']['links'] ?? [], $io);

            // Обрабатываем фото
            $this->processBrandPhotos($brand, $productData['props']['pageProps']['brand']['photos'] ?? [], $io, $downloadImages);

            // Обрабатываем логотип
            $this->processBrandLogo($brand, $productData['props']['pageProps']['brand'] ?? [], $io, $downloadImages);
        }

        $brand->setDescription($productData['props']['pageProps']['brand']['description'] ?? '');
        $brand->setStatus(Statuses::Active);
        $this->alphabetManagerService->handleBrandUpdate($brand);

        return $result;
    }

    private function processBrandLinks(Brand $brand, array $linksData, SymfonyStyle $io): void
    {
        $processedUrls = [];
        $newLinksCount = 0;
        $existingLinksCount = 0;


        foreach ($linksData as $linkData)
        {
            $url = $linkData['url'] ?? null;
            $title = $linkData['title'] ?? null;
            $title = str_replace('www.', '', $title);
            $title = str_replace('ВКонтакте', 'VK', $title);
            $title = str_replace('WB', 'Wildberries', $title);
            $slug = $linkData['id'] ?? null;
            $createdAt = DateTime::createFromFormat('Y-m-d H:i:s.v\Z', $linkData['created']) ?? null;
            $updatedAt = DateTime::createFromFormat('Y-m-d H:i:s.v\Z', $linkData['updated']) ?? null;

            if (!$url) {
                continue;
            }

            // Проверяем уникальность URL в рамках текущей обработки
            if (in_array($url, $processedUrls)) {
                $io->note("Пропущен дубликат ссылки: {$url}");
                continue;
            }

            $processedUrls[] = $url;

            // Проверяем существование ссылки через коллекцию бренда
            $existingLink = $this->findLinkInCollection($brand, $url);

            if ($existingLink) {
                $existingLinksCount++;
                // Можно обновить существующую ссылку, если нужно
                // $existingLink->setTitle($title);
                continue;
            }

            // Создаем новую ссылку
            $brandLink = new BrandLink();
            $brandLink->setBrand($brand);
            $brandLink->setLinkUrl($url);
            $brandLink->setTitle($title);
            $brandLink->setSlug($slug);
            $brandLink->setCreatedAt($createdAt);
            $brandLink->setUpdatedAt($updatedAt);
            $brandLink->setStatus(Statuses::Active); // Активный статус
//            dd($brandLink);
            // Используем встроенный метод addLink
            $brand->addLink($brandLink);
            $newLinksCount++;

            $io->text("Добавлена новая ссылка: {$title} - {$url}");
        }

        if ($newLinksCount > 0) {
            $this->entityManager->flush();
            $io->success("Добавлено новых ссылок: {$newLinksCount}");
        }

        if ($existingLinksCount > 0) {
            $io->info("Найдено существующих ссылок: {$existingLinksCount}");
        }
    }

    private function findLinkInCollection(Brand $brand, string $url): ?BrandLink
    {
        foreach ($brand->getLinks() as $link) {
            if ($link->getLinkUrl() === $url) {
                return $link;
            }
        }

        return null;
    }

    private function fetchPageContent(string $url, int $cacheTtl, array &$result, SymfonyStyle $io): ?string
    {
        // Пытаемся получить из кеша
        if ($this->useCache) {
            $cachedContent = $this->cacheService->getCachedContent($url, $cacheTtl);
            if ($cachedContent !== null) {
                $io->text('✓ Загружено из кеша');
                $result['from_cache'] = true;
                return $cachedContent;
            }
        }

        // Загружаем по сети
        try {
            $io->text('Загружаем по сети...');
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception(sprintf('HTTP статус: %d', $response->getStatusCode()));
            }

            $content = $response->getContent();

            // Сохраняем в кеш
            if ($this->useCache) {
                $this->cacheService->saveToCache($url, $content);
                $io->text('✓ Сохранено в кеш');
            }

            $result['from_cache'] = false;
            return $content;

        } catch (\Exception $e) {
            $this->logger->error('Ошибка получения страницы', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function parseData(?string $htmlContent, string $url, SymfonyStyle $io)
    {
        $data = [
            'description' => null,
            'anons' => null,
            'specs' => null,
            'sizes' => null,
            'rating' => null,
            'originImage' => null,
            'review_count' => 0
        ];

        try {
            // Извлекаем JSON из script тега
            preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $htmlContent, $matches);

            if (!isset($matches[1])) {
                $io->error('JSON data not found in HTML file');
                return Command::FAILURE;
            }
            $data = json_decode($matches[1], true);
//            dd($data);
        } catch (\Exception $e) {
            $this->logger->warning('Ошибка парсинга HTML', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            $io->warning('Ошибка парсинга HTML: ' . $e->getMessage());
        }

        return $data;
    }

    private function processBrandPhotos(Brand $brand, array $photosData, SymfonyStyle $io, bool $downloadImages): void
    {
        $processedPaths = [];
        $newPhotosCount = 0;
        $existingPhotosCount = 0;
        $skippedPhotosCount = 0;

//        dd($brand->getImages(), $photosData);

        foreach ($photosData as $cnt => $photoData)
        {
            $path = $photoData['path'] ?? null;

            if (!$path) {
                continue;
            }

            // Проверяем уникальность пути в рамках текущей обработки
            if (in_array($path, $processedPaths)) {
                $io->note("Пропущен дубликат фото: {$path}");
                $skippedPhotosCount++;
                continue;
            }

            $processedPaths[] = $path;

            // Проверяем существование фото через коллекцию бренда
            $existingPhoto = $this->findImageInCollection($brand, $photoData['id']);

            if ($existingPhoto) {
                $existingPhotosCount++;
                $io->text("Фото уже существует: {$path}");
                continue;
            }
//            dd($existingPhoto);

            // Создаем новое фото
            $brandImage = new BrandImage();
            $brandImage->setBrand($brand);
            $brandImage->setStatus(Statuses::Active); // Активный статус
            $brandImage->setSlug($photoData['id']);

            $brandImage->setTitle(sprintf('%s - изображение %d', $brand->getTitle(), $cnt+1));

            // Если нужно скачать изображения
            if ($downloadImages)
            {
                $previewPath = '/_next/image?url=%2Fapi%2Fimage%3Furl%3D'.urlencode($path).'&w=1080&q=75';
                $previewName = $this->downloadImage($previewPath, $io);
                if ($previewName) {
                    $brandImage->setPreview($previewName);
                } else {
                    $io->error("Не удалось скачать preview: {$path}");
                }
                $imagePath = '/api/image?url='.$path;
                $imageName = $this->downloadImage($imagePath, $io);
                if ($imageName) {
                    $brandImage->setImage($imageName);
                } else {
                    $io->error("Не удалось скачать image: {$path}");
                }

            } else {
                // Если скачивание отключено, сохраняем только путь
                $brandImage->setPreview($path);
                $brandImage->setImage($path);
            }

            // Используем встроенный метод addImage
            $brand->addImage($brandImage);
            $this->entityManager->persist($brandImage);
            $newPhotosCount++;

            $io->text("Добавлено новое фото: {$path}");
        }

        if ($newPhotosCount > 0) {
            $this->entityManager->flush();
            $io->success("Добавлено новых фото: {$newPhotosCount}");
        }

        if ($existingPhotosCount > 0) {
            $io->info("Найдено существующих фото: {$existingPhotosCount}");
        }

        if ($skippedPhotosCount > 0) {
            $io->note("Пропущено дубликатов: {$skippedPhotosCount}");
        }
    }

    private function downloadImage(string $imagePath, SymfonyStyle $io, ?string $subDir = 'brands'): ?string
    {
        try {
            // Формируем полный URL для скачивания
            $baseUrl = 'https://russianstreetwear.club';
            $imageUrl = $baseUrl . $imagePath;
//            dd($imageUrl);
            $io->text("Скачиваем изображение: {$imageUrl}");

            // Используем ImageDownloadService для скачивания
            $fileName = $this->imageDownloader->downloadAndSaveImage($imageUrl, $subDir);

            if (!$fileName) {
                throw new \Exception('ImageDownloadService вернул null');
            }

            $io->text("Скачано и сохранено изображение: {$fileName}");

            return $fileName;

        } catch (\Exception $e) {
            $io->error("Ошибка при загрузке изображения {$imagePath}: " . $e->getMessage());
            return null;
        }
    }

    private function findImageInCollection(Brand $brand, string $path): ?BrandImage
    {
        foreach ($brand->getImages() as $image)
        {
            if($image->getSlug() == $path)
            {
                return $image;
            }
//            $currentImage = $image->getImage();
//            $currentPreview = $image->getPreview();
//
//             Извлекаем имя файла из пути для сравнения
//            $searchFileName = pathinfo($path, PATHINFO_BASENAME);
//            $currentFileName = $currentImage ? pathinfo($currentImage, PATHINFO_BASENAME) : null;
//            $currentPreviewFileName = $currentPreview ? pathinfo($currentPreview, PATHINFO_BASENAME) : null;
//
//             Сравниваем по имени файла
//            if ($currentFileName === $searchFileName || $currentPreviewFileName === $searchFileName) {
//                return $image;
//            }

            // Также проверяем полные пути
//            if ($currentImage === $path || $currentPreview === $path) {
//                return $image;
//            }
        }

        return null;
    }

    private function processBrandLogo(Brand $brand, array $photosData, SymfonyStyle $io, bool $downloadImages): void
    {
        $newPhotosCount = 0;
//        dd($photosData);
        $path = $photosData['logo']['path'] ?? null;

        if (!$path) {
            return;
        }

        if ($downloadImages)
        {
            $subDir = 'logos';
            if(!file_exists($this->uploadDir.$subDir.'/'.$brand->getLogo())) {
                $previewPath = '/api/image?url='.$path;
                $previewName = $this->downloadImage($previewPath, $io, $subDir);
                if ($previewName) {
                    $brand->setLogo($previewName);
                } else {
                    $io->error("Не удалось скачать logo: {$brand->getLogo()}");
                }
            } else {
                $io->text("logo уже существует: {$brand->getLogo()}");
            }
//            $previewPath = '/_next/image?url=%2Fapi%2Fimage%3Furl%3D'.urlencode($path).'&w=640&q=75';

        } else {
            $brand->setLogo($path);
        }

        // Используем встроенный метод addImage
        $this->entityManager->persist($brand);
        $newPhotosCount++;

        $io->text("Добавлено новое logo: {$path}");
    }

}
