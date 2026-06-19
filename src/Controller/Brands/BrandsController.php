<?php

namespace App\Controller\Brands;

use App\Entity\Brand;
use App\Entity\Product;
use App\Repository\BrandRepository;
use App\Repository\BrandStyleRepository;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\Agent\BrandUnpublisher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BrandsController extends AbstractController
{
    #[Route('/{_locale}/brands', name: 'brand_index', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
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
            $totalItemsQb->andWhere('UPPER(SUBSTRING(b.title, 1, :letterLen)) = :letter')
                ->setParameter('letter', mb_strtoupper($letter))
                ->setParameter('letterLen', mb_strlen($letter));
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
            $brandsQb->andWhere('UPPER(SUBSTRING(b.title, 1, :letterLen)) = :letter')
                ->setParameter('letter', mb_strtoupper($letter))
                ->setParameter('letterLen', mb_strlen($letter));
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

        // «Приоритет в выдаче» (обещание тарифов Basic/Premium): бренды с активной/триальной
        // платной подпиской поднимаются выше, внутри групп — прежний порядок по новизне.
        // GROUP BY b.id схлопывает возможные дубли от истории подписок (PK-зависимость
        // колонок — легальна в MySQL с only_full_group_by).
        $brands = $brandsQb
            ->leftJoin(\App\Entity\Subscription::class, 'sub', 'WITH', 'sub.brand = b AND sub.status IN (:subStatuses)')
            ->leftJoin('sub.tariff', 'tar', 'WITH', 'tar.priceRub > 0')
            ->setParameter('subStatuses', [\App\Entity\Subscription::STATUS_ACTIVE, \App\Entity\Subscription::STATUS_TRIAL])
            ->addSelect('MAX(tar.priceRub) AS HIDDEN paidTariff')
            ->groupBy('b.id')
            ->orderBy('paidTariff', 'DESC')
            ->addOrderBy('b.created_at', 'DESC')
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
            foreach ($displayAlphabet as $azLetter) {
                if (!array_key_exists($azLetter, $foundLetters)) {
                    unset($displayAlphabets[$localAZ][$azLetter]);
                }
            }
        }

        // Второй уровень: валидные двухбуквенные префиксы для выбранной буквы (напр. WA, WY)
        $currentFirstLetter = $letter ? mb_strtoupper(mb_substr($letter, 0, 1)) : null;
        $subLetters = [];
        if ($currentFirstLetter !== null) {
            foreach ($lettersQb as $brandData) {
                $title = $brandData['title'];
                if (mb_strlen($title) < 2) {
                    continue;
                }
                if (mb_strtoupper(mb_substr($title, 0, 1)) !== $currentFirstLetter) {
                    continue;
                }
                $prefix = mb_strtoupper(mb_substr($title, 0, 2));
                $subLetters[$prefix] = ($subLetters[$prefix] ?? 0) + 1;
            }
            ksort($subLetters);
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
            'subLetters' => $subLetters,
            'currentLetter' => $letter,
            'currentFirstLetter' => $currentFirstLetter,
            'currentCity' => $city,
            'currentStyle' => $style,
            'locale' => $locale,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'perPage' => $perPage,
        ]);
    }

    /**
     * Скрыть бренд из каталога — веб-кнопка для админа прямо на странице бренда
     * (замена сломанной TG-кнопки: вебхук Telegram→прод таймаутит). Soft-hide +
     * снятие с прод-каталога через тот же BrandUnpublisher::hide.
     */
    #[Route('/{_locale}/brands/{slug}/hide',
        name: 'brand_hide',
        requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'],
        defaults: ['_locale' => 'ru'],
        methods: ['POST'])]
    public function hide(
        #[MapEntity(mapping: ['slug' => 'slug'])] Brand $brand,
        Request $request,
        BrandUnpublisher $unpublisher,
        \App\Service\AdminAccess $adminAccess,
    ): Response {
        if (!$adminAccess->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('brand_hide_' . $brand->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный токен — попробуйте ещё раз.');

            return $this->redirectToRoute('brand_show', ['_locale' => $request->getLocale(), 'slug' => $brand->getSlug()]);
        }

        $res = $unpublisher->hide($brand->getId());
        $this->addFlash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? sprintf('Бренд «%s» скрыт. %s', $res['title'] ?? '', $res['message'] ?? '') : ($res['message'] ?? 'Не удалось скрыть.'));

        return $this->redirectToRoute('brand_index', ['_locale' => $request->getLocale()]);
    }

    #[Route('/{_locale}/brands/{slug}',
        name: 'brand_show',
        requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'],
        defaults: ['_locale' => 'ru'])]
    public function show(#[MapEntity(mapping: ['slug' => 'slug'])]Brand $brand, BrandRepository $brandRepo, \App\Repository\BrandUserRepository $brandUserRepo, \App\Service\CitySlugger $citySlugger, \App\Service\AdminAccess $adminAccess): Response
    {
        // Является ли текущий пользователь участником команды ИМЕННО этого бренда
        $isMemberOfThisBrand = false;
        if ($this->getUser() !== null) {
            $isMemberOfThisBrand = $brandUserRepo->findOneBy(['brand' => $brand, 'user' => $this->getUser()]) !== null;
        }

        // Неактивные бренды (new = в очереди дрип-публикации, disabled/deleted) публично
        // не существуют — 404, как в каталоге/sitemap. Участникам бренда — превью.
        // Админу (main ROLE_ADMIN или admincore-сессия) — превью любого бренда.
        if ($brand->getStatus() !== \Nevinny\AdminCoreBundle\Enum\Statuses::Active && !$isMemberOfThisBrand && !$adminAccess->isAdmin()) {
            throw $this->createNotFoundException('Бренд не опубликован');
        }

        $demoProducts = $this->createDemoProducts($brand);
        // Жёсткий граф перелинковки; динамический подбор — только пока граф не построен
        $similarBrands = $brandRepo->findRelatedHard($brand) ?: $brandRepo->findSimilarBrands($brand, 8);

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

        // FAQ (SEO задача C): аккордеон + FAQPage JSON-LD
        $faqs = $brandRepo->getEntityManager()
            ->getRepository(\App\Entity\BrandFaq::class)
            ->findByBrandOrdered($brand);

        // Краудсорс-валидация: скрытые точки (state=hidden) не показываем.
        // hiddenDp: ключи вида "{target_type}:{target_id|0}:{field}".
        $hiddenDp = [];
        foreach ($brandRepo->getEntityManager()->getRepository(\App\Entity\BrandDatapoint::class)->findHiddenByBrand($brand) as $dp) {
            $hiddenDp[$dp->getTargetType() . ':' . ($dp->getTargetId() ?? 0) . ':' . $dp->getField()] = true;
        }

        // Извлечённые атрибуты (краул→extract), сгруппированы по name для рендера.
        // Скрытые голосами (brand_attribute:{id}:value в hiddenDp) отфильтрованы.
        $attrGroups = [];
        foreach ($brandRepo->getEntityManager()->getRepository(\App\Entity\BrandAttribute::class)->findByBrand($brand) as $attr) {
            if (isset($hiddenDp['brand_attribute:' . $attr->getId() . ':value'])) {
                continue;
            }
            $attrGroups[$attr->getName()][] = ['id' => $attr->getId(), 'value' => $attr->getValue()];
        }

        // sameAsLinks для JSON-LD Organization.sameAs
        $sameAsLinks = [];
        foreach ($brand->getLinks() as $link) {
            if ($link->getLinkUrl()) {
                $sameAsLinks[] = $link->getLinkUrl();
            }
        }

        // Счётчик активных брендов для WEARBASE Organization.description
        $totalBrands = $brandRepo->count(['status' => Statuses::Active]);

        return $this->render('tailwind/brand/show.html.twig', [
            'brand' => $brand,
            'products' => $demoProducts,
            'similarBrands' => $similarBrands,
            'styles' => $styles,
            'cities' => $cities,
            // Слаг города бренда → ссылка на дедицированный city-хаб (реципрок к city→card,
            // «родительская категория» по правилу 2.12). null если города нет.
            'citySlug' => ($c = trim((string) $brandCity)) !== '' ? $citySlugger->slugify($c) : null,
            'isMemberOfThisBrand' => $isMemberOfThisBrand,
            'faqs' => $faqs,
            'hiddenDp' => $hiddenDp,
            'attrGroups' => $attrGroups,
            'sameAsLinks' => $sameAsLinks,
            'totalBrands' => $totalBrands,
        ]);
    }

    #[Route('/', name: 'home', priority: 10)]
    public function home(Request $request): Response
    {
        // Используем locale из cookie/LocaleListener, fallback — 'ru'
        $locale = $request->getLocale() ?: 'ru';
        $supported = ['ru', 'en', 'zh', 'ar', 'tr', 'de', 'fr', 'es', 'ko'];
        if (!in_array($locale, $supported, true)) {
            $locale = 'ru';
        }
        return $this->redirectToRoute('home_hub', ['_locale' => $locale], 302);
    }

    #[Route('/{_locale}/', name: 'home_hub', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
    public function homeHub(BrandRepository $repo, Request $request, \App\Service\CitySlugger $slugger): Response
    {
        $locale = $request->getLocale();

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
        foreach ($topCities as &$cityRow) {
            $cityRow['slug'] = $slugger->slugify($cityRow['city']);
        }
        unset($cityRow);

        $styles = $repo->createQueryBuilder('b')
            ->select('s.id, s.slug, s.title, COUNT(DISTINCT b.id) as cnt')
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
    #[Route('/{_locale}/cities', name: 'brand_cities', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
    public function cities(BrandRepository $repo, Request $request, \App\Service\CitySlugger $slugger): Response
    {
        $cities = $repo->createQueryBuilder('b')
            ->select('b.city, COUNT(b.id) as cnt')
            ->where('b.status = :status')
            ->andWhere('b.city IS NOT NULL')
            ->andWhere('b.city != \'\'')
            ->setParameter('status', Statuses::Active)
            ->groupBy('b.city')
            ->orderBy('cnt', 'DESC')
            ->addOrderBy('b.city', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($cities as &$city) {
            $city['slug'] = $slugger->slugify($city['city']);
        }
        unset($city);

        return $this->render('tailwind/cities.html.twig', [
            'cities' => $cities,
            'totalBrands' => array_sum(array_column($cities, 'cnt')),
            'locale' => $request->getLocale(),
        ]);
    }

    #[Route('/{_locale}/cities/{slug}', name: 'brand_city', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko', 'slug' => '[a-z0-9-]+'], defaults: ['_locale' => 'ru'])]
    public function cityShow(string $slug, BrandRepository $repo, Request $request, \App\Service\CitySlugger $slugger): Response
    {
        $allCities = $repo->createQueryBuilder('b')
            ->select('DISTINCT b.city')
            ->where('b.status = :status')
            ->andWhere('b.city IS NOT NULL')
            ->andWhere('b.city != \'\'')
            ->setParameter('status', Statuses::Active)
            ->getQuery()
            ->getSingleColumnResult();

        $city = $slugger->resolve($slug, $allCities);
        if (!$city) {
            throw $this->createNotFoundException('Город не найден');
        }

        $brands = $repo->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.city = :city')
            ->setParameter('status', Statuses::Active)
            ->setParameter('city', $city)
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('tailwind/city.html.twig', [
            'city' => $city,
            'slug' => $slug,
            'brands' => $brands,
            'locale' => $request->getLocale(),
        ]);
    }

    #[Route('/{_locale}/styles', name: 'brand_styles', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko'], defaults: ['_locale' => 'ru'])]
    public function styles(BrandRepository $repo, Request $request): Response
    {
        $styles = $repo->createQueryBuilder('b')
            ->select('s.slug, s.title, COUNT(DISTINCT b.id) as cnt')
            ->join('b.styles', 's')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->groupBy('s.id')
            ->orderBy('cnt', 'DESC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('tailwind/styles.html.twig', [
            'styles' => $styles,
            'totalBrands' => array_sum(array_column($styles, 'cnt')),
            'locale' => $request->getLocale(),
        ]);
    }

    #[Route('/{_locale}/style/{slug}', name: 'brand_style', requirements: ['_locale' => 'en|ru|zh|ar|tr|de|fr|es|ko', 'slug' => '[a-z0-9-]+'], defaults: ['_locale' => 'ru'])]
    public function styleShow(string $slug, BrandRepository $repo, BrandStyleRepository $styleRepo, Request $request): Response
    {
        $style = $styleRepo->findOneBy(['slug' => $slug]);
        if (!$style) {
            throw $this->createNotFoundException('Стиль не найден');
        }

        $brands = $repo->createQueryBuilder('b')
            ->join('b.styles', 's')
            ->where('b.status = :status')
            ->andWhere('s.slug = :slug')
            ->setParameter('status', Statuses::Active)
            ->setParameter('slug', $slug)
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('tailwind/style.html.twig', [
            'style' => $style,
            'slug' => $slug,
            'brands' => $brands,
            'locale' => $request->getLocale(),
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
