# AGENTS.md - Developer Guidelines for Wearbase

> **Role**: Senior Symfony Architect
> **Architecture**: SOLID, DDD, Hexagonal Architecture
> **Principles**: Avoid anemic models · Use DI and autowiring

---

## 🚨 Mandatory Rules

These rules are **non-negotiable**. Violate them and the code will be rejected.

### Rule 1: Think Before Coding
Read every file involved before touching anything. Check how similar features are already built. If something is unclear, stop and ask. **Never assume the structure. VERIFY it first.**

### Rule 2: Simplicity First
Fewer lines always wins. No premature abstractions. No over-engineering. No 12-file solution for a 2-file problem. If a junior dev can't read it, rewrite it.

### Rule 3: Surgical Changes Only
Edit what's broken. Nothing else. No rewriting entire files to fix one function. No touching imports you weren't asked about. **Small diffs. Always.**

### Rule 4: Goal-Driven Execution
Ask WHY before writing HOW. If the task is unclear, stop and ask. Don't build what you think they want. Build what they **ACTUALLY** asked for.

### Rule 5: Branch Per Change
Every change goes on its own branch — **never commit or push directly to `main`**. `main` is protected: it only accepts merges through a Pull Request. Workflow:

```bash
git checkout -b <type>/<short-desc>   # feat/… fix/… docs/… refactor/…
# make changes + atomic commits
git push -u origin <type>/<short-desc>
gh pr create --fill                   # merge into main via PR
```

One logical change = one branch = one PR. Don't stack unrelated work on the same branch.

---

## Project Overview

| Aspect | Value |
|--------|-------|
| **Framework** | Symfony 7.3 |
| **PHP** | >=8.2 |
| **Test** | PHPUnit 12.4 |
| **Database** | Doctrine ORM (DBAL 3.x) |
| **Admin** | EasyAdminBundle + nevinny/admin-core |

### Entities — текущие (App namespace)

**Бренды/Каталог**: `Brand`, `BrandLink`, `BrandSize`, `BrandAudience`, `BrandTier`, `BrandImage`, `BrandStyle`, `Alphabet`

**Товары**: `Product` (базовая — расширяется)

**Admin/Core**: `User` (только /admin), `Main`, `SectionType`, `SectionLink` (from nevinny/admin-core)

> **База данных**: ~779 подтверждённых российских брендов + 5439 брендов с Lamoda (требуют классификации по происхождению). SQL-файлы в `_sql/`.

---

### Entities — запланированные (ЛК Бренда + ЛК Клиента)

Создать в `src/Entity/` (подробная схема в `_docs/lk-design.md`):

| Entity | Назначение |
|--------|-----------|
| `User` (App) | Front-end пользователи (покупатели + менеджеры брендов) |
| `BrandUser` | Pivot: пользователь ↔ бренд с ролью (owner/manager) |
| `BrandInvite` | Приглашение менеджера в бренд по email |
| `ProductCategory` | Дерево категорий товаров (Худи / Куртки / Джинсы...) |
| `ProductVariant` | SKU: размер + цвет + цена + остаток |
| `ProductImage` | Фотографии товара (привязка к товару или варианту) |
| `Address` | Адреса доставки покупателя |
| `Cart` | Корзина (для авторизованных и гостей по sessionId) |
| `CartItem` | Позиция в корзине |
| `Order` | Заказ (один бренд = один заказ) |
| `OrderItem` | Позиция в заказе (snapshot цены/названия) |
| `OrderStatusHistory` | История смен статуса заказа |
| `Notification` | In-app уведомления |
| `NotificationSettings` | Настройки каналов уведомлений пользователя |

Расширить `Product`: добавить `title`, `slug`, `category`, `gender`, `styles`, `status`, SEO-поля.

### Роли пользователей (front-end)

```
ROLE_USER            — базовая (все авторизованные)
ROLE_CUSTOMER        — покупатель
ROLE_BRAND_MANAGER   — менеджер бренда (через BrandUser)
ROLE_BRAND_OWNER     — владелец бренда (может приглашать)
```

### Статусы заказа

