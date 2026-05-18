# SEO Rules — универсальные правила для роста в поиске by design

> Извлечено из трёхстороннего конкурентного анализа (GetTransfer vs WelcomePickups vs SunTransfers):
> голова к голове по страницам Milan Malpensa, Istanbul Sabiha, Bangkok→Pattaya,
> структурного сравнения 4 500 vs 4 778 vs 43 173 URL, и анализа 10 постов блога конкурента.
>
> **Применимо к любому сайту с каталогом страниц** (маркетплейс, агрегатор, SaaS, e-commerce,
> местный бизнес, B2B-сервис). Принципы масштабируются от 50 до 50 000 страниц.

---

## Ключевые инсайты из анализа

Прежде чем переходить к правилам — диагноз, почему проекты проигрывают в поиске
при наличии хорошего продукта и большого каталога:

### Инсайт 1 — «Данные есть, но поиск их не видит»
Самая частая причина потери позиций: **ценные коммерческие сигналы живут в DOM,
но не обёрнуты в JSON-LD**. Рейтинги, цены, отзывы, даты обновления — всё это есть
на странице для пользователя, но поисковик и AI-движки их не видят. Конкуренты
с теми же данными, но с правильной разметкой, получают ⭐ в выдаче и цитирование
в ChatGPT/Perplexity — вы нет.

**Принцип**: структурированные данные — это не «SEO-украшение», это API
между вашим сайтом и поисковыми/AI-системами.

### Инсайт 2 — «Тонкие страницы без бренд-авторитета — худшая позиция»
Можно быть тонким (ST: 43k страниц по 700 слов) и выигрывать, если у вас
30 000 агрегированных отзывов в схеме. Можно быть глубоким (WP: 4.5k страниц
по 2 000+ слов) и выигрывать, если у вас 450 экспертных статей строят
топический авторитет. **Быть тонким БЕЗ бренд-сигналов — проигрышная стратегия**.

### Инсайт 3 — «Шаблонные баги убивают страницы быстрее, чем конкуренты»
Страница Istanbul Sabiha занимает позицию 16.8 не потому что конкуренты сильнее,
а потому что в её `<title>` написано "Istanbul Airport Transfers" (IST) вместо
"Istanbul Sabiha Gökçen Airport Transfers" (SAW). Один шаблонный баг в title/H1
= конфликт сигналов = Page 2. Аудит шаблонов ценнее, чем написание новых статей.

### Инсайт 4 — «AI-поиск работает по другим правилам»
ChatGPT, Perplexity, Gemini AI Overview **не ранжируют страницы** — они извлекают
структурированный контент: FAQPage Q&A, именованные сущности, атрибутированные цитаты.
Страница без FAQ-блока и без схемы `@graph` просто не существует для AI-ответов.
Это не будущее — это 2025-2026.

### Инсайт 5 — «Длина статьи не равна качеству»
WP пишет посты в блоге по 600–1 300 слов и ранжируется. Не потому что «контент
короткий», а потому что структура правильная: intro → narrative H2s → PAA-вопросы
в виде H2 → named author schema → full @graph. **1 000 правильно структурированных
слов лучше 3 000 слов без разметки и структуры**.

### Инсайт 6 — «Внутренние ссылки — это валюта авторитета»
WP даёт 800–1 200 внутренних ссылок с каждой страницы; у GetTransfer — 36–45
(структурный лимит шаблона). Внутренние ссылки — это то, как топический авторитет
распределяется по сайту. Без хаб-структуры каждая страница дерётся за себя в одиночку.

---

## Раздел 1 — Правила для архитектора (SEO + Tech Lead)

> Принимаемые решения при проектировании системы. Переделать их потом стоит
> в 10–100 раз дороже, чем заложить правильно с нуля.

### A1 — URL-структура должна отражать таксономию намерений

Каждый уровень URL = один уровень намерения пользователя.

```
/directions/{country}/{city}/                    ← city intent
/directions/{country}/{city}/airports/{slug}     ← airport intent
/directions/{country}/{city}/populars/{slug}     ← P2P route intent
/directions/{country}/{city}/hotels/{slug}       ← hotel pickup intent
/blog/transport/{city}/{slug}/                   ← informational intent
/services/{service-type}/                        ← service-type intent
```

