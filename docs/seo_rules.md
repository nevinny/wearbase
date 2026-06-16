# SEO Rules — канон WEARBASE (v3.0.0)

**Версия:** 3.0.0 | **Обновлено:** 2026-06-16
**Консолидирует** три ранее раздельных файла (оригиналы — в [`archive/`](archive/)):
- `seo_rules_SEO_GUIDE.md` → **Часть 0** (WEARBASE — рабочие константы)
- `seo_rules_2.3.0.md` → **Часть 1** (Нормативные правила MUST/SHOULD/NICE) + Приложение
- прежний `seo_rules.md` → **Часть 2** (Проектные принципы и паттерны реализации, by-design)

**Основано на:** SEO-PEDIA-2026, SpamBrain/AVI PDF, Manual Actions, production-кейсах Key Group,
трёхстороннем конкурентном анализе (GetTransfer / WelcomePickups / SunTransfers).

---

## Легенда (для Части 1)

### Уровни строгости
| Метка | Значение | Применение |
|-------|----------|------------|
| **[MUST]** | Блокирующее требование: нарушение = запрет деплоя / публикации | Релизный гейт |
| **[SHOULD]** | Сильная рекомендация: влияет на рост и стабильность, не блокирует релиз | Качественный гейт |
| **[NICE]** | Желательно при наличии ресурсов; не аудируется регулярно | Backlog |

### Слои ответственности
| Метка | Кто |
|-------|-----|
| **Infra** | DevOps: сервер, CDN, DNS, nginx |
| **Dev** | Разработка: код, шаблоны, роутинг |
| **SEO** | SEO-владелец: стратегия, аудиты, гейты |
| **Content** | Авторы, редакторы, контент-менеджеры |
| **Data** | БД, CMS, фиды, переводы |

Формат правила: **[MUST · Слой]** *(краткая проверка · ОК, если: … · Симптом поломки: …)*

---

# Часть 0 — WEARBASE: рабочие константы

> Конкретные пороги и значения, зашитые в код проекта. Это quick-reference; нормативные
> правила и их обоснования — в Части 1, паттерны реализации — в Части 2.

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

## Validation Constants (ContentValidator.php)

```php
MIN_DESCRIPTION_WORDS = 170      // минимум для description
MAX_META_TITLE = 60              // символов
MAX_META_DESCRIPTION = 155       // символов
MAX_META_KEYWORDS = 200          // символов
```

## AI Detection — запрещённые слова

**TIER-1 (instant fail):**
```
delve, tapestry, landscape, multifaceted, pivotal, realm, commendable, intricate,
intricacies, noteworthy, meticulous, meticulously, testament, underpinning, underscores,
nuanced, showcasing, embark, endeavor, encompass, spearhead, groundbreaking
```

**TIER-2:**
```
furthermore, moreover, additionally, crucial, robust, innovative, leverage, streamline,
foster, bolster, garner, vibrant, enduring, elevate, seamless, comprehensive,
transformative, unprecedented, cutting-edge, dynamic, scalable, tailored
```

**Anti-AI Phrases (RU, wearbase) — 21 форма в `ContentValidator.php`:**
```
инновационный/-ая/-ое/-ые, уникальный/-ая/-ое/-ые, передовой/-ые,
лидирующий/-ая/-ее, новаторский/-ая, выделяется, отличается,
несравненный, беспрецедентный
```

### AI Phrase Density (баллы)
| Threshold | Score |
|-----------|-------|
| >8/1000 words | 12 pts |
| >5/1000 words | 8 pts |
| >2/1000 words | 4 pts |

### Pass Gates
```
SB (SpamBrain)      ≥ 7/10
RV (Reader Value)   ≥ 7/10
HL (Human-Likeness) ≥ 8/10
thin_content        < 4
```

### Human Signals (negative scoring — снижают AI-score)
- First-person pronouns >2% = −3 pts
- Data specificity (prices, dates) = −1 pt
- E-E-A-T markers 2+ = −2 pts

## Content Requirements

| Metric | Requirement | Notes |
|--------|-------------|-------|
| **Word Count** | ≥1200 слов | informational; <300 = thin_content flag |
| **FRES (Readability)** | 60-70 target | Flesch Reading Ease |
| **Paragraph Length** | 2-3 предложения | для GEO extractability |
| **Internal Links** | 3-5 per article | контекстуальные |
| **Citations** | 5-6 credible sources | named authors |

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
- **Retry:** max 3 with temperature decay (0.7→0.6→0.5); after 3 fails → reject

### Anti-Scaled-Content
1. Content velocity pacing (max 5/day)
2. Diverse content types
3. Unique per-page value
4. Author rotation (3-5 real authors)
5. Multi-source RAG

## Schema.org Requirements
1. **Format:** JSON-LD only
2. **URLs:** Все absolute (`https://...`)
3. **@id:** Unique per entity
4. **Match:** Markup = visible content (иначе = Manual Action)
5. **No Hidden:** Don't markup hidden elements
6. **No Fake Ratings:** только реальные отзывы
7. **Breadcrumb:** starts at 1, not 0
8. **Validation:** https://search.google.com/test/rich-results

**Required Schemas (global):** `Organization` (на каждой странице), `BreadcrumbList`, `WebSite` (для searchbox).

## E-E-A-T Requirements
- Author Bio с credentials
- sameAs links: соцсети бренда
- Experience markers: first-person, numbers, dates
- Trust signals: real address, phone

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

---

# Часть 1 — Нормативные правила (MUST/SHOULD/NICE)

## 1. Техническая основа

### 1.1 Trailing-slash — выбери один формат и зафиксируй намертво
**[MUST · Infra/Dev]** Проверка: `scripts/trailing_slash_audit.py <domain>` → 0 mismatches. ОК, если: canonical = sitemap = served URL = internal links — один формат. Симптом: «Page with redirect» или «Crawled, currently not indexed» в GSC без видимой причины.

Canonical, sitemap, internal links, CDN-конфиг и server redirects должны возвращать **один и тот же формат URL**. Несоответствие хотя бы в одном слое — Google перестаёт индексировать без уведомлений (реальный инцидент: blog.getrentacar.com — 0% индексации).

