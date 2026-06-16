# SEO Rules — WEARBASE

**Источник:** SEO Guide 2026
**Обновлено:** 2026-05-14

> ⚠️ **АРХИВ (2026-06-16).** WEARBASE-специфика (validation-константы, TIER-списки запрещённых
> слов, команды, pass-gates) перенесена в канон [`../seo_rules.md`](../seo_rules.md), часть 0
> «WEARBASE — рабочие константы». Файл сохранён для истории.

---

## Meta Tags

| Element | Limit | Notes |
|---------|-------|-------|
| **Title Tag** | 50-65 символов | Google обрезает >60 |
| **Meta Description** | 140-160 символов | Оптимально 150-155, заканчивать на целом слове |
| **Alt Text** | ≤125 символов | Descriptive, не keyword-stuffed |
| **URL** | ≤60 символов | lowercase, hyphens, с ключевым словом |

### Title Examples
```
✅ "[Бренд] — бренд одежды | WEARBASE"
✅ "Ателье в Москве 2026: цены и отзывы | Brand"
❌ "Купить одежду бренда X в Москве недорого онлайн с доставкой"
```

### Description Examples
```
✅ "Каталог одежды бренда X. Streetwear и casual. Отзывы покупателей."
❌ "Компания X представляет собой инновационный бренд одежды который..."
```

---

## Content Requirements

| Metric | Requirement | Notes |
|--------|-------------|-------|
| **Word Count** | ≥1200 слов | informational; <300 = thin_content flag |
| **FRES (Readability)** | 60-70 target | Flesch Reading Ease |
| **Paragraph Length** | 2-3 предложения | для GEO extractability |
| **Internal Links** | 3-5 per article | контекстуальные |
| **Citations** | 5-6 credible sources | named authors |

---

## Schema.org Requirements

1. **Format:** JSON-LD only
2. **URLs:** Все absolute (`https://...`)
3. **@id:** Unique per entity
4. **Match:** Markup = visible content (иначе = Manual Action)
5. **No Hidden:** Don't markup hidden elements
6. **No Fake Ratings:** только реальные отзывы
7. **Breadcrumb:** starts at 1, not 0
8. **Validation:** https://search.google.com/test/rich-results

### Required Schemas (global)
- `Organization` — на каждой странице
- `BreadcrumbList` — для навигации
- `WebSite` — для searchbox

---

## AI Detection Rules

### Запрещённые слова (TIER-1) — instant fail
```
delve, tapestry, landscape, multifaceted, pivotal, realm,
commendable, intricate, noteworthy, meticulous, testament,
underscores, nuanced, showcasing, embark, endeavor,
encompass, spearhead, groundbreaking
```

### Запрещённые слова (TIER-2)
```
furthermore, moreover, additionally, crucial, robust,
innovative, leverage, streamline, foster, bolster,
garner, vibrant, enduring, elevate, seamless,
comprehensive, transformative, unprecedented,
cutting-edge, dynamic, scalable, tailored
```

### AI Phrase Density
| Threshold | Score |
|-----------|-------|
| >8/1000 words | 12 pts |
| >5/1000 words | 8 pts |
| >2/1000 words | 4 pts |

### Anti-AI Phrases (wearbase)
```
инновационный, уникальный, передовой, лидирующий,
новаторский, выделяется, отличается, несравненный,
беспрецедентный
```

### Pass Gates
```
SB (SpamBrain) ≥ 7/10
RV (Reader Value) ≥ 7/10
HL (Human-Likeness) ≥ 8/10
thin_content < 4
```

### Human Signals (negative scoring)
- First-person pronouns >2% = -3pts
- Data specificity (prices, dates) = -1pt
- E-E-A-T markers 2+ = -2pts

---

## Technical SEO

### Core Web Vitals (mobile)
| Metric | Target |
|--------|--------|
| LCP | ≤2.5s |
| INP | ≤200ms |
| CLS | ≤0.1 |
| TTFB | ≤600ms |

### Crawlability
- robots.txt: no `Disallow: /` на production
- Sitemap: ≤50000 URL
- Canonical: same URL as served
- Orphan rate: <3%

### Structure
- One H1 per page
- Semantic HTML5: `<article>`, `<nav>`, `<main>`
- Breadcrumbs (HTML + schema)

---

## LLM Content Pipeline

### Production Limits (anti-ban)
| Metric | Safe | Warning | Ban |
|--------|------|---------|-----|
| New articles/day | ≤5 | 5-15 | >20 |
| New articles/week | ≤30 | 30-60 | >100 |
| Translations/day | ≤200 | 200-500 | >1000 |
| GSC Indexing API | ≤200 | - | >200 |

### Generation Settings
- **Short-form** (≤500 words): temperature=0.7
- **Long-form** (1200+ words): multi-step, temp 0.7 draft → 0.5 polish
- **Retry:** max 3 with temperature decay (0.7→0.6→0.5)
- **Rejection:** after 3 fails → reject

### Anti-Scaled-Content
1. Content velocity pacing (max 5/day)
2. Diverse content types
3. Unique per-page value
4. Author rotation (3-5 real authors)
5. Multi-source RAG

---

## E-E-A-T Requirements

- Author Bio с credentials
- sameAs links: соцсети бренда
- Experience markers: first-person, numbers, dates
- Trust signals: real address, phone

---

## Validation Constants (WEARBASE)

```php
// ContentValidator.php
MIN_DESCRIPTION_WORDS = 170      // минимум для description
MAX_META_TITLE = 60              // символов
MAX_META_DESCRIPTION = 155       // символов
MAX_META_KEYWORDS = 200          // символов

// AI Phrases (21)
[
    'инновационный', 'инновационная', 'инновационное', 'инновационные',
    'уникальный', 'уникальная', 'уникальное', 'уникальные',
    'передовой', 'передовые',
    'лидирующий', 'лидирующая', 'лидирующее',
    'новаторский', 'новаторская',
    'выделяется', 'отличается',
    'несравненный', 'беспрецедентный',
]
```

---

## Commands

```bash
# Генерация meta для брендов с описанием
php bin/console app:brand:generate-content --meta-only 500

# Генерация description для брендов без
php bin/console app:brand:generate-content 100

# Конкретный бренд
php bin/console app:brand:generate-content --id=148

# Проверка контента
php bin/console app:brand:check-content --limit=100

# Экспорт проблем
php bin/console app:brand:check-content --limit=500 --export=/tmp/issues.json
```