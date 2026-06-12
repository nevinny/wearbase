# Product Page Redesign (по WB)

**Дата:** 2026-05-24
**Статус:** Утверждён

## Layout

WB-style: 2 колонки на desktop (галерея слева, информация справа), 1 колонка на mobile.
Ниже сгиба на всю ширину: характеристики, блок бренда, похожие товары.

## Фаза 1 — UI (только шаблон + JS)

### 1. Галерея с зумом

- Клик по превью (маленькое фото) → меняется главное фото (fade transition)
- Клик по главному фото → lightbox (модальное окно с полноразмерным изображением)
- Vanilla JS (no Stimulus, no libraries)

### 2. Свотчи цвета

- Варианты группируются по `color` + `colorHex` на клиенте (данные уже в DOM)
- Рендерятся как кружки (background = colorHex)
- Клик по свотчу → показываются размеры только этого цвета
- Цена обновляется при выборе свотча
- Главное фото меняется, если есть `ProductImage.variant` с этим цветом

### 3. Цена со скидкой

- Если выбран вариант с `comparePrice > price`:
  `~~comparePrice~~` в сером + `−X%` красным бейджем + `price` крупно
- Если `comparePrice = null`: только `price`
- Если ни один вариант не выбран: `minPrice от ...`
- `discountPercent = round((1 - price/comparePrice) * 100)`

### 4. Селектор количества

- `[−] [1] [+]` — кнопки, все в inline JS
- `−` disabled при 1
- `+` disabled при `stockQty`
- Передаётся как `qty` в `cart_add`

### 5. Блок бренда

- Логотип (`brand.logo`), анонс (`brand.anons`), ссылки (`brand.links` по типу)
- Ниже карточки товара, на всю ширину
- Иконки соцсетей: Website, Instagram, Telegram

### 6. Похожие товары

- `ProductRepository::findSimilar(Product, int limit = 8)` — по категории + стилям, исключая текущий
- Сначала товары того же бренда
- Сетка 4 колонки desktop / 2 колонки mobile
- Карточки как в каталоге

## Фаза 2 — Характеристики (новые поля)

### Новые поля на Product

| Поле | Тип | Назначение |
|------|-----|------------|
| `material` | string(255), nullable | Материал (Хлопок 100%) |
| `composition` | string(500), nullable | Состав (95% хлопок, 5% эластан) |
| `careInstructions` | text, nullable | Уход (Машинная стирка до 30°C) |
| `countryOfOrigin` | string(100), nullable | Страна производства |
| `manufacturer` | string(255), nullable | Производитель |

### Отображение

- Таблица 2 колонки (ключ | значение) под карточкой товара
- Показывать только заполненные поля
- Положение: между карточкой товара и блоком бренда

### Импорт

- Колонки 13-17 в `ProductImportService`
- Обновить XLSX-шаблон

## Фаза 3 — Изображения в импорте

### Формат

- Новая колонка 18 «Фото (URL)»
- Несколько URL через `|`, максимум 10

### ImageDownloaderService

- `download(string $url): ?string` — скачивает через `Symfony HttpClient`
- Сохраняет в `public/images/products/uuid_originalname.ext`
- При ошибке → лог + return null (не блокирует импорт)
- Timeout 10s, max size 5MB
- Первое фото → `isMain = true`
- `sort` = порядок в списке

### Привязка к варианту

- `ProductImage.variant` — nullable ManyToOne (уже есть)
- При импорте: если фото специфично для варианта — привязывать через `variant`

## JS Implementation

- Vanilla JS, inline в `show.html.twig`
- Без Stimulus (соответствует существующему паттерну)

## Affected Files

### Фаза 1
- `templates/catalog/show.html.twig` — все UI изменения
- `src/Controller/Catalog/CatalogController.php` — передать brand, similar products
- `src/Repository/ProductRepository.php` — новый метод findSimilar()

### Фаза 2
- `src/Entity/Product.php` — новые поля + getter/setter
- `src/Service/ProductImportService.php` — новые константы + обработка

### Фаза 3
- `src/Service/ProductImportService.php` — колонка 18
- `src/Service/ImageDownloaderService.php` — новый сервис
- `src/Entity/ProductImage.php` — уже готово (variant relation есть)
