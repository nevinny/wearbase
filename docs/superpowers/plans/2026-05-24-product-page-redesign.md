# Product Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign product page to WB-level: gallery, color swatches, discount price, quantity selector, brand block, characteristics, similar products, image import.

**Architecture:** 3 phases — Phase 1 (UI only, no new entities), Phase 2 (new fields on Product), Phase 3 (image import pipeline). Vanilla JS. TDD for backend services.

**Tech Stack:** Symfony 7.3, Doctrine ORM, Twig, Tailwind CSS, Vanilla JS, PHPUnit

---

## File Map

| File | Phase | Action |
|------|-------|--------|
| `src/Repository/ProductRepository.php` | 1 | Add `findSimilar()` |
| `src/Controller/Catalog/CatalogController.php` | 1 | Pass similar products + brand links |
| `templates/catalog/show.html.twig` | 1, 2 | Rewrite product page template |
| `src/Entity/Product.php` | 2 | Add 5 new fields |
| `src/Service/ProductImportService.php` | 2, 3 | New columns + image download |
| `src/Service/ImageDownloaderService.php` | 3 | New service |
| `tests/` | 1, 2, 3 | Tests for repository, entity, downloader |

---

### Task 1: ProductRepository::findSimilar()

**Files:**
- Modify: `src/Repository/ProductRepository.php`
- Create: `tests/Repository/ProductRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Brand;
use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProductRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProductRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(ProductRepository::class);
    }

    public function testFindSimilarReturnsProductsFromSameCategory(): void
    {
        // Create shared category
        $category = new ProductCategory();
        $category->setTitle('TestCat');
        $this->em->persist($category);

        $brand = $this->em->getRepository(Brand::class)->findOneBy([]);

        // Create reference product
        $product = new Product();
        $product->setTitle('Reference');
        $product->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $product->setBrand($brand);
        $product->setCategory($category);
        $product->setStatus('active');
        $this->em->persist($product);

        // Create similar product
        $similar = new Product();
        $similar->setTitle('Similar One');
        $similar->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $similar->setBrand($brand);
        $similar->setCategory($category);
        $similar->setStatus('active');
        $this->em->persist($similar);

        // Create unrelated product (different category)
        $otherCategory = new ProductCategory();
        $otherCategory->setTitle('OtherCat');
        $this->em->persist($otherCategory);

        $unrelated = new Product();
        $unrelated->setTitle('Unrelated');
        $unrelated->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $unrelated->setBrand($brand);
        $unrelated->setCategory($otherCategory);
        $unrelated->setStatus('active');
        $this->em->persist($unrelated);

        $this->em->flush();

        $result = $this->repo->findSimilar($product, 5);

        $this->assertCount(1, $result);
        $this->assertSame('Similar One', $result[0]->getTitle());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./bin/phpunit tests/Repository/ProductRepositoryTest.php --filter=testFindSimilarReturnsProductsFromSameCategory -v`
Expected: Error — `Method ProductRepository::findSimilar not found`

- [ ] **Step 3: Implement findSimilar()**

Add to `src/Repository/ProductRepository.php`:

```php
/**
 * @return Product[]
 */
public function findSimilar(Product $product, int $limit = 8): array
{
    $qb = $this->createQueryBuilder('p')
        ->leftJoin('p.brand', 'b')
        ->leftJoin('p.productImages', 'pi', 'WITH', 'pi.isMain = true')
        ->addSelect('b', 'pi')
        ->where('p.status = :status')
        ->andWhere('b.status = :brandStatus')
        ->andWhere('p.id != :productId')
        ->setParameter('status', 'active')
        ->setParameter('brandStatus', 'active')
        ->setParameter('productId', $product->getId())
        ->orderBy('p.id', 'DESC')
        ->setMaxResults($limit);

    // Same category first, then same brand
    if ($product->getCategory()) {
        $qb->andWhere('p.category = :category OR p.brand = :brand')
           ->setParameter('category', $product->getCategory())
           ->setParameter('brand', $product->getBrand());
    } else {
        $qb->andWhere('p.brand = :brand')
           ->setParameter('brand', $product->getBrand());
    }

    return $qb->getQuery()->getResult();
}
```

- [ ] **Step 4: Run tests**