### 1.2 Canonical всегда указывает на себя, на живой 200-OK URL
**[MUST · Dev]** Проверка: `curl -sI <canonical-url>` → 200 OK, нет noindex в `X-Robots-Tag`. Симптом: страница выпадает из индекса без Manual Action.

Canonical — **сильная подсказка, не директива**. При конфликте сигналов (canonical ↔ sitemap ↔ hreflang ↔ внутренние ссылки ↔ редиректы) Google вправе выбрать другой URL. Все сигналы должны быть консистентны.

### 1.3 SSR обязателен для всего SEO-критичного контента
**[MUST · Dev]** Проверка: `curl -s <url> | grep -c "<h1>"` > 0; title и canonical видны без JS. Симптом: URL Inspection показывает пустую страницу.

**SSR-гейт (обязательно в HTML при первой загрузке):** `<title>`, `<meta description>`, `<meta robots>`, один `<h1>`, основной текст, breadcrumbs HTML+JSON-LD, canonical, hreflang, JSON-LD (Article/Organization; FAQPage если есть FAQ-секция), внутренние ссылки. Через JS можно: комментарии, калькуляторы, виджеты, ЛК.

### 1.4 HTTPS + HSTS — без исключений; HTTP → HTTPS только 301
**[MUST · Infra]** `curl -I http://example.com` → 301 (не 302); HSTS присутствует. 302 не передаёт PageRank.

### 1.5 Google Indexing API: ≤ 200 URL/день, батч по 50, задержка 1500ms
**[SHOULD · Dev]** Превышение тихо сжигает дневной лимит. Приоритет: новые/изменённые первыми.

### 1.6 Sitemap: только канонические 200-OK URL, ≤ 5000 URL/день при submission
**[MUST · Dev/Data]** Только 200-OK без редиректов/404/noindex; `lastmod` реальный из БД (фиктивные одинаковые даты игнорируются); разделение по типам + `sitemap_index.xml` если >50K URL; ≤50K URL / 50MB на файл. Sitemap — подсказка, не гарантия.

### 1.7 robots.txt: блокируй параметры, не страницы по смыслу
**[MUST · Dev]** `Disallow: /*?utm_`, `/*?ref=`, `/*?currency=`. Не блокировать `Googlebot`/`Google-Extended` — выпадешь из AI Overviews. **robots.txt Disallow ограничивает сканирование, но НЕ удаляет из индекса** — для удаления нужен `noindex` (см. §9).

### 1.8 Core Web Vitals — LCP ≤ 2.5s, INP ≤ 200ms, CLS ≤ 0.1
**[SHOULD · Dev/Infra]** Приоритет — field data (CrUX, mobile-first), lab (Lighthouse) только для диагностики. INP заменил FID с марта 2024. Anti-паттерны: preload всего; тяжёлые шрифты без `font-display: swap`; render-blocking `<script>`; изображения без explicit width/height.

### 1.9 TTFB ≤ 600ms — nginx cache обязателен, proxy_ignore_headers для Next.js
**[SHOULD · Infra]** Без `proxy_ignore_headers Cache-Control Set-Cookie Expires` nginx сбрасывает кэш при каждом Set-Cookie. Реальный результат: TTFB 800ms → 50ms.

```nginx
proxy_cache_path /var/cache/nginx levels=1:2 keys_zone=blog:100m max_size=10g inactive=60m;
server { location / {
    proxy_cache blog;
    proxy_cache_valid 200 302 10m;
    proxy_cache_key "$scheme$host$request_uri";
    proxy_ignore_headers Cache-Control Set-Cookie Expires;
    proxy_hide_header Set-Cookie;
    proxy_pass http://localhost:3000;
}}
```

### 1.10 Все 3rd-party скрипты — async/defer, не blocking
**[SHOULD · Dev]** Аналитика → `async`; маркетинг/AB → `defer` (+ Lighthouse before/after до мёржа); хитмапы → `load`+setTimeout 3000ms. Каждый новый скрипт требует CWV-сравнение.

### 1.11 Изображения: WebP, explicit width/height, lazy ниже fold, preload hero
**[SHOULD · Dev]** `loading="lazy"` без width/height = CLS. Hero — `<link rel="preload" as="image">`. Srcset экономит 60-70% mobile-трафика.

### 1.12 Orphan rate внутренних страниц < 3%
**[SHOULD · Dev/SEO]** Screaming Frog → «No incoming internal links» ≤ 3% SEO-ценных URL. Симптом: контент не набирает позиции — PageRank не доходит.

### 1.13 Редиректы: максимум 1 хоп, петля = P0, политика 404 vs 410
**[MUST · Dev]** Redirect chain запрещены (A→B, не A→B→C); loop = P0; **301** постоянный (передаёт PageRank, существует вечно); **302** временный; **410 Gone** удалено навсегда (быстрее очищается чем 404); **301 на главную** при удалении = soft-404.

### 1.14 SSR release gate: автоматический тест перед деплоем шаблонов
**[MUST · Dev]** Curl-тест на staging — grep на все элементы из 1.3 перед мёржем.

## 2. Архитектура сайта

### 2.1 Flat architecture: все ценные страницы ≤ 3 клика от главной
**[SHOULD · Dev/SEO]** Управляемый критерий: доля SEO-ценных страниц (>100 impressions/мес) глубже 3 кликов < 2%. DiscoverCars — 341K URL на 2 клика.

### 2.2 Один URL = одна страница = один search intent
**[MUST · SEO]** Множество URL под похожие запросы без уникального контента = каннибализация.

### 2.3 URL: lowercase, hyphens, ≤ 60 символов, без keyword stuffing
**[MUST · Dev]** Только lowercase+hyphens (underscores не разделители); нет session_id/tracking/numeric ID; нелатиница → транслитерация (локальный slug только если ЦА исключительно этот язык); единый trailing-slash из 1.1.

### 2.4 URL никогда не меняй после публикации — только через 301 на замену
**[MUST · Dev/SEO]** 301 только на семантически релевантную замену. 301 на главную = soft-404.

