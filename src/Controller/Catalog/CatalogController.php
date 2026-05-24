<?php

declare(strict_types=1);

namespace App\Controller\Catalog;

use App\Entity\Product;
use App\Repository\BrandRepository;
use App\Repository\BrandStyleRepository;
use App\Repository\ProductCategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичный каталог товаров.
 *
 * GET /catalog                  — все активные товары
 * GET /catalog?gender=women     — фильтр по полу
 * GET /catalog?sort=new|price_asc|price_desc
 * GET /catalog?sale=1           — только товары со скидкой
 * GET /catalog?q=запрос         — поиск по названию
 * GET /catalog?brand=42         — товары конкретного бренда
 * GET /catalog?page=2           — пагинация
 */
class CatalogController extends AbstractController
{
    private const PER_PAGE = 48;

    private const GENDER_LABELS = [
        'women' => 'Женщины',
        'men'   => 'Мужчины',
        'unisex' => 'Унисекс',
        'kids'  => 'Дети',
    ];

    private const SORT_LABELS = [
        'new'        => 'Новинки',
        'price_asc'  => 'Дешевле',
        'price_desc' => 'Дороже',
    ];

    #[Route('/catalog', name: 'catalog_index', methods: ['GET'])]
    public function index(
        Request $request,
        ProductRepository $productRepo,
        BrandRepository $brandRepo,
        ProductCategoryRepository $categoryRepo,
        BrandStyleRepository $styleRepo,
        ProductVariantRepository $variantRepo,
    ): Response {
        $filters = $this->extractFilters($request);
        $page    = max(1, (int) $request->query->get('page', 1));
        $offset  = ($page - 1) * self::PER_PAGE;

        $products = $productRepo->findForCatalog($filters, self::PER_PAGE, $offset);
        $total    = $productRepo->countForCatalog($filters);
        $pages    = (int) ceil($total / self::PER_PAGE);

        // Список брендов для фильтра (только те, у которых есть активные товары)
        $brands = $brandRepo->createQueryBuilder('b')
            ->select('b.id, b.title')
            ->join('b.products', 'p')
            ->where("b.status = 'active'")
            ->andWhere("p.status = 'active'")
            ->groupBy('b.id')
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();

        // Данные для новых фильтров
        $categories = $categoryRepo->findBy(['status' => 'active'], ['ord' => 'ASC']);
        $styles     = $styleRepo->findBy([], ['title' => 'ASC']);
        $sizes      = $variantRepo->findDistinctSizes();

        return $this->render('catalog/index.html.twig', [
            'products'     => $products,
            'total'        => $total,
            'page'         => $page,
            'pages'        => $pages,
            'filters'      => $filters,
            'brands'       => $brands,
            'categories'   => $categories,
            'styles'       => $styles,
            'sizes'        => $sizes,
            'genderLabels' => self::GENDER_LABELS,
            'sortLabels'   => self::SORT_LABELS,
            'currentSort'  => $filters['sort'] ?? 'new',
        ]);
    }

    #[Route('/product/{uuid}', name: 'product_show', methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product,
        ProductRepository $productRepo,
    ): Response {
        if ($product->getStatus() !== Statuses::Active || $product->getBrand()->getStatus() !== Statuses::Active) {
            throw $this->createNotFoundException('Товар не найден');
        }

        return $this->render('catalog/show.html.twig', [
            'product'         => $product,
            'brand'           => $product->getBrand(),
            'similarProducts' => $productRepo->findSimilar($product, 8),
        ]);
    }

    private function extractFilters(Request $request): array
    {
        $gender   = $request->query->get('gender');
        $sort     = $request->query->get('sort', 'new');
        $search   = trim((string) $request->query->get('q', ''));
        $sale     = (bool) $request->query->get('sale');
        $brand    = $request->query->getInt('brand') ?: null;
        $category = $request->query->getInt('category') ?: null;
        $style    = $request->query->getInt('style') ?: null;
        $size     = $request->query->get('size');
        $minPrice = $request->query->get('min_price');
        $maxPrice = $request->query->get('max_price');

        return [
            'gender'    => in_array($gender, ['men', 'women', 'unisex', 'kids'], true) ? $gender : null,
            'sort'      => in_array($sort, ['new', 'price_asc', 'price_desc'], true) ? $sort : 'new',
            'search'    => $search,
            'sale'      => $sale,
            'brand'     => $brand,
            'category'  => $category,
            'style'     => $style,
            'size'      => $size ?: null,
            'min_price' => $minPrice !== null && $minPrice !== '' ? (float) $minPrice : null,
            'max_price' => $maxPrice !== null && $maxPrice !== '' ? (float) $maxPrice : null,
        ];
    }
}