Run: `./bin/phpunit tests/Repository/ProductRepositoryTest.php --filter=testFindSimilarReturnsProductsFromSameCategory -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Repository/ProductRepository.php tests/Repository/ProductRepositoryTest.php
git commit -m "feat: add ProductRepository::findSimilar()"
```

---

### Task 2: CatalogController — pass brand data + similar products

**Files:**
- Modify: `src/Controller/Catalog/CatalogController.php`

- [ ] **Step 1: Update show() action**

Inject `ProductRepository` and pass similar products + brand links to template:

```php
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
```

- [ ] **Step 2: Run existing tests to verify no regression**

Run: `./bin/phpunit --filter=CatalogController`
Expected: No failing tests

- [ ] **Step 3: Commit**

```bash
git add src/Controller/Catalog/CatalogController.php
git commit -m "feat: pass similarProducts to product page template"
```

---

### Task 3: show.html.twig — gallery with lightbox

**Files:**
- Modify: `templates/catalog/show.html.twig`

- [ ] **Step 1: Replace gallery section with thumbnails + lightbox**

In `templates/catalog/show.html.twig`, replace the images section (lines 86-112) with:

```twig
{# ── Галерея ── #}
<div>
    {% if product.productImages|length > 0 %}
        <div class="bg-white rounded-xl overflow-hidden shadow-sm mb-3 relative group cursor-pointer"
             id="main-image-container">
            <img id="main-image"
                 src="{{ asset('images/products/' ~ product.mainImage.image ?? product.mainImage.preview) }}"
                 alt="{{ product.title }}"
                 class="w-full h-auto object-cover"
                 style="max-height:500px"
                 loading="lazy">
        </div>

        {% if product.productImages|length > 1 %}
        <div class="flex gap-2 overflow-x-auto pb-1" id="thumbnail-list">
            {% for image in product.productImages %}
            <button type="button"
                    class="thumbnail-btn w-20 h-20 rounded-lg overflow-hidden border-2 flex-shrink-0 transition cursor-pointer
                           {{ image.isMain ? 'border-gray-900' : 'border-transparent hover:border-gray-400' }}"
                    data-src="{{ asset('images/products/' ~ (image.image ?? image.preview)) }}"
                    data-full="{{ asset('images/products/' ~ (image.image ?? image.preview)) }}">
                <img src="{{ asset('images/products/' ~ (image.preview ?? image.image)) }}"
                     alt="" class="w-full h-full object-cover">
            </button>
            {% endfor %}
        </div>
        {% endif %}
    {% else %}
        <div class="bg-gray-100 rounded-xl flex items-center justify-center" style="height:400px">
            <span class="text-6xl text-gray-300">👗</span>
        </div>
    {% endif %}
</div>
```

- [ ] **Step 2: Add thumbnail + lightbox JS**

Add before the closing `{% endblock %}`:

```html
<script>
(function() {
    // ── Gallery thumbnails ──
    var mainImg = document.getElementById('main-image');
    var thumbs = document.querySelectorAll('.thumbnail-btn');
    thumbs.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var src = btn.getAttribute('data-src');
            if (mainImg) mainImg.src = src;
            thumbs.forEach(function(t) { t.classList.remove('border-gray-900'); t.classList.add('border-transparent'); });
            btn.classList.remove('border-transparent');
            btn.classList.add('border-gray-900');
        });
    });

    // ── Lightbox ──
    var container = document.getElementById('main-image-container');
    if (container) {
        container.addEventListener('click', function() {
            var src = mainImg ? mainImg.src : '';
            if (!src) return;
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.9);display:flex;align-items:center;justify-content:center;cursor:pointer';
            overlay.addEventListener('click', function() { document.body.removeChild(overlay); });
            var img = document.createElement('img');
            img.src = src;
            img.style.cssText = 'max-width:90vw;max-height:90vh;object-fit:contain;border-radius:8px';
            overlay.appendChild(img);
            document.body.appendChild(overlay);
        });
    }
})();
</script>
```

- [ ] **Step 3: Verify template renders**

Run: `./bin/console cache:clear`
Expected: No errors

- [ ] **Step 4: Commit**

```bash
git add templates/catalog/show.html.twig
git commit -m "feat: product gallery with thumbnails and lightbox"
```