### 2.5 Canonical + hreflang: самоссылающийся canonical на каждой языковой версии
**[MUST · Dev]** Ошибка #1 в multilingual: canonical на EN-master со всех языков → переводы не индексируются. Все 4 сигнала (canonical/hreflang/sitemap/internal links) обязаны совпадать.

### 2.6 Hreflang только bidirectional: если A→B, то B→A обязательно
**[MUST · Dev/Data]** `scripts/hreflang_audit.py` → 0 missing back-references. Hreflang только для `isReal=true` переводов.

### 2.7 x-default обязателен в hreflang-матрице
**[MUST · Dev]** Обычно = EN-версия.

### 2.8 Paginated pages: self-canonical на каждой, не canonical на page 1
**[MUST · Dev]** Иначе страницы пагинации выпадают из индекса.

### 2.9 Фильтры/facets: noindex+follow или canonical на main category
**[MUST · Dev]** Индексируемыми оставлять только коммерчески ценные комбо с реальным спросом. `noindex, follow` (не `disallow`).

### 2.10 Breadcrumbs HTML + BreadcrumbList schema, position starts at 1
**[SHOULD · Dev]** Position 0 = ошибка схемы → Manual Action риск.

### 2.11 Каталог типов страниц: index/canonical/sitemap статус
**[MUST · SEO/Dev]** Документировать матрицу (хранить в CLAUDE.md / wiki):

| Тип страницы | Index | Canonical | Sitemap | Обязательные ссылки |
|---|---|---|---|---|
| Главная | ✅ | self | ✅ | все hub-разделы |
| Категория / hub | ✅ | self | ✅ | подкатегории, топ-карточки |
| Листинг page 1 | ✅ | self | ✅ | карточки, breadcrumbs |
| Листинг page 2+ | ✅ | self (не на page 1) | ❌ | breadcrumbs, nav |
| Карточка / статья | ✅ | self | ✅ | related, breadcrumbs, hub |
| Фильтр без спроса | ❌ noindex | → main category | ❌ | — |
| Фильтр с реальным спросом | ✅ | self | ✅ | hub |
| Внутренний поиск | ❌ noindex | → category | ❌ | — |
| Чекаут / аккаунт | ❌ noindex | self | ❌ | — |
| Теги / архив дат | case by case | — | — | — |

### 2.12 Система внутренней перелинковки: обязательные модули на шаблонах
**[SHOULD · Dev/SEO]** Модули: Breadcrumbs (везде кроме главной), «Похожие/Related» (3-5 ссылок), родительская категория, «следующий/предыдущий», hub-ссылки. Запрет: публиковать страницы-сироты.

### 2.13 Политика каннибализации: выбор primary и действие по дублю
**[MUST · SEO]** Выбрать primary (трафик + link profile); второй: 301/canonical/merge/noindex. Лучше меньше сильных страниц, чем много слабых.

### 2.14 URL migration: обязательная redirect map перед изменением структуры
**[MUST · Dev/SEO]** Детали — §11.

## 3. Контент и семантика

### 3.1 Минимум 1200 слов для информационных статей; абсолютный минимум 300
**[SHOULD · Content]** <300 слов = автоматический thin_content флаг. Лонгриды: 1800+.

### 3.2 Один H1 на страницу; H1 ≠ просто keyword, это литературный заголовок
**[MUST · Content/Dev]** H2 лучше формулировать как вопросы пользователя — матчат fan-out AI queries.

### 3.3 Keyword density ≤ 3%; exact-match anchor ratio < 0.60
**[SHOULD · Content]** >3% — SpamBrain флаг. Точные анкоры >60% = Unnatural Links маркер.

### 3.4 Параграфы максимум 2–3 предложения; прямой ответ в начале секции
**[SHOULD · Content]** Для GEO extractability — AI парсит блоки знаний, не стены текста.

### 3.5 5–6 именованных citations на статью с name/title/company
**[SHOULD · Content]** «Studies show» = vague. «Maria Rossi, head of pricing at Sixt Italia: '…'» = E-E-A-T + GEO citation. Без sources → AI Overviews не цитирует.

### 3.6 Transition words ≤ 12/1000 слов; не начинать абзацы с Furthermore/Moreover
**[SHOULD · Content]** SpamBrain и AI-детекторы смотрят буквально.

### 3.7 Нулевая терпимость к TIER-1 AI словам в published контенте
**[MUST · Content]** `scripts/ai_detector.py article.md` → 0 TIER-1 hits. Полный список — Часть 0.

### 3.8 Sentence burstiness: CV(sentence length) ≥ 0.30
**[SHOULD · Content]** Inject 3–4 коротких «punchy» предложений на каждую 1000-словную секцию.

### 3.9 E-E-A-T — косвенный, но весомый фактор
**[SHOULD · Content/SEO]** Без Author Bio + sameAs + experience markers → scaled content abuse риск.

### 3.10 Author Bio обязателен; один автор ≤ 100 статей
**[MUST · Content/Data]** James Miller на 8614 статьях = ban. Нужны 3–5 реальных авторов с ротацией.

### 3.11 Fake AggregateRating = мгновенный CRITICAL Manual Action
**[MUST · Dev/Data]** blog.gettransfer.com — 6 месяцев recovery. Без реальных отзывов — не ставить aggregateRating/Review schema вообще.

### 3.12 Контент обновлять ≤ раз в 3 месяца для конкурентных тем
**[NICE · Content]** AI recency bias — старше 3 мес → меньше citations.

### 3.13 Information Gain — если контент воспроизводим из top-выдачи, SoM = 0
**[SHOULD · Content]** Проприетарные данные, кейсы, личный опыт, уникальная методология = единственное, что даёт share-of-model.

### 3.14 FRES 60–70, ARI ≤ 9 — машинно проверяемые пороги
**[SHOULD · Content]** ARI ≥ 11 = переписывать.

### 3.15 FAQPage schema — для GEO/AI Overviews, не для SERP rich results
**[SHOULD · Dev/Content]** С 7 мая 2026 Google удалил FAQPage rich results (аккордеоны в SERP). Причина ставить schema сохраняется: ChatGPT/Claude/Gemini/Perplexity используют FAQPage JSON-LD для извлечения Q/A (GEO). Не ставить ради SERP-оформления.

