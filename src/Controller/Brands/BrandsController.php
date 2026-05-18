<?php

namespace App\Controller\Brands;

use App\Entity\Brand;
use App\Entity\Product;
use App\Repository\BrandRepository;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BrandsController extends AbstractController
{
    #[Route('/{_locale}/brands', name: 'brand_index', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function brand_index(Request $request, BrandRepository $repo): Response
    {
        $locale = $request->getLocale(); // 'en' или 'ru'

        // Универсальные алфавиты
        $alphabets = [
            'digits' => range('0', '9'),
            'en' => range('A', 'Z'),
            'ru' => ['А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О',
                'П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'],
            // добавляем при необходимости: 'de', 'jp', 'zh', ...
        ];

//        $alphabet = $alphabets[$locale] ?? range('A', 'Z');
        if ($locale === 'en') {
            $displayAlphabets = [
                'digits' => $alphabets['digits'],
                'en' => $alphabets['en']
            ];
        } else {
            $displayAlphabets = [
                'digits' => $alphabets['digits'],       // английский всегда
                'en' => $alphabets['en'],       // английский всегда
                $locale => $alphabets[$locale] ?? []  // локальный алфавит
            ];
        }

        $letter = $request->query->get('letter');
        $city = $request->query->get('city');
        $style = $request->query->get('style');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 24;

        $baseQb = $repo->createQueryBuilder('b')
            ->andWhere('b.status = :active')
            ->setParameter('active', 'active')
        ;

        if ($letter) {
            $baseQb->andWhere('UPPER(SUBSTRING(b.title, 1, 1)) = :letter')
                ->setParameter('letter', strtoupper($letter));
        }

        if ($city) {
            $baseQb->andWhere('b.city = :city')
                ->setParameter('city', $city);
        }

        if ($style) {
            $baseQb->join('b.styles', 's')
                ->andWhere('s.id = :styleId OR s.slug = :styleSlug')
                ->setParameter('styleId', is_numeric($style) ? (int)$style : null)
                ->setParameter('styleSlug', is_numeric($style) ? null : $style);
        }

        $totalItemsQb = $repo->createQueryBuilder('b')
            ->select('COUNT(DISTINCT b.id)')
            ->andWhere('b.status = :active')
            ->setParameter('active', 'active');

        if ($letter) {
            $totalItemsQb->andWhere('UPPER(SUBSTRING(b.title, 1, 1)) = :letter')
                ->setParameter('letter', strtoupper($letter));
        }

        if ($city) {
            $totalItemsQb->andWhere('b.city = :city')
                ->setParameter('city', $city);
        }

        if ($style) {
            $totalItemsQb->join('b.styles', 's')
                ->andWhere('s.id = :styleId OR s.slug = :styleSlug')
                ->setParameter('styleId', is_numeric($style) ? (int)$style : null)
                ->setParameter('styleSlug', is_numeric($style) ? null : $style);
        }

        $totalItems = (int) $totalItemsQb->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        $brandsQb = $repo->createQueryBuilder('b')
            ->andWhere('b.status = :active')
            ->setParameter('active', 'active');

        if ($letter) {
            $brandsQb->andWhere('UPPER(SUBSTRING(b.title, 1, 1)) = :letter')
                ->setParameter('letter', strtoupper($letter));
        }

        if ($city) {
            $brandsQb->andWhere('b.city = :city')
                ->setParameter('city', $city);
        }

        if ($style) {
            $brandsQb->join('b.styles', 's')
                ->andWhere('s.id = :styleId OR s.slug = :styleSlug')
                ->setParameter('styleId', is_numeric($style) ? (int)$style : null)
                ->setParameter('styleSlug', is_numeric($style) ? null : $style);
        }

        $brands = $brandsQb
            ->orderBy('b.created_at', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult()
        ;

        $lettersQb = $repo->createQueryBuilder('b')
            ->select('b.title')
            ->andWhere('b.status = :active')
            ->setParameter('active', 'active')
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getArrayResult()
        ;
        $foundLetters = [];
        foreach ($lettersQb as $brandData) {
            $firstChar = mb_strtoupper(mb_substr($brandData['title'], 0, 1));
            if (!array_key_exists($firstChar, $foundLetters)) {
                $foundLetters[$firstChar] = 0;
            }
            $foundLetters[$firstChar]++;
        }

        foreach ($displayAlphabets as $localAZ => $displayAlphabet) {
            foreach ($displayAlphabet as $letter) {
                if (!array_key_exists($letter, $foundLetters)) {
                    unset($displayAlphabets[$localAZ][$letter]);
                }
            }
        }

        // Подставляем популярные бренды, если буква не выбрана
        $featured = [];
        if (!$letter) {
            $featured = $repo->findBy([
//                'isFeatured' => true
            ], ['created_at' => 'DESC'], 12);
        }

        return $this->render('tailwind/brand/index.html.twig', [
            'brands' => $brands,
            'featuredBrands' => $featured,
            'alphabets' => $displayAlphabets,
            'foundLetters' => $foundLetters,
            'currentLetter' => $letter,
            'currentCity' => $city,
            'currentStyle' => $style,
            'locale' => $locale,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'perPage' => $perPage,
        ]);
    }

    #[Route('/{_locale}/brands/{slug}',
        name: 'brand_show',
        requirements: ['_locale' => 'en|ru'],
        defaults: ['_locale' => 'ru'])]
    public function show(#[MapEntity(mapping: ['slug' => 'slug'])]Brand $brand, BrandRepository $brandRepo): Response
    {
        $demoProducts = $this->createDemoProducts($brand);
        $similarBrands = $brandRepo->findSimilarBrands($brand, 8);

        $brandStyles = $brand->getStyles()->toArray();
        $brandCity = $brand->getCity();

        $firstStyle = $brand->getStyles()->first();
        $styleId = $firstStyle ? $firstStyle->getId() : null;

        $styles = [];
        if ($styleId) {
            $styles = $brandRepo->createQueryBuilder('b')
                ->select('DISTINCT s.slug, s.title')
                ->leftJoin('b.styles', 's')
                ->join('b.styles', 'bs')
                ->where('s.slug IS NOT NULL')
                ->andWhere('bs.id = :styleId')
                ->setParameter('styleId', $styleId)
                ->andWhere('b.id != :brandId')
                ->setParameter('brandId', $brand->getId())
                ->setMaxResults(8)
                ->getQuery()
                ->getResult();
        }

        $cities = $brandRepo->createQueryBuilder('b')
            ->select('DISTINCT b.city')
            ->where('b.city IS NOT NULL')
            ->andWhere('b.city != \'\'')
            ->andWhere('b.city != :city')
            ->setParameter('city', $brandCity)
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();
        $cities = array_column($cities, 'city');

        return $this->render('tailwind/brand/showv2.html.twig', [
            'brand' => $brand,
            'products' => $demoProducts,
            'similarBrands' => $similarBrands,
            'styles' => $styles,
            'cities' => $cities,
        ]);
    }

    #[Route('/', name: 'home', priority: 10)]
    public function home(): Response
    {
        return $this->redirectToRoute('home_hub', [
            '_locale' => 'ru'
        ], 302);
    }

    #[Route('/{_locale}/', name: 'home_hub', requirements: ['_locale' => 'en|ru'], defaults: ['_locale' => 'ru'])]
    public function homeHub(BrandRepository $repo): Response
    {
        $locale = 'ru';

        $featuredBrands = $repo->findFeaturedBrands(12);
        $recentBrands = $repo->findBy(['status' => Statuses::Active], ['created_at' => 'DESC'], 12);

        $qb = $repo->createQueryBuilder('b')
            ->select('b.city, COUNT(b.id) as cnt')
            ->where('b.status = :status')
            ->andWhere('b.city IS NOT NULL')
            ->andWhere('b.city != \'\'')
            ->setParameter('status', Statuses::Active)
            ->groupBy('b.city')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(10);

        $topCities = $qb->getQuery()->getResult();

        $styles = $repo->createQueryBuilder('b')
            ->select('s.id, s.title, COUNT(DISTINCT b.id) as cnt')
            ->leftJoin('b.styles', 's')
            ->where('b.status = :status')
            ->andWhere('s.id IS NOT NULL')
            ->setParameter('status', Statuses::Active)
            ->groupBy('s.id')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $totalBrands = $repo->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('tailwind/hub.html.twig', [
            'featuredBrands' => $featuredBrands,
            'recentBrands' => $recentBrands,
            'topCities' => $topCities,
            'topStyles' => $styles,
            'totalBrands' => $totalBrands,
            'locale' => $locale,
        ]);
    }
    #[Route('/brands/a-z', name: 'brands_az')]
    public function brandsAlphabetical(Request $request, BrandRepository $brandRepository): Response
    {
        $letter = $request->query->get('letter', 'A');
        $search = $request->query->get('search', '');

        // Все буквы алфавита + цифры
        $alphabet = array_merge(
            range('A', 'Z'),
            ['0-9']
        );

        // Получаем бренды по букве или поиску
        if (!empty($search)) {
            $brands = $brandRepository->findBrandsBySearch($search);
            $currentLetter = null;
        } else {
            $brands = $brandRepository->findBrandsByLetter($letter);
            $currentLetter = $letter;
        }

        // Группируем бренды по первой букве для общего списка
        $allBrands = $brandRepository->findAllActiveBrands();
        $brandsByLetter = [];

        foreach ($allBrands as $brand) {
            $firstChar = strtoupper(substr($brand->getTitle(), 0, 1));
            if (!ctype_alpha($firstChar)) {
                $firstChar = '0-9';
            }

            if (!isset($brandsByLetter[$firstChar])) {
                $brandsByLetter[$firstChar] = [];
            }

            $brandsByLetter[$firstChar][] = [
                'title' => $brand->getTitle(),
                'slug' => $brand->getSlug(),
//                'logo' => $brand->getLogo(),
//                'isLocal' => $brand->getIsLocal(),
//                'isSustainable' => $brand->getIsSustainable(),
//                'productCount' => $brand->getProductCount(),
            ];
        }

        // Сортируем по алфавиту
        ksort($brandsByLetter);

        return $this->render('local-brands/az-index.html.twig', [
            'brands' => $brands,
            'brandsByLetter' => $brandsByLetter,
            'alphabet' => $alphabet,
            'currentLetter' => $currentLetter,
            'searchQuery' => $search,
            'totalBrands' => count($allBrands),
        ]);
    }

    /**
     * Создает демо-товары для бренда
     */
    private function createDemoProducts(Brand $brand): array
    {
        $demoProducts = [];
        return $demoProducts;
        // Товар 1
        $product1 = new Product();
        $product1->setTitle('Оверсайз худи "Shadow"');
        $product1->setSlug('oversize-hudi-shadow');
        $product1->setPrice(4990);
        $product1->setBrand($brand);
        $product1->setDescription('Черное оверсайз худи из хлопка с капюшоном. Увеличенный крой для комфортной носки.');
        $demoProducts[] = $product1;

        // Товар 2
        $product2 = new Product();
        $product2->setTitle('Футболка оверсайз "Minimal"');
        $product2->setSlug('oversize-t-shirt-minimal');
        $product2->setPrice(2990);
        $product2->setBrand($brand);
        $product2->setDescription('Белая футболка оверсайз с минималистичным принтом. 100% хлопок.');
        $demoProducts[] = $product2;

        // Товар 3
        $product3 = new Product();
        $product3->setTitle('Бомбер "Urban"');
        $product3->setSlug('bomber-urban');
        $product3->setPrice(8990);
        $product3->setBrand($brand);
        $product3->setDescription('Легкий бомбер из нейлона с градиентной отделкой. Для прохладных вечеров.');
        $demoProducts[] = $product3;

        // Товар 4
        $product4 = new Product();
        $product4->setTitle('Штаны карго "Utility"');
        $product4->setSlug('cargo-pants-utility');
        $product4->setPrice(5990);
        $product4->setBrand($brand);
        $product4->setDescription('Штаны карго с множеством карманов. Удобство и функциональность.');
        $demoProducts[] = $product4;

        // Товар 5
        $product5 = new Product();
        $product5->setTitle('Кепка "Logo"');
        $product5->setSlug('cap-logo');
        $product5->setPrice(1990);
        $product5->setBrand($brand);
        $product5->setDescription('Бейсболка с вышитым логотипом бренда. Регулируемая посадка.');
        $demoProducts[] = $product5;

        // Товар 6
        $product6 = new Product();
        $product6->setTitle('Рюкзак "City"');
        $product6->setSlug('backpack-city');
        $product6->setPrice(7590);
        $product6->setBrand($brand);
        $product6->setDescription('Городской рюкзак с отделением для ноутбука. Водоотталкивающий материал.');
        $demoProducts[] = $product6;

        // Товар 7
        $product7 = new Product();
        $product7->setTitle('Лонгслив "Oversize"');
        $product7->setSlug('longsleeve-oversize');
        $product7->setPrice(3990);
        $product7->setBrand($brand);
        $product7->setDescription('Длинный рукав оверсайз кроя. Идеально для слоинга.');
        $demoProducts[] = $product7;

        // Товар 8
        $product8 = new Product();
        $product8->setTitle('Ветровка "Rain"');
        $product8->setSlug('windbreaker-rain');
        $product8->setPrice(6990);
        $product8->setBrand($brand);
        $product8->setDescription('Ветровка с защитой от ветра и воды. Складной дизайн.');
        $demoProducts[] = $product8;

        return $demoProducts;
    }
}