Правила:
- Один URL — одно намерение. Не смешивать коммерческое и информационное в одном URL.
- Near-duplicate intent (CDG layover / things to do at CDG) → canonical к одному URL,
  второй URL можно оставить в sitemap для захвата трафика.
- Slug должен содержать **специфический идентификатор** сущности (имя аэропорта,
  название маршрута), не родовое слово. `sabiha-gokcen` → ок; `airport` → плохо.

### A2 — Каждый тип страницы = отдельный шаблон с отдельным набором JSON-LD

Один шаблон на все страницы приводит к тому, что ни один тип не имеет полной схемы.
Проектируйте шаблонную матрицу:

| Тип страницы | Обязательные @type в @graph |
|---|---|
| Любая страница | WebSite, Organization, BreadcrumbList, WebPage |
| Коммерческая (продукт/услуга) | + Product, AggregateRating, Offer |
| Страница с отзывами | + Review (per review), AggregateRating |
| Локальный бизнес / гео-страница | + LocalBusiness, PostalAddress |
| Блог/статья | + Article, Person (author), ImageObject |
| FAQ-блок | + FAQPage, Question, Answer |
| P2P маршрут | + TouristTrip |
| Главная/категория | + WebSite (с potentialAction для поиска) |

### A3 — AggregateRating должна строиться по гео-каскаду из данных

Для каталожных сайтов агрегированный рейтинг нужен **на каждой** странице,
но не у каждой страницы достаточно отзывов. Проектируйте fallback-цепочку:

```
1. Рейтинг специфичный для этой сущности (аэропорт / маршрут / отель)
2. Рейтинг по городу
3. Рейтинг по стране
4. Рейтинг по всему сайту (с явным указанием "X reviews globally")
```

**Критично**: то, что написано в JSON-LD, должно быть видно на странице.
Google штрафует за schema, не подкреплённую видимым контентом.

### A4 — Sitemap — отдельная система, не плагин

Sitemap влияет на:
- Скорость индексации новых страниц
- Краулинговый бюджет (Google не будет переобходить раздутые sitemaps)
- Hreflang-ошибки при неправильной сборке

Правила проектирования:
- Один `<url>` содержит `<loc>` + `<lastmod>` + все `<xhtml:link hreflang>` — не дублировать
  один и тот же URL в 31 языковом файле ситемапа.
- Цель: 1 sitemap-файл содержит все 4 500 URL с 31 hreflang каждый ≈ 25 MB.
  Не 744 MB из-за дублирования.
- Sitemap index должен разбивать по **типу**, а не по языку:
  `sitemap-airports.xml`, `sitemap-populars.xml`, `sitemap-blog.xml`.
- Исключайте из sitemap: служебные URL, пагинацию, параметрические URL,
  страницы с `noindex`.

### A5 — Dormant pages: не создавать новый контент, пока есть неопубликованный

Прежде чем запускать производство контента, аудит БД:

```sql
SELECT type, published, COUNT(*)
FROM direction_pages
GROUP BY type, published;
```

В нашем кейсе: 1 979 смоделированных URL с `published=false` — это
категорийные индексы (все аэропорты города / все маршруты из города),
которые строятся автоматически из существующих дочерних страниц.
**Публикация этих 2 000 URL стоит ноль слов контента и даёт +44% к каталогу.**

### A6 — Каталог масштабируется по P2P-комбинаторике, не по простому перечислению

SunTransfers: 771 «из» × avg 53 «до» = 40 921 страница.
GetTransfer: 4 500 / 31 язык ≈ 145 уникальных маршрутов.

Но: **нельзя публиковать пустые комбинаторные страницы** — это Panda-penalty
за thin content. Правило: страница публикуется только если:
- Есть реальные данные (цена, дистанция, хотя бы одна машина)
- Есть уникальный контентный блок (хотя бы шаблонный FAQ + per-mode таблица)
- Объём трафикового запроса ≥ 10 поисков/мес (GSC или Ahrefs)

### A7 — Языки: глубина важнее ширины

31 язык с машинным переводом хуже, чем 5 языков с нативным контентом.
Распределение по приоритету:

```
Tier 1 (нативный/редакторский контроль): en, de, fr, es, ru
Tier 2 (профессиональный перевод): it, pt, nl, pl, tr, ja, zh
Tier 3 (машинный + ревью): остальные
```

Sitemap должен отражать Tier — `hreflang` только для Tier 1+2 если Tier 3
не прошёл качественный гейт.

---