### 3.16 Не >3–5 новых статей/день с одного домена
**[MUST · Data/Dev]** SpamBrain отслеживает publishing velocity как primary scaled content abuse signal. Симптом: трафик падает через 2–4 недели без Manual Action.

### 3.17 Near-duplicate контент: консолидация или noindex
**[MUST · SEO/Content]** `scripts/cross_page_similarity.py` → similarity ≥ 0.85 при >5 страниц = флаг → консолидация в primary + 301; программатические страницы: минимум 60% уникального контента.

## 4. UX и поведенческие сигналы

### 4.1 Engagement Rate ≥ 50% (GA4)
**[SHOULD · Content/Dev]** Engaged session = ≥10s OR ≥2 pageviews OR conversion. ER < 40% при высоком трафике = несоответствие интента контенту.

### 4.2 Scroll depth 75% — критический порог для лонгрида
**[NICE · Dev]** GTM триггеры 25/50/75/100%.

### 4.3 Первый viewport — прямой ответ, не вводные абзацы
**[SHOULD · Content]** Первые 2–3 предложения = direct answer.

### 4.4 Table of Contents для лонгридов — снижает pogo-sticking на 15–25%
**[NICE · Dev]**

### 4.5 CTA на 50% скролла, не в конце
**[NICE · Content/Dev]** Пользователи редко доходят до конца.

### 4.6 Mobile-first: tap targets ≥ 48px, text ≥ 16px, no sneaky redirects
**[MUST · Dev]** Sneaky mobile redirects — отдельный Manual Action (часто через взломанные 3rd-party скрипты).

### 4.7 Seasonal pages — permanent URL с ежегодным обновлением
**[SHOULD · Dev/Content]** `/black-friday/` вместо `/black-friday-2026/`.

## 5. Ссылочный профиль и авторитет

### 5.1 Branded anchors 40–60%, naked URL 15–25%, exact-match ≤ 3%
**[SHOULD · SEO]** SpamBrain смотрит на распределение, не на количество.

### 5.2 Плохие ссылки не штрафуют — они аннулируют вес (Penguin умер в 2022)
**[NICE · SEO]** Удаление токсичных ссылок не восстанавливает позиции.

### 5.3 Disavow ТОЛЬКО при Manual Action «Unnatural links» + документированные попытки removal
**[MUST · SEO]** Disavow без Manual Action = потенциальное ухудшение.

### 5.4 Brand mentions без ссылок > traditional link building в 2026
**[SHOULD · SEO]** Mentions на Reddit/YouTube/Wikipedia коррелируют с AI Overviews citations сильнее DR-rich backlinks.

### 5.5 Organization + sameAs → Wikipedia/Wikidata/LinkedIn/Crunchbase
**[SHOULD · Dev/SEO]** Без entity clarity бренд «невидим» для AI-ответов.

### 5.6 Expired domain abuse — мгновенный алгоритмический фильтр
**[MUST · SEO]** Entity Shift = обнуление траста.

### 5.7 PBN детектируется по Network Footprints
**[NICE · SEO]** IP, DNS, nameservers, templates, analytics ID.

## 6. Инсайдерские нюансы

### 6.1 SpamBrain выносит вердикт ДО анонса update
**[MUST · SEO]** Сайт оценён за недели до анонса — реагировать в момент анонса поздно.

### 6.2 «Algorithmic penalty» = нет уведомления в GSC, нет Reconsideration Request
**[MUST · SEO]** Только: исправить паттерны + накопить чистую историю + ждать 3–6 месяцев.

### 6.3 Google Content Warehouse API утечка 2024
**[NICE · SEO]** `spambrainData` (глобальная spam probability), `anchorMismatchDemotion` — реальные атрибуты.

### 6.4 Helpful Content System — sitewide classifier, не page-level
**[MUST · SEO]** Если 30%+ контента thin → под ударом весь домен.

### 6.5 Fake translations → «crawled, currently not indexed» эпидемия
**[MUST · Data/Dev]** DeepL под нагрузкой возвращает original без ошибки. Решение: 3-уровневая валидация + поле `isReal`.

### 6.6 AI Overviews отсутствует на transactional/action queries
**[SHOULD · SEO]** ~7% запросов = AI Overview. Transactional («buy/book/rent») = обычный SERP.

### 6.7 Reddit #1 источник citations в ChatGPT, YouTube #1 в Google AI Mode
**[SHOULD · SEO]** Бренд без Reddit/YouTube = ~0% organic AI citations.

### 6.8 Supabase/hosted DB может быть root-cause производительности
**[NICE · Infra]** key-g.com: миграция на локальный PostgreSQL = 12s → 268ms (45×).

### 6.9 Параметр `isReal: boolean` для каждого перевода — обязательный паттерн
**[MUST · Data]** `SELECT COUNT(*) FROM translations WHERE is_real IS NULL` = 0.

### 6.10 Prisma 7 несовместим с Next.js standalone — использовать Prisma 5
**[MUST · Dev]** (Историческое для key-g стека; на WEARBASE стек Symfony/Doctrine — правило не применяется напрямую, оставлено как урок «техдолг убивает SEO».)

### 6.11 «Нейтрализация» ссылок ≠ «штраф»
**[NICE · SEO]** После Dec 2022 bad links просто не помогают.

### 6.12 Site Reputation Abuse: перемещение к субдомену = circumvent policy
**[MUST · SEO]** Move к new domain + nofollow от старого, НЕ subdomain/subdirectory.

### 6.13 Reconsideration Request: конкретика, числа, документированные actions
**[MUST · SEO]** «Удалено 17,879 статей (список), stripped AggregateRating с 341…» = шанс на approval.

### 6.14 0% hallucination у LLM не существует
**[MUST · Data/Content]** Все даты, цифры, имена, адреса — верифицировать по первоисточникам.

## 8. Масштаб и управление качеством

### 8.1 «N страниц в неделю» = N системных изменений, а не N URL вручную
**[MUST · SEO]** Единица работы — шаблон/правило/класс страниц/гейт.