---

### Task 4: show.html.twig — color swatches + discount price + size selector

**Files:**
- Modify: `templates/catalog/show.html.twig`

- [ ] **Step 1: Add data attributes to variant buttons**

The existing size buttons already have `data-variant-id`, `data-price`, `data-compare-price`.
Add `data-color`, `data-color-hex`, `data-stock`:

```twig
{% for variant in product.variants %}
    {% if variant.status == 'active' and variant.isInStock %}
        <button type="button"
                class="size-btn px-4 py-2 border rounded-lg text-sm transition cursor-pointer"
                data-variant-id="{{ variant.id }}"
                data-price="{{ variant.priceFloat }}"
                data-compare-price="{{ variant.comparePrice }}"
                data-color="{{ variant.color }}"
                data-color-hex="{{ variant.colorHex }}"
                data-stock="{{ variant.stockQty }}">
            {{ variant.size ?: variant.sku }}
        </button>
    {% endif %}
{% endfor %}
```

- [ ] **Step 2: Add color swatches before sizes**

```twig
{% set grouped = product.variants|filter(v => v.status == 'active' and v.isInStock) %}
{% set uniqueColors = {} %}
{% for v in grouped %}
    {% set key = v.color ~ '|' ~ v.colorHex %}
    {% if key not in uniqueColors|keys %}
        {% set uniqueColors = uniqueColors|merge({(key): {'color': v.color, 'colorHex': v.colorHex}}) %}
    {% endif %}
{% endfor %}

{% if uniqueColors|length > 0 %}
<div class="mb-6">
    <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Цвет</div>
    <div class="flex flex-wrap gap-2" id="color-swatches">
        {% for key, colorData in uniqueColors %}
        <button type="button"
                class="color-swatch w-9 h-9 rounded-full border-2 transition cursor-pointer
                       {{ loop.first ? 'border-gray-900' : 'border-gray-300 hover:border-gray-500' }}"
                style="background-color: {{ colorData.colorHex ?: '#ccc' }}"
                data-color="{{ colorData.color }}"
                data-color-hex="{{ colorData.colorHex }}"
                title="{{ colorData.color }}">
        </button>
        {% endfor %}
    </div>
</div>
{% endif %}
```

- [ ] **Step 3: Add discount price display**

Replace the price div (lines 124-128):

```twig
<div class="mb-4" id="price-display">
    {% set firstVar = product.variants|filter(v => v.isInStock and v.status == 'active')|first %}
    {% if firstVar %}
        <div class="flex items-center gap-3">
            {% if firstVar.comparePrice and firstVar.comparePrice > firstVar.price %}
                <span class="text-2xl text-gray-400 line-through">{{ firstVar.comparePrice|price }}</span>
                <span class="text-sm font-bold text-red-500 bg-red-50 px-2 py-0.5 rounded">−{{ firstVar.discountPercent }}%</span>
            {% endif %}
            <span class="text-3xl font-bold" id="current-price">{{ firstVar.priceFloat|price }}</span>
        </div>
    {% else %}
        <span class="text-3xl font-bold">—</span>
    {% endif %}
</div>
```

- [ ] **Step 4: Add color swatch + price update JS**

Append to existing `<script>` block:

```javascript
// ── Color swatches ──
var swatches = document.querySelectorAll('.color-swatch');
var sizeBtns = document.querySelectorAll('.size-btn');
var priceDisplay = document.getElementById('current-price');
var priceBlock = document.getElementById('price-display');

swatches.forEach(function(sw) {
    sw.addEventListener('click', function() {
        var color = sw.getAttribute('data-color');
        swatches.forEach(function(s) { s.classList.remove('border-gray-900'); s.classList.add('border-gray-300', 'hover:border-gray-500'); });
        sw.classList.remove('border-gray-300', 'hover:border-gray-500');
        sw.classList.add('border-gray-900');

        // Filter sizes by selected color
        sizeBtns.forEach(function(btn) {
            var btnColor = btn.getAttribute('data-color');
            if (!color || !btnColor || btnColor === color) {
                btn.style.display = '';
            } else {
                btn.style.display = 'none';
            }
        });

        // Reset selection, select first visible
        var visible = Array.from(sizeBtns).filter(function(b) { return b.style.display !== 'none'; });
        sizeBtns.forEach(function(b) {
            b.classList.remove('ring-2', 'ring-gray-900', 'border-gray-900');
            b.classList.add('border-gray-300', 'hover:border-gray-900');
        });
        if (visible.length > 0) {
            visible[0].classList.add('ring-2', 'ring-gray-900', 'border-gray-900');
            visible[0].classList.remove('border-gray-300', 'hover:border-gray-900');
            updatePrice(visible[0]);
            selectedId = visible[0].getAttribute('data-variant-id');
            form.action = form.action.replace(/\/cart\/add\/\d+$/, '/cart/add/' + selectedId);
        }
    });
});

function updatePrice(btn) {
    var price = btn.getAttribute('data-price');
    var compare = btn.getAttribute('data-compare-price');
    var discountHtml = '';
    if (compare && parseFloat(compare) > parseFloat(price)) {
        var pct = Math.round((1 - price / compare) * 100);
        discountHtml = '<span class="text-2xl text-gray-400 line-through">' + parseFloat(compare).toLocaleString('ru-RU') + ' ₽</span> <span class="text-sm font-bold text-red-500 bg-red-50 px-2 py-0.5 rounded">−' + pct + '%</span> ';
    }
    priceBlock.innerHTML = '<div class="flex items-center gap-3">' + discountHtml + '<span class="text-3xl font-bold">' + parseFloat(price).toLocaleString('ru-RU') + ' ₽</span></div>';
    priceDisplay = document.getElementById('current-price');
}

// Update existing size click handler to call updatePrice
sizeBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
        sizeBtns.forEach(function(b) {
            b.classList.remove('ring-2', 'ring-gray-900', 'border-gray-900');
            b.classList.add('border-gray-300', 'hover:border-gray-900');
        });
        btn.classList.add('ring-2', 'ring-gray-900', 'border-gray-900');
        btn.classList.remove('border-gray-300', 'hover:border-gray-900');
        selectedId = btn.getAttribute('data-variant-id');
        form.action = form.action.replace(/\/cart\/add\/\d+$/, '/cart/add/' + selectedId);
        updatePrice(btn);
    });
});
```

- [ ] **Step 5: Commit**

```bash
git add templates/catalog/show.html.twig
git commit -m "feat: color swatches and discount price display"
```

---

### Task 5: show.html.twig — quantity selector

**Files:**
- Modify: `templates/catalog/show.html.twig`

- [ ] **Step 1: Add quantity selector before add-to-cart button**

Before the `<form>` block (before line 184):

```twig
{# ── Количество ── #}
{% if firstVariant %}
<div class="mb-4" id="quantity-selector">
    <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Количество</div>
    <div class="flex items-center gap-0 border border-gray-300 rounded-lg w-fit">
        <button type="button"
                class="qty-btn px-3 py-2 text-lg font-medium text-gray-600 hover:bg-gray-100 transition disabled:opacity-30 rounded-l-lg"
                data-action="decrease" disabled>−</button>
        <input type="number"
               id="qty-input"
               value="1"
               min="1"
               max="{{ firstVariant.stockQty }}"
               class="w-12 text-center border-x border-gray-300 py-2 text-sm [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
               readonly>
        <button type="button"
                class="qty-btn px-3 py-2 text-lg font-medium text-gray-600 hover:bg-gray-100 transition disabled:opacity-30 rounded-r-lg"
                data-action="increase">+</button>
    </div>
    <span class="text-xs text-gray-400 ml-2">{{ firstVariant.stockQty }} шт. в наличии</span>
</div>
{% endif %}
```

- [ ] **Step 2: Add quantity JS + update form action to include qty**