`new → confirmed → processing → shipped → delivered → completed`  
Специальные: `cancelled`, `returned`, `refunded`

### Каналы уведомлений

- **In-app** — колокольчик, таблица `notification`
- **Email** — Symfony Mailer (Brevo/Mailgun)
- **Telegram** — Symfony Notifier TelegramTransport (chatId в User.telegramChatId)
- **Push** — Web Push / vapid

---

## Commands

### Tests
```bash
./bin/phpunit                              # All tests
./bin/phpunit tests/Controller/Test.php   # Single file
./bin/phpunit --filter=testMethodName      # Single test
./bin/phpunit --coverage-html var/coverage # With coverage
```

### Symfony Console
```bash
./bin/console cache:clear              # Clear cache
./bin/console debug:router             # List routes
./bin/console doctrine:migrations:migrate  # Run migrations
./bin/console make:migration           # Create migration
```

### Docker
```bash
docker compose up -d   # Start
docker compose down    # Stop
```

---

## Code Style

### General
- **Indentation**: 4 spaces
- **Line endings**: LF
- **Charset**: UTF-8

### Naming

| Element | Convention | Example |
|---------|------------|---------|
| Classes | PascalCase | `BrandService` |
| Methods | camelCase | `getBrandsByLetter()` |
| Properties | camelCase | `$brandRepository` |
| Constants | UPPER_SNAKE_CASE | `CACHE_TTL` |
| DB tables | snake_case | `brand_size_brand` |

### PHP Syntax

```php
// Nullable return types
public function getId(): ?int

// Constructor injection
public function __construct(
    private BrandRepository $brandRepository,
    private CacheInterface $cache
) {}

// Fluent setters (return static)
public function setTitle(?string $title): static
{
    $this->title = $title;
    return $this;
}

// Doctrine collections
/**
 * @var Collection<int, Product>
 */
#[ORM\OneToMany(targetEntity: Product::class, mappedBy: 'brand')]
private Collection $products;

public function __construct()
{
    $this->products = new ArrayCollection();
}

// Doctrine entity
#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Brand
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
}
```

### Imports

Group: PHP built-ins → Vendor → App. Use aliases: `use Doctrine\ORM\Mapping as ORM;`

```php
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\BrandRepository;
```

### Error Handling

```php
return new Response($xml, 200, ['Content-Type' => 'text/xml']);
throw $this->createNotFoundException('Brand not found');
```

### Doctrine

- Use repository classes for queries
- Define relationships with proper cascade settings

```php
// In repositories
public function findAvailableFirstLetters(string $locale): array
{
    return $this->createQueryBuilder('b')
        ->select('DISTINCT b.firstLetter')
        ->andWhere('b.status = :status')
        ->setParameter('status', 'published')
        ->getQuery()
        ->getResult();
}
```

### Services

```php
class BrandService
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private BrandRepository $brandRepository,
        private CacheInterface $cache
    ) {}
}
```

### Testing

```php
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BrandControllerTest extends WebTestCase
{
    public function testSomething(): void
    {
        $client = static::createClient();
        $client->request('GET', '/brands');
        $this->assertResponseIsSuccessful();
    }
}
```

---

## Design Principles

### SOLID
- **S**ingle Responsibility: One class = one reason to change
- **O**pen/Closed: Open for extension, closed for modification
- **L**iskov Substitution: Subtypes must be substitutable for base types
- **I**nterface Segregation: Many small interfaces > one large interface
- **D**ependency Inversion: Depend on abstractions, not concretions

### DRY (Don't Repeat Yourself)
- Extract common logic into shared methods/services
- Use traits for reusable behavior across entities
- Define constants for magic values

### KISS (Keep It Simple, Stupid)
- Prefer simple solutions over complex ones
- Avoid over-engineering: write code that's easy to understand now
- Refactor when complexity grows

---

## Symfony Best Practices

### Autowire

Symfony automatically injects dependencies. Just type-hint in constructor:

```php
public function __construct(
    private BrandRepository $brandRepository,
    private CacheInterface $cache,
    private LoggerInterface $logger
) {}
```

No need to manually configure services - autowire is enabled by default.

### Controller Best Practices