### 8.2 Ручное участие — только в четырёх функциях
**[MUST · SEO]** (1) владелец SEO-качества, (2) владелец контент-шаблонов, (3) владелец локализации, (4) аналитика/мониторинг.

### 8.3 Pipeline routing: три исхода
**[MUST · Dev/SEO]** Auto publish (прошла гейты) → Hold (не прошла блокирующий) → Human review (новый шаблон/язык/money page/аномальный score).

### 8.4 Blast radius: ограничить выход нового паттерна за сутки
**[MUST · Dev]** Новый шаблон — не более сотен URL за первый выход.

### 8.5 Канареечный релиз для каждого нового шаблона/языка/логики
**[MUST · Dev/SEO]** Малая выборка → 1–2 языка → мониторинг 7–14 дней → расширение.

### 8.6 Каждый page type должен иметь задокументированный «Unit of Value»
**[MUST · SEO/Content]** Уникальные данные / интерпретация / FAQ / следующие шаги — хотя бы один слой.

### 8.7 Doorway red flags — операционный чеклист (2+ совпали = высокий риск)
**[MUST · SEO/Dev]** 80–90% одинакового контента / нет реального спроса / все ведут в одну конверсию / только в sitemap без органической перелинковки.

### 8.8 Multilingual Gate — блокирующий, до индексации
**[MUST · Dev/Data]** hreflang-граф + canonical sanity + language detection + anti-fake translation + near-duplicate.

### 8.9 Политика hold-first: новые шаблоны и языки = noindex по умолчанию
**[MUST · Dev/SEO]** Переход в индекс — явное действие, не дефолт.

### 8.10 Pre-publish checks — 4 группы
**[MUST · Dev/SEO]** A. Duplicate/doorway-risk; B. Multilingual integrity; C. Value & consistency; D. Indexability.

### 8.11 Матрица page types × риск
**[MUST · SEO]** Высокий риск = human review; средний = канарейка; низкий = auto publish при гейтах.

### 8.12 При «not indexed эпидемии» — найти 1–3 доминирующих паттерна, не лечить поштучно
**[MUST · SEO]** Сегментация → техпричины → pruning → усиление Unit of Value → канареечный rollout.

## 9. Индексируемость: noindex / robots / canonical / redirect

### 9.1 Четыре инструмента — четыре функции: разделить и не смешивать
**[MUST · Dev/SEO]**

| Инструмент | Что делает | Чего НЕ делает |
|---|---|---|
| `robots.txt Disallow` | Запрещает **сканирование** | Не удаляет из индекса; не блокирует ссылочные сигналы |
| `noindex` (meta / X-Robots) | Исключает из **индекса** | Не запрещает сканирование; не передаёт вес |
| `canonical` | Указывает **предпочтительный URL** | Не гарантия; игнорируется при конфликте |
| `301 redirect` | Передаёт **PageRank** на новый URL | Не удаляет из индекса мгновенно; создаёт hop |

### 9.2 Иерархия инструментов: когда что применять
**[MUST · Dev]**

| Сценарий | Инструмент |
|---|---|
| URL никогда не должен быть в индексе (корзина, аккаунт, поиск) | `noindex` + `Disallow` |
| URL временно не должен (staging, preview) | `noindex` (без Disallow) |
| URL удалён навсегда (ROT) | `410 Gone` |
| URL переехал | `301 → new URL` |
| URL дубль (параметры, сессии) | `canonical → primary` + `noindex, follow` |
| URL временно недоступен | `404` |

Запрет: `canonical` на `noindex`-страницу — результат непредсказуем.

### 9.3 Redirect chains запрещены: максимум 1 хоп
**[MUST · Dev]** При A→B→C: обновить redirect A напрямую на C. Batch: `for url in $(cat top_urls.txt); do hops=$(curl -sL -o /dev/null -w "%{num_redirects}" "$url"); [ "$hops" -gt 1 ] && echo "CHAIN: $url ($hops hops)"; done`.

### 9.4 Redirect loop = P0 инцидент: мгновенная блокировка
**[MUST · Dev]** `curl -L --max-redirs 5 <URL>`. Fix приоритет P0 независимо от трафика. После — переотправить через Indexing API.

### 9.5 Политика 404 vs 410 vs 301 для удалённых страниц
**[SHOULD · Dev/SEO]** Удалена навсегда → **410**; временно → **404**; переехала → **301 → target**; объединена → **301 → primary**; категория расформирована → **301 → parent** (не на главную).

### 9.6 Ежемесячный аудит «индексационного мусора»
**[SHOULD · SEO]** GSC Coverage → Excluded → топ-100 → классифицировать (намеренное/непредвиденное) → исправлять непредвиденные.

## 10. Параметры, фасеты, пагинация

### 10.1 Tracking-параметры не должны создавать уникальных URL для Googlebot
**[MUST · Dev/Infra]** Canonical без параметра + `Disallow: /*?utm_` (аналогично `?ref=`, `?currency=`, `?distance=`, `?preview=true`).

### 10.2 Стратегия для filter/facet параметров: явное решение для каждого типа
**[MUST · Dev/SEO]**

| Тип параметра | Реальный спрос? | Стратегия |
|---|---|---|
| Фильтр с реальным спросом (brand+color) | Да | index + canonical self + sitemap |
| Фильтр без спроса (size=XL) | Нет | `noindex, follow` + canonical → base |
| Сортировка (sort=price) | Нет | `noindex, follow` + canonical → base |
| Tracking (utm, ref) | Нет | `Disallow` + canonical |
| Пагинация (page=2) | Частично | self-canonical; не в sitemap |
| A/B вариант (?variant=b) | Нет | `noindex` или canonical → control |

### 10.3 Пагинация: canonical self на каждой; «page 1 с параметром» = дубль
**[MUST · Dev]** Page 1 с `?page=1` и без параметра → один 301 → другой; страницы 2+ → self-canonical (не на page 1), не в sitemap; бесконечная прокрутка → статические URL для краулера.

### 10.4 Параметризованные URL не генерировать как `<a href>` если noindex
**[MUST · Dev]** `grep -r "?color=\|?size=\|?sort=" templates/` → все через `<button>`/JS. Иначе краулер находит тысячи параметризованных URL несмотря на noindex.