```javascript
// ── Quantity selector ──
var qtyInput = document.getElementById('qty-input');
var qtyBtns = document.querySelectorAll('.qty-btn');
if (qtyInput) {
    function updateQtyBtns() {
        var val = parseInt(qtyInput.value) || 1;
        var max = parseInt(qtyInput.getAttribute('max'));
        qtyBtns.forEach(function(btn) {
            var action = btn.getAttribute('data-action');
            btn.disabled = (action === 'decrease' && val <= 1) || (action === 'increase' && val >= max);
        });
    }
    qtyBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var val = parseInt(qtyInput.value) || 1;
            var max = parseInt(qtyInput.getAttribute('max'));
            if (btn.getAttribute('data-action') === 'decrease' && val > 1) qtyInput.value = val - 1;
            if (btn.getAttribute('data-action') === 'increase' && val < max) qtyInput.value = val + 1;
            updateQtyBtns();
        });
    });
    updateQtyBtns();
}

// Update AJAX submit to include qty
form.addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = form.querySelector('button[type="submit"]');
    var orig = btn.textContent;
    btn.textContent = '…';
    btn.disabled = true;

    var qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;

    fetch(form.action, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: new URLSearchParams({qty: qty})
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { alert(data.error); return; }
            if (window.updateCartBadge) window.updateCartBadge(data.count);
            btn.textContent = '✓ В корзине';
            setTimeout(function() {
                btn.textContent = orig;
                btn.disabled = false;
            }, 1500);
        })
        .catch(function() {
            btn.textContent = 'Ошибка';
            btn.disabled = false;
        });
});
```

- [ ] **Step 3: Commit**

```bash
git add templates/catalog/show.html.twig
git commit -m "feat: quantity selector with stock limit"
```

---

### Task 6: show.html.twig — brand block

**Files:**
- Modify: `templates/catalog/show.html.twig`

- [ ] **Step 1: Add brand block below product card**

After the `.grid` div (before `</div>` closing container), add:

```twig
{# ── Блок бренда ── #}
<div class="mt-12 p-6 bg-white rounded-xl border border-gray-100">
    <div class="flex items-start gap-4">
        {% if brand.logo %}
        <div class="w-20 h-20 rounded-lg overflow-hidden bg-gray-50 flex-shrink-0">
            <img src="{{ asset('images/brands/' ~ brand.logo) }}"
                 alt="{{ brand.title }}"
                 class="w-full h-full object-contain">
        </div>
        {% else %}
        <div class="w-20 h-20 rounded-lg bg-gray-100 flex items-center justify-center text-3xl flex-shrink-0">🏷️</div>
        {% endif %}
        <div class="flex-1 min-w-0">
            <a href="{{ path('brand_show', {slug: brand.slug}) }}"
               class="text-xl font-bold hover:text-indigo-600 transition">
                {{ brand.title }}
            </a>
            {% if brand.anons %}
            <p class="text-gray-600 mt-1 text-sm">{{ brand.anons }}</p>
            {% endif %}
            {% if brand.links|length > 0 %}
            <div class="flex flex-wrap gap-2 mt-3">
                {% for link in brand.links %}
                <a href="{{ link.linkUrl }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-full transition">
                    {% if link.linkType == 'website' %}🌐{% elseif link.linkType == 'instagram' %}📷{% elseif link.linkType == 'telegram' %}✈️{% elseif link.linkType == 'vk' %}💙{% elseif link.linkType == 'youtube' %}▶️{% elseif link.linkType == 'tiktok' %}🎵{% else %}🔗{% endif %}
                    {{ link.linkType|capitalize }}
                </a>
                {% endfor %}
            </div>
            {% endif %}
        </div>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add templates/catalog/show.html.twig
git commit -m "feat: brand block on product page"
```

---

### Task 7: show.html.twig — similar products grid

**Files:**
- Modify: `templates/catalog/show.html.twig`

- [ ] **Step 1: Add similar products section after brand block**

```twig
{# ── Похожие товары ── #}
{% if similarProducts|length > 0 %}
<div class="mt-12">
    <h2 class="text-2xl font-bold mb-6">Похожие товары</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    {% for similar in similarProducts %}
        <a href="{{ path('product_show', {uuid: similar.uuid}) }}"
           class="group bg-white rounded-xl overflow-hidden border border-gray-100 hover:shadow-md transition">
            {% set simImg = similar.mainImage %}
            {% if simImg and (simImg.preview or simImg.image) %}
            <div class="aspect-[3/4] overflow-hidden bg-gray-50">
                <img src="{{ asset('images/products/' ~ (simImg.preview ?? simImg.image)) }}"
                     alt="{{ similar.title }}"
                     class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                     loading="lazy">
            </div>
            {% else %}
            <div class="aspect-[3/4] bg-gray-100 flex items-center justify-center text-4xl text-gray-300">👗</div>
            {% endif %}
            <div class="p-3">
                <p class="text-xs text-gray-500">{{ similar.brand.title }}</p>
                <p class="text-sm font-medium mt-0.5 line-clamp-2">{{ similar.title }}</p>
                <p class="text-sm font-bold mt-1">{{ similar.minPrice|price }}</p>
            </div>
        </a>
    {% endfor %}
    </div>
</div>
{% endif %}
```