- Keep controllers thin - delegate logic to services
- Use `$this->json()`, `$this->render()`, `$this->redirect()`
- Return `Response` objects or use #[Route] attribute
- Avoid business logic in controllers

```php
#[Route('/brands', name: 'brand_index')]
public function index(BrandService $brandService): Response
{
    $brands = $brandService->getAllBrands();
    return $this->render('brand/index.html.twig', [
        'brands' => $brands
    ]);
}
```

### Service Best Practices

- One service = one responsibility
- Use interfaces for testability
- Inject only what you need
- Use private services when not reused

### Entity Best Practices

- Keep entities simple - data + relations only
- Use traits for common fields (Created, Status, etc.)
- Don't put business logic in entities
- Use VichUploader for file uploads

### Form Handling

- Create forms as separate classes in `src/Form/`
- Use form types for reusability
- Handle validation with annotations or constraints

---

## Directory Structure

```
src/
├── Command/           # Console commands
├── Controller/
│   ├── Admin/         # EasyAdmin CRUD controllers
│   ├── Account/       # ЛК Клиента (/account/*)
│   ├── Brand/         # ЛК Бренда (/brand/*)
│   ├── Brands/        # Публичные страницы брендов
│   ├── Catalog/       # Каталог товаров (публичный)
│   └── Dev/           # Dev-only контроллеры
├── Entity/            # Doctrine entities
├── EventListener/     # Event subscribers
├── Form/              # Symfony Form Types
├── Notification/      # Сервисы уведомлений
├── Repository/        # Doctrine repositories
└── Service/           # Business logic

_docs/                 # Архитектурная документация
├── lk-design.md       # Дизайн ЛК Бренда + ЛК Клиента
_sql/                  # SQL-файлы и данные для импорта

tests/                 # PHPUnit tests
```

---

## Key Dependencies

- `doctrine/orm` ^3.5, `easycorp/easyadmin-bundle` ^4.27
- `nevinny/admin-core` ^1.0.4, `vich/uploader-bundle` ^2.8
- `symfony/ux-turbo`, `symfony/ux-twig-component`

---

## Environment Files

- `.env` - Default
- `.env.dev` - Dev
- `.env.test` - Test
- `.env.local` - Local (gitignored)

---

## SEO Page Type Matrix (SEO Rules v2.2.0)

Per SEO Rule 2.11, each page type must have documented strategy:

| Тип страницы | Route | Index | Canonical | Sitemap | Обязательные ссылки |
|---|---|---|---|---|---|
| Главная (Hub) | `home_hub` | ✅ | self | ✅ | Cities, Styles, Featured brands |
| Каталог брендов | `brand_index` | ✅ | self | ✅ | Breadcrumbs, alphabet nav |
| Бренд (карточка) | `brand_show` | ✅ | self | ✅ | Breadcrumbs, Similar brands |
| Каталог товаров | `catalog_index` | ✅ | self | ✅ | Breadcrumbs, фильтры |
| Карточка товара | `product_show` | ✅ | self | ✅ | Breadcrumbs, бренд, похожие |
| Фильтры (letter/city/style) | `brand_index?letter=X` | ❌ noindex,follow | → base | ❌ | — |
| Пагинация page 2+ | `brand_index?page=N` | ❌ noindex,follow | self | ❌ | Breadcrumbs, nav |
| ЛК (account/brand) | `/account/*`, `/brand/*` | ❌ noindex | — | ❌ | — |
| Admin | `/admin/*` | ❌ | — | ❌ | — |

### Ключевые правила

- Фильтры (`?letter=`, `?city=`, `?style=`) → `noindex, follow` (SEO Rule 2.9)
- Пагинация page 2+ → `noindex, follow`, self-canonical (SEO Rule 2.8, 10.3)
- `robots.txt` блокирует UTM/референс параметры: `Disallow: /*?utm_*` (SEO Rule 1.7)
- Canonical = sitemap = served URL (trailing slash consistency, SEO Rule 1.1)
- **Trailing slash policy**: URLs БЕЗ trailing slash (Symfony default). `.htaccess` redirect: `(.+)/$` → `/$1` (301)