### 10.5 Бесконечная лента: статическая альтернатива для Googlebot
**[SHOULD · Dev]** `/articles?page=2` либо `<noscript>` с прямыми ссылками.

### 10.6 Мониторинг параметрного «взрыва» после деплоя
**[SHOULD · SEO]** 10K+ новых Excluded URL за неделю без изменений контента → откат или немедленный `noindex`/`Disallow`.

## 11. Миграции: переезды, редизайны, массовые правки URL

### 11.1 Pre-migration checklist (обязателен до деплоя с изменением URL)
**[MUST · Dev/SEO]**
```
□ 1:1 redirect map готов (старый URL → новый, status_code, reason)
□ Redirect chains проверены (curl -L -I по всем новым URL)
□ Redirect loops проверены (curl -L --max-redirs 5)
□ Sitemap обновлён; internal links обновлены; canonical обновлены
□ hreflang матрица обновлена; robots.txt не блокирует новые пути
□ Schema JSON-LD обновлены (mainEntityOfPage, URL)
□ GSC: оба URL (старый + новый) отслеживаются
□ Staging smoke test: топ-50 URL вручную
```

### 11.2 Redirect map: формат и хранение
**[MUST · Dev]** CSV/JSON `{source_url, target_url, status_code, reason}`; нет дублей source; нет target, который сам редиректит; target проверен на 200 на staging; хранится в git.

### 11.3 Post-release monitoring: 72 часа
**[MUST · Dev/SEO]** Часы 0–4: 5xx/404 spike, redirect chains (server logs). День 1: GSC Coverage delta. Дни 2–3: organic WoW, топ-50 spot-checks, canonical consistency.

### 11.4 Стадированная миграция для крупных изменений (>10K URL)
**[SHOULD · Dev/SEO]** 1–5% → мониторинг 7д → 20–30% → 7д → остальные.

### 11.5 Обновление топ-20 внешних доноров ссылок
**[NICE · SEO]** Уменьшает redirect hops для значимого PageRank.

### 11.6 Post-migration SEO audit через 30 дней
**[SHOULD · SEO]** Indexed count без просадки >5%; позиции топ-100 ±3; при просадке >20% → `scripts/penalty_triage.py`.

## 12. Мониторинг и наблюдаемость

### 12.1 GSC: ежедневный минимум и еженедельный расширенный
**[MUST · SEO]** Ежедневно: Manual Actions (пусто?), Coverage «Crawled, not indexed» delta (>500 = alert), Security Issues, Performance clicks WoW (падение >20% = alert). Еженедельно: Enhancement reports (Article/BreadcrumbList/Product) 0 errors *(FAQPage report удалён Google в июне 2026)*, CWV mobile «Poor» <5%, top queries входы/выходы из топ-20.

### 12.2 Server-side SLA: P0 и P1 инциденты
**[MUST · Infra]**

| Тип | Триггер | Реакция |
|---|---|---|
| **P0** | 5xx >1% / Redirect loop / Manual Action / robots блокирует всё / Organic −50% за час | 15 мин |
| **P1** | TTFB p95 >1500ms / 404 spike >2× baseline / CWV «Poor» >10% нового шаблона | 1–4 часа |
| **P2** | «Crawled, not indexed» >500/день / AVI tier «<70» >30% статей | 24–48 часов |

### 12.3 Post-release monitoring window: 72 часа
**[MUST · Dev/SEO]** Каждый деплой шаблонов/роутинга. Детали — 11.3.

### 12.4 Диагностика «необъяснимого» падения трафика
**[MUST · SEO]** Дерево: Manual Actions → совпадение с Google update → trailing_slash audit → fake_translation → Coverage аномалии → cross_page_similarity → TTFB/5xx/CWV.

### 12.5 Crawl log analysis: ежемесячный аудит crawl budget
**[NICE · Infra/SEO]** Аномалия: Googlebot >20% crawl budget на параметризованные/404 URL.

---

# Часть 2 — Проектные принципы и паттерны реализации (by-design)

> Извлечено из трёхстороннего конкурентного анализа (GetTransfer / WelcomePickups /
> SunTransfers). Применимо к любому сайту-каталогу; масштабируется от 50 до 50 000 страниц.
> Примеры — на transfer-кейсе, паттерны универсальны.

## Ключевые инсайты

**Инсайт 1 — «Данные есть, но поиск их не видит».** Ценные коммерческие сигналы живут в DOM, но не обёрнуты в JSON-LD. Структурированные данные — это API между сайтом и поисковыми/AI-системами.

**Инсайт 2 — «Тонкие страницы без бренд-авторитета — худшая позиция».** Можно быть тонким и выигрывать (30k агрегированных отзывов в схеме) или глубоким и выигрывать (450 экспертных статей). Тонкий БЕЗ бренд-сигналов — проигрыш.

**Инсайт 3 — «Шаблонные баги убивают страницы быстрее конкурентов».** Один баг в title/H1 = конфликт сигналов = Page 2. Аудит шаблонов ценнее написания новых статей.

**Инсайт 4 — «AI-поиск работает по другим правилам».** ChatGPT/Perplexity/Gemini не ранжируют — извлекают: FAQPage Q&A, именованные сущности, атрибутированные цитаты. Без FAQ-блока и `@graph` страницы не существует для AI.

**Инсайт 5 — «Длина статьи не равна качеству».** 1000 правильно структурированных слов (intro → narrative H2s → PAA H2s → author schema → @graph) лучше 3000 без разметки.

**Инсайт 6 — «Внутренние ссылки — валюта авторитета».** Без хаб-структуры каждая страница дерётся за себя в одиночку.

## Раздел A — Архитектор (SEO + Tech Lead)