- [ ] **Step 2: Commit**

```bash
git add templates/catalog/show.html.twig
git commit -m "feat: similar products grid on product page"
```

---

### Task 8: Product entity — new characteristics fields

**Files:**
- Modify: `src/Entity/Product.php`
- Modify: `src/Entity/ProductVariant.php` (add `getDiscountPercent()` if not already present)

- [ ] **Step 1: Add fields to Product entity**

Add after the `image` legacy field (line 101):

```php
    // Характеристики (Фаза 2)
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $material = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $composition = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $careInstructions = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $countryOfOrigin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;
```

Add getters/setters after `setImage()`:

```php
    public function getMaterial(): ?string { return $this->material; }
    public function setMaterial(?string $material): static { $this->material = $material; return $this; }

    public function getComposition(): ?string { return $this->composition; }
    public function setComposition(?string $composition): static { $this->composition = $composition; return $this; }

    public function getCareInstructions(): ?string { return $this->careInstructions; }
    public function setCareInstructions(?string $careInstructions): static { $this->careInstructions = $careInstructions; return $this; }

    public function getCountryOfOrigin(): ?string { return $this->countryOfOrigin; }
    public function setCountryOfOrigin(?string $countryOfOrigin): static { $this->countryOfOrigin = $countryOfOrigin; return $this; }

    public function getManufacturer(): ?string { return $this->manufacturer; }
    public function setManufacturer(?string $manufacturer): static { $this->manufacturer = $manufacturer; return $this; }
```

- [ ] **Step 2: Create migration**

Run: `./bin/console make:migration`
Expected: Migration file created with ALTER TABLE product ADD columns

- [ ] **Step 3: Run migration**

Run: `./bin/console doctrine:migrations:migrate --no-interaction`
Expected: Migrations executed

- [ ] **Step 4: Commit**

```bash
git add src/Entity/Product.php
git add src/Entity/ProductVariant.php
git add migrations/  # assuming migration files
git commit -m "feat: add product characteristics fields"
```

---

### Task 9: show.html.twig — characteristics table

**Files:**
- Modify: `templates/catalog/show.html.twig`

- [ ] **Step 1: Add characteristics table before brand block**

```twig
{# ── Характеристики ── #}
{% set chars = {
    'Материал': product.material,
    'Состав': product.composition,
    'Уход': product.careInstructions,
    'Страна производства': product.countryOfOrigin,
    'Производитель': product.manufacturer,
}|filter(v => v is not null) %}

{% if chars|length > 0 %}
<div class="mt-12">
    <h2 class="text-xl font-bold mb-4">Характеристики</h2>
    <div class="border border-gray-200 rounded-xl overflow-hidden max-w-2xl">
        {% for label, value in chars %}
        <div class="flex border-b border-gray-100 last:border-b-0">
            <div class="w-48 px-4 py-3 bg-gray-50 text-sm font-medium text-gray-600">{{ label }}</div>
            <div class="flex-1 px-4 py-3 text-sm text-gray-900">{{ value }}</div>
        </div>
        {% endfor %}
    </div>
</div>
{% endif %}
```

- [ ] **Step 2: Commit**

```bash
git add templates/catalog/show.html.twig
git commit -m "feat: product characteristics table"
```

---

### Task 10: ProductImportService — new characteristics columns

**Files:**
- Modify: `src/Service/ProductImportService.php`

- [ ] **Step 1: Update column constants**

Find the column mapping (around line 15-20) and add:

```php
private const COL_MATERIAL = 13;
private const COL_COMPOSITION = 14;
private const COL_CARE = 15;
private const COL_COUNTRY = 16;
private const COL_MANUFACTURER = 17;
private const COL_PHOTO_URLS = 18;
```

- [ ] **Step 2: Set characteristics in importProduct()**