## Раздел 2 — Правила для программиста (Backend + Template)

> Правила, которые реализуются в коде: рендер шаблонов, JSON-LD генерация,
> мета-теги, внутренние ссылки, data-pipeline.

### P1 — Title и H1: специфичность обязательна

Шаблон title должен включать **все** специфические идентификаторы сущности:

```php
// ПЛОХО — для вторичного аэропорта Istanbul Sabiha (SAW):
"{city} Airport Transfers ({iata}) | {brand}"
// → "Istanbul Airport Transfers (SAW)" — конфликт с IST

// ХОРОШО:
"{city} {airport_label} Airport Transfers ({iata}) | {brand}"
// → "Istanbul Sabiha Gökçen Airport Transfers (SAW)"
```

Правило: если `airport_label` пустой/null → не убирать, а fallback к полному названию.
H1 и title должны быть семантически идентичны (не обязательно текстуально).

Аудитный SQL для поиска сломанных страниц:

```sql
SELECT id, type, city_name, airport_label, h1, title, impressions_28d
FROM direction_pages
WHERE type = 'airport'
  AND published = true
  AND (
    airport_label IS NULL OR airport_label = ''
    OR position(lower(airport_label) in lower(coalesce(h1, ''))) = 0
  )
ORDER BY impressions_28d DESC NULLS LAST
LIMIT 100;
```

### P2 — JSON-LD @graph: один блок с несколькими @type, не несколько блоков

```html
<!-- ПЛОХО — несколько <script> -->
<script type="application/ld+json">{"@type": "BreadcrumbList"...}</script>
<script type="application/ld+json">{"@type": "Product"...}</script>

<!-- ХОРОШО — один @graph -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {"@type": "WebSite", "@id": "#website", ...},
    {"@type": "WebPage", "@id": "#webpage", ...},
    {"@type": "Organization", "@id": "#organization", ...},
    {"@type": "BreadcrumbList", ...},
    {"@type": "Product", ...},
    {"@type": "AggregateRating", ...}
  ]
}
</script>
```

Используйте `@id` для перекрёстных ссылок между типами внутри @graph.

### P3 — Генерация AggregateRating: pipeline из DWH

```php
// Псевдокод для генерации AggregateRating с гео-каскадом
function getAggregateRating(DirectionPage $page): ?AggregateRating
{
    $scopes = [
        ['airport_id' => $page->airportId],           // аэропорт
        ['city_id' => $page->cityId],                 // город
        ['country_id' => $page->countryId],           // страна
        [],                                            // весь сайт
    ];

    foreach ($scopes as $scope) {
        $result = $this->dwh->query(
            'SELECT AVG(rating) as avg, COUNT(*) as cnt
             FROM transfers WHERE rating IS NOT NULL',
            $scope
        );
        if ($result->cnt >= 30) {  // минимальный порог
            return new AggregateRating(
                ratingValue: round($result->avg, 1),
                reviewCount: $result->cnt,
                bestRating: 5,
                worstRating: 1
            );
        }
    }
    return null; // не показывать, если данных нет
}
```

**Важно**: render AggregateRating в видимой части страницы (рядом с H1),
иначе Google считает schema «invisible content» и штрафует.

### P4 — Offers block: fallback-цепочка, не silent failure

```php
// ПЛОХО — молчаливо возвращает пустой блок
$offers = $this->repo->getAirportOffers($airportId);
if (empty($offers)) return ''; // рендерим пустой H2

// ХОРОШО — fallback chain
$offers = $this->getOffersWithFallback($page);

private function getOffersWithFallback(DirectionPage $page): array
{
    // 1. Точный аэропорт
    $offers = $this->repo->getCompletedTransfers($page->airportId, limit: 9);
    if (count($offers) >= 3) return $offers;

    // 2. Любой аэропорт города
    $offers = $this->repo->getTransfersByCity($page->cityId, limit: 9);
    if (count($offers) >= 3) return $offers;

    // 3. Live offerable routes (не completed, а доступные к бронированию)
    $offers = $this->repo->getLiveRoutes($page->airportId, limit: 9);
    if (count($offers) >= 3) return $offers;

    // 4. Скрыть блок, не рендерить пустой H2
    return [];
}
```

### P5 — Reviews block: гео-скоп, не global fallback