- **A1 — URL отражает таксономию намерений.** Один URL — одно намерение; near-duplicate intent → canonical; slug содержит специфический идентификатор сущности, не родовое слово.
- **A2 — Каждый тип страницы = отдельный шаблон с набором JSON-LD.** Матрица: любая страница → WebSite+Organization+BreadcrumbList+WebPage; коммерческая → +Product/AggregateRating/Offer; отзывы → +Review; гео → +LocalBusiness/PostalAddress; блог → +Article/Person/ImageObject; FAQ → +FAQPage.
- **A3 — AggregateRating по гео-каскаду** (сущность → город → страна → весь сайт с явным указанием). Критично: schema = видимый контент.
- **A4 — Sitemap — отдельная система.** Один `<url>` = loc + lastmod + все hreflang (не дублировать в N языковых файлов); разбивать по типу, не по языку; исключать служебные/пагинацию/параметры/noindex.
- **A5 — Dormant pages first.** Аудит БД на `published=false` категорийные индексы — публикация стоит ноль слов контента и даёт + к каталогу.
- **A6 — Каталог по P2P-комбинаторике, не перечислению.** Публиковать комбинаторную страницу только при: реальные данные + уникальный блок + спрос ≥10 поисков/мес.
- **A7 — Языки: глубина важнее ширины.** Tier 1 (нативный): en/de/fr/es/ru; Tier 2 (проф. перевод); Tier 3 (машинный+ревью). Hreflang только для прошедших качественный гейт.

## Раздел P — Программист (Backend + Template)

- **P1 — Title и H1: специфичность обязательна.** Включать все идентификаторы сущности; при пустом `airport_label` → fallback к полному названию, не убирать. H1 и title семантически идентичны.
```php
// ПЛОХО: "{city} Airport Transfers ({iata})" → "Istanbul Airport Transfers (SAW)" — конфликт с IST
// ХОРОШО: "{city} {airport_label} Airport Transfers ({iata})" → "Istanbul Sabiha Gökçen Airport Transfers (SAW)"
```
- **P2 — JSON-LD @graph: один блок с несколькими @type**, перекрёстные `@id`, не несколько `<script>`.
- **P3 — AggregateRating: pipeline из DWH** с гео-каскадом и минимальным порогом (напр. ≥30 отзывов); render рядом с H1, иначе «invisible content».
- **P4 — Offers block: fallback-цепочка**, не silent failure (точный → город → live routes → скрыть блок, не рендерить пустой H2).
- **P5 — Reviews block: гео-скоуп**, не global fallback (нерелевантные отзывы хуже пустого блока).
- **P6 — datePublished и dateModified обязательны в WebPage schema**; updatedAt обновляется при любом изменении контента, включая цену/рейтинг из DWH.
- **P7 — Wrapping Offers cards в Product + Offer + Review schema** (данные уже в DOM — обернуть, ноль изменений контента). ⚠️ На WEARBASE — только где есть реальная цена/наличие (см. §3.11, marketing_seo.md).
- **P8 — FAQ: Q&A в H2 дешевле FAQPage schema** (Google подхватывает в PAA; AI извлекает из H2). Последний вопрос = CTA. При FAQPage schema — синхронизировать с видимым контентом.
- **P9 — Currency localization** по приоритету: сессия пользователя → geo-IP → валюта страны страницы.

## Раздел F — Верстальщик (Frontend + HTML)

- **F1 — Иерархия заголовков:** один H1, строгая вложенность (не пропускать уровни). Шаблон H2 для каталожной страницы: Offers → Reviews (с видимым AggregateRating) → How to → Vehicles → Why us → FAQ.
- **F2 — AggregateRating видна рядом с H1** (видимое подтверждение для JSON-LD).
- **F3 — "From X" price anchor выше сгиба** — коммерческий сигнал + конверсионный якорь.
- **F4 — Breadcrumb: минимум 3 уровня**, JSON-LD/microdata синхронизирован с видимым, position с 1.
- **F5 — Open Graph + Twitter Cards: обязательный минимум** (og:type/title/description/image 1200×630/url/locale; twitter:card summary_large_image; canonical; hreflang + x-default).
- **F6 — Внутренние ссылки: блоки «связанных» в каждом шаблоне** (похожие маршруты, соседние категории, типы услуг, хаб города/страны). Плотность достигается блоками, не ручным проставлением.
- **F7 — Изображения: alt + width/height + lazy** (width/height предотвращают CLS; hero — eager, остальные — lazy).
- **F8 — Блок отзывов: Review microdata для каждого** (reviewRating/reviewBody/author/datePublished).
- **F9 — Структура блога:** narrative H2s → PAA-вопросы как H2 → последний вопрос = CTA в FAQ.

## Раздел C — Контент (редактор / стратег)

- **C1 — Длина по глубине темы:** utility 500–700; listicle 700–900; explainer 1000–1500; segment guide 1000–1200; hub 1500–2000. Не гнаться за словами ради слов.
- **C2 — Цены только в транспортных материалах** (how-to, transfer pages, comparison — обязательно; рестораны/listicle/utility — нет, content debt).
- **C3 — Canonical-консолидация: один URL на намерение** (near-duplicate intent → canonical, иначе каннибализация).
- **C4 — E-E-A-T: именованные авторы с биографией** (`/author/{slug}/` + Person schema; 3–5 постоянных авторов, не 50 без истории). Для каталога: «Reviewed/Updated by» + дата; Organization + sameAs.
- **C5 — Свежесть: dateModified ≠ datePublished** — обновлять при реальных изменениях (цены/рейтинг/контент), не фиктивно ежедневно.

## Release checklist (by-design)

**Технический минимум (блокирующий):** специфический title; один H1 = title; description 150–160; self-canonical; hreflang; JSON-LD @graph (WebSite+WebPage+Organization+BreadcrumbList); AggregateRating = видимое; OG-теги (og:image 1200×630); URL в sitemap.

**Контентный минимум (блокирующий):** H1 со специфическим идентификатором; видимый AggregateRating рядом с H1 (если есть отзывы); price anchor выше сгиба (коммерческие); ≥5 FAQ в H2/H3; гео-скоуп отзывов; breadcrumb = JSON-LD.

**Рекомендуемый:** Product+Offer (если реальные цены); ImageObject hero; date(Published/Modified); блоки внутренних ссылок; локальная валюта; непустой Offers block.

## Метрики успеха