In the import logic, after setting `$product->setWeight(...)`, add:

```php
$product->setMaterial($this->getCellValue($row, self::COL_MATERIAL));
$product->setComposition($this->getCellValue($row, self::COL_COMPOSITION));
$product->setCareInstructions($this->getCellValue($row, self::COL_CARE));
$product->setCountryOfOrigin($this->getCellValue($row, self::COL_COUNTRY));
$product->setManufacturer($this->getCellValue($row, self::COL_MANUFACTURER));
```

- [ ] **Step 3: Commit**

```bash
git add src/Service/ProductImportService.php
git commit -m "feat: add characteristics columns to product import"
```

---

### Task 11: ImageDownloaderService

**Files:**
- Create: `src/Service/ImageDownloaderService.php`
- Create: `tests/Service/ImageDownloaderServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ImageDownloaderService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ImageDownloaderServiceTest extends KernelTestCase
{
    public function testDownloadReturnsNullOnInvalidUrl(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(ImageDownloaderService::class);
        $result = $service->download('https://invalid.example/nonexistent.jpg');
        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./bin/phpunit tests/Service/ImageDownloaderServiceTest.php -v`
Expected: Error — `Class ImageDownloaderService not found`

- [ ] **Step 3: Implement ImageDownloaderService**

Create `src/Service/ImageDownloaderService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ImageDownloaderService
{
    private const TIMEOUT = 10;
    private const MAX_SIZE = 5 * 1024 * 1024;
    private const TARGET_DIR = 'images/products';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $publicDir, // %kernel.project_dir%/public
    ) {}

    public function download(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT,
            ]);

            $content = $response->getContent();
            $contentLength = strlen($content);

            if ($contentLength > self::MAX_SIZE) {
                $this->logger->warning('Image too large', ['url' => $url, 'size' => $contentLength]);
                return null;
            }

            $extension = $this->getExtension($url, $response->getHeaders()['content-type'][0] ?? '');
            $filename = Uuid::v4()->toRfc4122() . '.' . $extension;
            $targetDir = $this->publicDir . '/' . self::TARGET_DIR;

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }

            file_put_contents($targetDir . '/' . $filename, $content);

            return $filename;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to download image', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function getExtension(string $url, string $contentType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        foreach ($map as $type => $ext) {
            if (str_contains($contentType, $type)) {
                return $ext;
            }
        }

        // Fallback: extract from URL
        $path = parse_url($url, PHP_URL_PATH);
        $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']) ? $ext : 'jpg';
    }
}
```

- [ ] **Step 4: Register arguments in services.yaml**

Add to `config/services.yaml`:

```yaml
App\Service\ImageDownloaderService:
    arguments:
        $publicDir: '%kernel.project_dir%/public'
```

- [ ] **Step 5: Run tests**

Run: `./bin/phpunit tests/Service/ImageDownloaderServiceTest.php -v`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Service/ImageDownloaderService.php tests/Service/ImageDownloaderServiceTest.php
git commit -m "feat: ImageDownloaderService for importing product photos"
```

---

### Task 12: ProductImportService — image column and download integration

**Files:**
- Modify: `src/Service/ProductImportService.php`

- [ ] **Step 1: Add photo URL import logic**

In the import loop, after creating/setting ProductImage entities, add:

```php
use App\Service\ImageDownloaderService;

// In constructor, add:
public function __construct(
    private ImageDownloaderService $imageDownloader,
    // ... existing deps
) {}

// In importProduct(), after setting fields:
$photoUrls = $this->getCellValue($row, self::COL_PHOTO_URLS);
if ($photoUrls) {
    $urls = array_filter(array_map('trim', explode('|', $photoUrls)));
    $sort = 0;
    foreach ($urls as $url) {
        $filename = $this->imageDownloader->download($url);
        if ($filename === null) {
            continue; // skip failed downloads
        }
        $image = new ProductImage();
        $image->setImage($filename);
        $image->setPreview($filename);
        $image->setIsMain($sort === 0);
        $image->setSort($sort);
        $product->addProductImage($image);
        $this->em->persist($image);
        $sort++;
        if ($sort >= 10) break; // max 10 photos
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Service/ProductImportService.php
git commit -m "feat: import product photos from URL column"
```