```php
// ПЛОХО: показываем отзывы "Abu Dhabi → Dubai" на странице Istanbul
$reviews = $this->repo->getRandomReviews(limit: 7);

// ХОРОШО: гео-каскад
function getScopedReviews(DirectionPage $page, int $limit = 7): array
{
    $chains = [
        fn() => $this->repo->getReviews(airportId: $page->airportId, limit: $limit),
        fn() => $this->repo->getReviews(cityId: $page->cityId, limit: $limit),
        fn() => $this->repo->getReviews(countryId: $page->countryId, limit: $limit),
    ];

    foreach ($chains as $fetch) {
        $reviews = $fetch();
        if (count($reviews) >= 3) return $reviews;
    }
    return []; // скрыть блок, не показывать нерелевантное
}
```

### P6 — datePublished и dateModified: обязательно в WebPage schema

```php
// Каждая страница каталога должна иметь даты в JSON-LD
$webPage = [
    '@type' => 'WebPage',
    '@id' => $page->canonicalUrl . '#webpage',
    'url' => $page->canonicalUrl,
    'name' => $page->title,
    'datePublished' => $page->createdAt->format('Y-m-d'),
    'dateModified' => $page->updatedAt->format('Y-m-d'),
    'inLanguage' => $page->locale,
    'isPartOf' => ['@id' => '#website'],
];
// updatedAt должен обновляться при любом изменении контента страницы,
// включая изменение средней цены или рейтинга из DWH
```

### P7 — Wrapping Offers cards в Product + Offer + Review schema

Если на странице есть блок завершённых заказов/предложений с ценами и рейтингами:

```json
{
  "@type": "Product",
  "name": "Airport Transfer: Milan Malpensa → Milan City Center",
  "description": "Private transfer from Milan Malpensa Airport",
  "brand": {"@type": "Brand", "name": "GetTransfer"},
  "aggregateRating": {"@type": "AggregateRating", "ratingValue": "4.8", "reviewCount": 1247},
  "offers": [
    {
      "@type": "Offer",
      "name": "Malpensa → Milan (Economy sedan)",
      "price": "109",
      "priceCurrency": "USD",
      "availability": "https://schema.org/InStock",
      "areaServed": {"@type": "City", "name": "Milan"}
    }
  ],
  "review": [
    {
      "@type": "Review",
      "reviewRating": {"@type": "Rating", "ratingValue": "5"},
      "author": {"@type": "Person", "name": "John D."},
      "reviewBody": "Excellent driver, on time"
    }
  ]
}
```

**Данные уже есть в DOM — нужно только обернуть в JSON-LD. Ноль изменений контента.**

### P8 — FAQ: Q&A в H2 дешевле, чем FAQPage schema

WelcomePickups не использует `FAQPage` JSON-LD в своём блоге — они просто
добавляют 5–10 вопросов как H2 в конце статьи. Google всё равно подхватывает
их в PAA (People Also Ask). AI-движки тоже извлекают структурированные вопросы из H2.

Минимальная реализация (только HTML, без schema):

```html
<section id="faq">
  <h2>How long does a transfer from Malpensa to Milan take?</h2>
  <p>Approximately 45–60 minutes depending on traffic...</p>

  <h2>How much does a taxi from Malpensa to Milan cost?</h2>
  <p>Fixed taxi fare from Malpensa to Milan city centre is approximately €95...</p>

  <h2>Does GetTransfer offer transfers from Malpensa?</h2>
  <p>Yes — book a private transfer from €38 with meet & greet. <a href="/en/...">Book now</a></p>
</section>
```

Последний вопрос — CTA, встроенный в FAQ. Работает лучше баннера.

Если добавляете `FAQPage` schema — обязательно синхронизируйте с видимым контентом:

```json
{
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "How long does a transfer from Malpensa to Milan take?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Approximately 45–60 minutes depending on traffic..."
      }
    }
  ]
}
```

### P9 — Currency localization: показывать валюту пользователя, не default

```php
// ПЛОХО: все страницы рендерят US$ вне зависимости от страны страницы
$price = $offer->price . ' US$';

// ХОРОШО: определять валюту по стране страницы + geo-IP пользователя
function resolveCurrency(DirectionPage $page, Request $request): string
{
    // Приоритет 1: предпочтение пользователя из сессии
    if ($request->session()->has('currency')) {
        return $request->session()->get('currency');
    }
    // Приоритет 2: geo-IP страны пользователя
    $geoCountry = $this->geoIp->getCountry($request->ip());
    if ($geoCountry && isset(self::COUNTRY_CURRENCY[$geoCountry])) {
        return self::COUNTRY_CURRENCY[$geoCountry];
    }
    // Приоритет 3: валюта страны страницы
    return self::COUNTRY_CURRENCY[$page->countryCode] ?? 'EUR';
}
```