**Leading (контролируем):** avg JSON-LD @types/page 2→8→10; % страниц с FAQ 0→40→90%; median internal links ~40→80→150; % с visible AggregateRating 0→60→95%; % с price anchor 0→50→90%; editorial статей 0→15→80.

**Lagging (результат):** GSC avg position топ-100 −3→−6; clicks/мес +30%→+120%; ⭐ в SERP → >50% eligible; AI citation → ≥30% top queries.

**Quick win validation (4 недели):** после фикса title/H1 — GSC Search Appearance по конкретным URL → Submit to indexing + 7–14 дней → сравнить avg position до/после.

## Итоговые принципы (10 правил в одной строке)

1. **Данные в DOM → данные в JSON-LD.**
2. **Специфичность H1/title** (разные сущности — разные заголовки).
3. **AggregateRating везде** (⭐ поднимает CTR на 20–35%).
4. **FAQ-блок = AI-citation entry point.**
5. **Гео-скоуп контента** (нерелевантное хуже пустого).
6. **Dormant pages first.**
7. **Внутренние ссылки = авторитет** (это структура, не спам).
8. **Длина по теме, не по числу слов.**
9. **Шаблонные баги убивают быстрее конкурентов.**
10. **AI search и Google search — разные движки.**

---

# Часть 3 — Управление файлом (SEMVER)

Файл версионируется по [Semantic Versioning 2.0.0](https://semver.org/): `MAJOR.MINOR.PATCH`.
- **MAJOR** — удаление/реструктуризация блоков, смена фундаментального принципа.
- **MINOR** — добавление правил/блоков, значимое расширение.
- **PATCH** — уточнение формулировки, опечатка, обновление порога, пример.

Старые версии не удаляются — переезжают в [`archive/`](archive/) для истории и отката.

## CHANGELOG

```
## [3.0.0] — 2026-06-16
### Changed
- КОНСОЛИДАЦИЯ: три файла сведены в один канон seo_rules.md:
  • seo_rules_SEO_GUIDE.md (v1.0)  → Часть 0 «WEARBASE — рабочие константы»
  • seo_rules_2.3.0.md             → Часть 1 «Нормативные правила MUST/SHOULD/NICE» + Приложение
  • прежний seo_rules.md           → Часть 2 «Проектные принципы и паттерны (by-design)»
- Оригиналы перемещены в docs/archive/ (помечены как АРХИВ, для отката).
- 6.10 (Prisma) помечено как неприменимое к Symfony-стеку WEARBASE (оставлено как урок).
- Дедуплицированы: списки запрещённых слов (полный — Часть 0, §3.7 ссылается),
  легенда MUST/SHOULD/NICE поднята в шапку.

## [2.3.0] — 2026-05-26
### Changed
- 3.15: FAQPage schema — мотивация переключена с SERP rich results (удалены Google 07.05.2026) на GEO/AI Overviews
- 1.3: FAQPage убрана из обязательного SSR-гейта; 12.1: FAQPage удалена из Weekly Enhancement reports

## [2.2.0] — 2026-05-18 — Added: легенда MUST/SHOULD/NICE; правила 1.13–1.14, 2.11–2.14, 3.17; блоки 9–12; приложение чек-листов
## [2.1.0] — 2026-05-15 — Added: блок 8 (масштаб и управление качеством)
## [2.0.0] — 2026-04-17 — Added: 7 блоков + SEMVER (55 правил)
## [1.0]   — 2026-05-14 — by-design принципы (Часть 2) + WEARBASE-константы (Часть 0)
```

---

# Приложение — Чек-листы

### A. Pre-release (деплой шаблонов, роутинга, структуры)
```
TECHNICAL FOUNDATION
□ trailing-slash: canonical = sitemap = served = internal links — один формат
□ SSR gate: curl → title, H1, canonical, JSON-LD, внутренние ссылки в HTML
□ noindex: нет accidental noindex на продакшен-страницах
□ canonical: все → 200 OK без redirect (batch curl)
□ robots.txt: Googlebot + Googlebot-Extended Allow: /; UTM/tracking Disallow
□ redirect chains: max 1 hop; redirect loops: нет
□ schema: Rich Results Test → 0 errors на изменённых шаблонах
□ 3rd-party scripts: новые — Lighthouse before/after

ARCHITECTURE
□ page types matrix обновлена при новом типе
□ перелинковка: новые страницы — ссылки из ≥2 шаблонных модулей
□ pagination: self-canonical на каждой; page 1 без дублей
□ параметры: noindex/Disallow/canonical стратегии зафиксированы
□ migration doc: redirect map готов если меняются URL

CONTENT (при деплое новых шаблонов контента)
□ Author Bio присутствует и верифицируем
□ no fake aggregateRating; no TIER-1 AI words (scripts/ai_detector.py)
□ publishing velocity ≤ 5 URL/день на домен
```

### B. Post-release (первые 72 часа)
```
Часы 0–4: 5xx <0.5%; 404 ≤2× baseline; redirect loops нет; robots.txt не сломан
День 1: GSC Coverage без аномального «Page with redirect»/«Not found»; URL Inspection spot-check 5–10
Дни 2–3: GSC Performance без резкого падения WoW; CWV без регрессии; Manual Actions пусто
```

### C. Monthly SEO audit
```
INDEXATION: indexed count vs прошлый месяц (без падения >5%); топ-100 Excluded классифицировать; sitemap без редиректов/404/noindex
CONTENT QUALITY: avi_calculator доля <70 ≤30%; «жёлтая зона» (8–25, >500 impressions) refresh топ-20; ROT pages → 410/301; near-duplicate audit
TECHNICAL: trailing-slash / hreflang / fake-translation audits → 0; manual_action_scanner пусто
PERFORMANCE: CWV mobile «Poor» <5%; TTFB p95 <600ms; nginx cache hit >80%
CONTENT PIPELINE: velocity ≤5/день; isReal NULL = 0; L1 RAG enrichments ≥3/неделю; pass rate ≥70%
```

---

*Канон собран на основе production-опыта: key-g.com, blog.getrentacar.com, blog.gettransfer.com,
GetModel.com, и конкурентного анализа transfer-сегмента. Оригинальные файлы — в [`archive/`](archive/).*