---

## Раздел 3 — Правила для верстальщика (Frontend + HTML)

> Правила, реализуемые в HTML-шаблонах, CSS, разметке страницы.
> Ни одна из этих задач не требует JS или API.

### F1 — Иерархия заголовков: один H1, строгая вложенность

```html
<!-- ПЛОХО -->
<h1>Milan Malpensa Airport Transfers</h1>
<h3>Offers</h3>        <!-- пропущен H2 -->
<h2>Reviews</h2>
<h4>Book now</h4>      <!-- пропущен H3 -->

<!-- ХОРОШО -->
<h1>Milan Malpensa Airport Transfers</h1>
  <h2>Transfer Offers from Malpensa</h2>
    <h3>Economy sedan</h3>
    <h3>Minivan</h3>
  <h2>Customer Reviews</h2>
  <h2>How to Get from Malpensa to Milan</h2>
    <h3>By private transfer</h3>
    <h3>By taxi</h3>
    <h3>By public transport</h3>
  <h2>FAQ</h2>
```

Шаблон H2-структуры для страницы аэропорта:
1. Offers / Transfer options
2. Customer Reviews (с видимым AggregateRating)
3. How to get from X to Y (per mode)
4. Available vehicles
5. Why book with us (trust block)
6. FAQ (вопросы в виде H2 или вложенных H3)

### F2 — AggregateRating должна быть видна рядом с H1

```html
<!-- Сразу под H1 или в hero-секции -->
<h1>Milan Malpensa Airport Transfers</h1>
<div class="rating-anchor">
  <span class="stars" aria-label="4.8 out of 5">★★★★★</span>
  <span class="rating-value">4.8</span>
  <span class="review-count">(1,247 transfers)</span>
  <span class="price-from">— from €38</span>
</div>
```

Эта строка является видимым подтверждением для JSON-LD AggregateRating.
Без неё schema может быть расценена как «скрытый контент».

### F3 — "From X" price anchor: выше сгиба страницы

```html
<!-- В hero или рядом с CTA кнопкой, не в середине страницы -->
<section class="hero">
  <h1>Transfer from Bangkok Airport to Pattaya</h1>
  <p class="price-from">From <strong>฿1,050 / ~$30</strong> · Private transfer · 1h 30m</p>
  <a href="#booking" class="btn-primary">Book now</a>
</section>
```

Цена должна быть выше линии прокрутки (above the fold) — это основной
коммерческий сигнал и конверсионный якорь одновременно.

### F4 — Breadcrumb: минимум 3 уровня, JSON-LD синхронизирован с видимым

```html
<!-- Видимый breadcrumb -->
<nav aria-label="Breadcrumb">
  <ol itemscope itemtype="https://schema.org/BreadcrumbList">
    <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
      <a itemprop="item" href="/en/directions/italy/">
        <span itemprop="name">Italy</span>
      </a>
      <meta itemprop="position" content="1" />
    </li>
    <li itemprop="itemListElement" ...>
      <a itemprop="item" href="/en/directions/italy/milan/">
        <span itemprop="name">Milan</span>
      </a>
      <meta itemprop="position" content="2" />
    </li>
    <li itemprop="itemListElement" ...>
      <span itemprop="name">Milan Malpensa Airport</span>
      <meta itemprop="position" content="3" />
    </li>
  </ol>
</nav>
```

Microdata прямо в HTML — альтернатива JSON-LD для breadcrumb,
не требующая JS. Оба варианта работают.

### F5 — Open Graph и Twitter Cards: обязательный минимум

```html
<head>
  <!-- Open Graph (Facebook, LinkedIn, Telegram, WhatsApp preview) -->
  <meta property="og:type" content="website" />
  <meta property="og:title" content="Milan Malpensa Airport Transfers | GetTransfer" />
  <meta property="og:description" content="Book private transfer from Malpensa from €38. Meet & greet, flight tracking, 24/7 support." />
  <meta property="og:image" content="https://example.com/img/malpensa-transfer-1200x630.jpg" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:url" content="https://example.com/en/directions/italy/milan/airports/malpensa" />
  <meta property="og:locale" content="en_US" />

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="Milan Malpensa Airport Transfers | GetTransfer" />
  <meta name="twitter:description" content="Book private transfer from Malpensa from €38." />
  <meta name="twitter:image" content="https://example.com/img/malpensa-transfer-1200x630.jpg" />

  <!-- Canonical -->
  <link rel="canonical" href="https://example.com/en/directions/italy/milan/airports/malpensa" />

  <!-- Hreflang (для мультиязычных сайтов) -->
  <link rel="alternate" hreflang="en" href="https://example.com/en/directions/italy/milan/airports/malpensa" />
  <link rel="alternate" hreflang="de" href="https://example.com/de/directions/italy/milan/airports/malpensa" />
  <link rel="alternate" hreflang="x-default" href="https://example.com/en/directions/italy/milan/airports/malpensa" />
</head>
```

### F6 — Внутренние ссылки: блок «связанных страниц» в каждом шаблоне

Целевая плотность: 120–200 внутренних ссылок с каждой страницы каталога.
Достигается за счёт блоков, а не ручного проставления ссылок:

```html
<!-- Блок 1: Похожие маршруты из этого аэропорта (top-10 по трафику) -->
<section class="related-routes">
  <h2>Popular routes from Milan Malpensa Airport</h2>
  <ul>
    <li><a href="/en/directions/italy/milan/populars/malpensa-to-city-centre">Malpensa → Milan City Centre</a></li>
    <li><a href="/en/directions/italy/milan/populars/malpensa-to-lake-como">Malpensa → Lake Como</a></li>
    <!-- … -->
  </ul>
</section>

<!-- Блок 2: Другие аэропорты этого города / страны -->
<section class="nearby-airports">
  <h2>Other airports in Milan</h2>
  <ul>
    <li><a href="/en/directions/italy/milan/airports/linate">Milan Linate Airport (LIN)</a></li>
    <li><a href="/en/directions/italy/bergamo/airports/orio-al-serio">Bergamo Orio al Serio (BGY)</a></li>
  </ul>
</section>

<!-- Блок 3: Типы транспорта (service pages) -->
<section class="vehicle-types">
  <h2>Transfer vehicle types</h2>
  <ul>
    <li><a href="/en/services/airport_transfer">Airport transfers</a></li>
    <li><a href="/en/services/vip_transfer">VIP & Business transfers</a></li>
    <li><a href="/en/services/bus_rental">Bus & group transfers</a></li>
  </ul>
</section>

<!-- Блок 4: Хаб города и страны -->
<p>
  More <a href="/en/directions/italy/milan/">Milan transfers</a> ·
  All <a href="/en/directions/italy/">Italy transfers</a>
</p>
```

### F7 — Изображения: alt + width/height + lazy loading

```html
<!-- ПЛОХО -->
<img src="/img/malpensa.jpg">

<!-- ХОРОШО -->
<img
  src="/img/malpensa-airport-transfer-1200x800.jpg"
  alt="Private transfer from Milan Malpensa Airport to city centre"
  width="1200"
  height="800"
  loading="lazy"
  decoding="async"
>
```

`width` и `height` обязательны — предотвращают CLS (Cumulative Layout Shift),
один из Core Web Vitals. Hero-изображение — `loading="eager"`, все остальные — `loading="lazy"`.

### F8 — Блок отзывов: разметка Review для каждого

```html
<div itemscope itemtype="https://schema.org/Review">
  <div itemprop="reviewRating" itemscope itemtype="https://schema.org/Rating">
    <span itemprop="ratingValue">5</span>/5
  </div>
  <p itemprop="reviewBody">"Driver was on time, car was clean, excellent service"</p>
  <span itemprop="author" itemscope itemtype="https://schema.org/Person">
    <span itemprop="name">John D.</span>
  </span>
  <time itemprop="datePublished" datetime="2025-11-12">November 2025</time>
</div>
```

### F9 — Структура блога: шаблон "narrative H2s → PAA H2s → CTA FAQ"

```html
<article>
  <h1>How to Get from Bangkok Airport to Pattaya</h1>
  <p class="intro">Three options, clear prices, no tourist traps. Here's what to take.</p>

  <!-- Narrative section -->
  <h2>By private transfer (recommended)</h2>
  <p>...1.5 hours, ~$30 for 2 people, driver meets you at arrivals...</p>

  <h2>By bus</h2>
  <p>...2 hours, ฿143 (~$4), departure from level 1...</p>

  <h2>By taxi</h2>
  <p>...1.5 hours, ฿1,050 (~$30), meter + expressway toll...</p>

  <!-- PAA-style questions -->
  <h2>How long does it take to get from Bangkok Airport to Pattaya?</h2>
  <p>About 1.5 hours by private transfer or taxi, 2+ hours by bus.</p>

  <h2>What is the cheapest way from Bangkok Airport to Pattaya?</h2>
  <p>Bus at ฿143 ($4) is cheapest. Private transfer splits well for 3+ people.</p>

  <h2>Can I book a transfer from Bangkok Airport to Pattaya in advance?</h2>
  <p>Yes. Pre-booked private transfers avoid queues and surge pricing.
     <a href="/en/directions/thailand/bangkok/populars/airport-to-pattaya">Book on GetTransfer →</a>
  </p>
  <!-- ↑ последний вопрос = CTA, органично встроенный в FAQ -->
</article>
```

---

## Раздел 4 — Контентные правила (редактор / контент-стратег)

> Применимо к статьям блога, описаниям страниц каталога, FAQ-текстам.

### C1 — Длина статьи: по глубине темы, не по числу слов

| Тип контента | Оптимальный объём | Почему |
|---|---|---|
| Utility/нишевые (валюта, фраза, чаевые) | 500–700 слов | Один запрос, один ответ |
| Listicle (топ-10, рестораны, достопримечательности) | 700–900 слов | N пунктов × ~100 слов |
| Transport explainer (как добраться, сравнение) | 1 000–1 500 слов | Несколько режимов × ~300 слов |
| Segment guide (с детьми, для бизнеса) | 1 000–1 200 слов | Нюансы целевой аудитории |
| Airport/city hub (общая) | 1 500–2 000 слов | Агрегирует несколько интентов |

**Не гонитесь за 2 000+ слов ради слов** — Google ранжирует по релевантности,
не по длине.

### C2 — Цены в контенте: только в транспортных материалах

Включайте цены (с диапазоном или примером):
- "How to get from X to Y" статьи — обязательно
- Airport transfer pages — обязательно (прайс-якорь above the fold)
- Comparison pages (такси vs трансфер) — обязательно

Не включайте фиксированные цены:
- Ресторанные гиды — меняются слишком быстро
- Туристические listicle — content debt
- Utility pages (валюта, язык) — цены не нужны

### C3 — Canonical-консолидация: один URL на намерение

Near-duplicate intent → canonical redirect, не отдельные страницы:

```
"things to do at CDG" → canonical → "layover in Paris"
"suvarnabhumi to pattaya" → canonical → "bangkok airport to pattaya"
"cheap transfer malpensa" → canonical → "malpensa airport transfer"
```

Правило: если два URL отвечают на *одно* намерение пользователя — один
из них должен быть canonical к другому. Иначе они каннибализируют друг друга.

### C4 — E-E-A-T: именованные авторы с биографией

Для блогового контента:
- Каждая статья имеет именованного автора (не «Editorial team»)
- У автора есть страница `/author/{slug}/` с биографией, фото, credentials
- Person schema на странице автора и в каждой статье автора
- Минимум 3–5 постоянных авторов на сайт, не 50 разных без истории

Для страниц каталога:
- «Reviewed by» или «Updated by» + дата обновления
- Organization schema с `sameAs` (LinkedIn, Crunchbase, Wikipedia)

### C5 — Свежесть: dateModified ≠ datePublished

Обновляйте `dateModified` и видимую «последнее обновление» дату когда:
- Меняются цены на странице
- Меняется рейтинг (AggregateRating)
- Реально обновляете контент

Не обновляйте дату автоматически каждый день без изменений — Google умеет
обнаруживать фиктивные обновления.

---

## Раздел 5 — Чеклист запуска (Release checklist)

> Перед публикацией любой страницы или группы страниц.

### Технический минимум (блокирующий)

- [ ] `<title>` содержит специфический идентификатор сущности (не родовое слово)
- [ ] `<h1>` один на страницу, семантически совпадает с title
- [ ] `<meta name="description">` заполнен, 150–160 символов, содержит ключевое слово
- [ ] `<link rel="canonical">` указывает на себя (или на правильный canonical)
- [ ] Hreflang настроен для всех языков (если мультиязычный сайт)
- [ ] JSON-LD `@graph` содержит минимум: WebSite + WebPage + Organization + BreadcrumbList
- [ ] JSON-LD AggregateRating совпадает с видимым рейтингом на странице
- [ ] Open Graph теги заполнены (og:title, og:description, og:image 1200×630)
- [ ] Canonical URL добавлен в sitemap

### Контентный минимум (блокирующий)

- [ ] H1 содержит специфический идентификатор (название аэропорта, маршрута, города)
- [ ] AggregateRating видна рядом с H1 (если есть отзывы)
- [ ] "From X" price anchor выше сгиба (если коммерческая страница)
- [ ] Минимум 5 FAQ-вопросов в виде H2/H3 (или FAQPage schema)
- [ ] Отзывы гео-скоупированы (релевантны этой странице)
- [ ] Breadcrumb отображается и совпадает с JSON-LD

### Рекомендуемый (не блокирующий)

- [ ] Product + Offer schema для коммерческих предложений
- [ ] ImageObject schema для hero-изображения
- [ ] datePublished и dateModified в WebPage schema
- [ ] Блоки внутренних ссылок (соседние страницы, хаб, сервисы)
- [ ] Валюта соответствует стране страницы (не US$ по умолчанию)
- [ ] Offers block не пустой (проверена fallback-цепочка)

---

## Раздел 6 — Метрики успеха

### Leading indicators (контролируем сами)

| Метрика | Старт | Цель 3 мес | Цель 12 мес |
|---|---|---|---|
| Avg JSON-LD @types per page | 2 | 8 | 10 |
| % страниц с FAQ-блоком | 0% | 40% | 90% |
| Median internal links per page | ~40 | 80 | 150 |
| % страниц с visible AggregateRating | 0% | 60% | 95% |
| % страниц с "from X" price anchor | 0% | 50% | 90% |
| Кол-во опубликованных editorial статей | 0 | 15 | 80 |

### Lagging indicators (результат)

| Метрика | Цель 3 мес | Цель 12 мес |
|---|---|---|
| GSC avg position, top-100 pages | -3 позиции | -6 позиций |
| GSC clicks/month | +30% | +120% |
| ⭐ stars appearing in SERP | первые появляются | >50% eligible pages |
| AI citation (ChatGPT/Perplexity) | первые цитирования | ≥30% top queries |

### Quick win validation (первые 4 недели)

После исправления title/H1 на проблемных страницах:
1. Проверить в GSC: Search Appearance → выбрать конкретные URL
2. Дождаться повторной индексации (Submit to indexing + 7–14 дней)
3. Сравнить avg position до/после по точным запросам с названием аэропорта

---

## Итоговые принципы (10 правил в одной строке)

1. **Данные в DOM → данные в JSON-LD**: если пользователь видит цену или рейтинг, поисковик тоже должен видеть.
2. **Специфичность H1/title**: «Istanbul Airport» и «Istanbul Sabiha Gökçen Airport» — разные сущности для Google.
3. **AggregateRating везде**: ⭐ в выдаче поднимает CTR на 20–35% при одинаковой позиции.
4. **FAQ-блок = AI-citation entry point**: 5–8 вопросов H2 в конце любой страницы — бесплатный вход в AI-ответы.
5. **Гео-скоуп контента**: нерелевантные отзывы хуже, чем пустой блок.
6. **Dormant pages first**: опубликуйте уже смоделированные URL прежде чем писать новый контент.
7. **Внутренние ссылки = авторитет**: 120+ ссылок с каждой страницы — это структура, а не спам.
8. **Длина по теме, не по целевому числу слов**: 1 000 правильных слов > 3 000 нерелевантных.
9. **Шаблонные баги убивают быстрее конкурентов**: аудит шаблонов > написание новых страниц.
10. **AI search и Google search — разные движки**: FAQPage и structured @graph нужны для обоих, но по-разному.

---

*Документ основан на анализе: malpensa-gap-analysis.md, sabiha-gap-analysis.md,
pattaya-gap-analysis.md, wp-st-gt-seo-comparison.md, wp-blog-analysis.md,
rules-for-direction-pages.md, reason-why-and-where-to-move-seo-strategy.md.*

*Версия: 1.0 · Дата: 2026-05-14*
