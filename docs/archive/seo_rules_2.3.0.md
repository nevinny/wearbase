# SEO Rules — v2.3.0
**Версия:** 2.3.0 | **Дата:** 2026-05-26
**Основано на:** SEO-PEDIA-2026, SpamBrain PDF, AVI PDF, Manual Actions, production-кейсах Key Group, external framework v2 (indexability / migrations / monitoring / parameters layer)

---

## Легенда

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

## 1. Техническая основа

### 1.1 Trailing-slash — выбери один формат и зафиксируй намертво
**[MUST · Infra/Dev]** Проверка: `scripts/trailing_slash_audit.py <domain>` → 0 mismatches. ОК, если: canonical = sitemap = served URL = internal links — один формат. Симптом: «Page with redirect» или «Crawled, currently not indexed» в GSC без видимой причины.

Canonical, sitemap, internal links, CDN-конфиг и server redirects должны возвращать **один и тот же формат URL** — либо всегда с `/`, либо всегда без. Несоответствие хотя бы в одном слое — Google перестаёт индексировать страницы без уведомлений. Реальный инцидент: blog.getrentacar.com — 0% индексации из-за этого.

Дополнительно: внутренние и внешние ссылки не должны вести на «несуществующую форму» URL, иначе Google видит лишние редиректы до канонической версии и теряет сигналы.

### 1.2 Canonical всегда указывает на себя, на живой 200-OK URL
**[MUST · Dev]** Проверка: `curl -sI <canonical-url>` → 200 OK, нет noindex в `X-Robots-Tag`. ОК, если: canonical → 200 без redirect, без noindex. Симптом: страница выпадает из индекса без Manual Action.

Canonical — **сильная подсказка для Google, не директива**. При конфликте сигналов (canonical ↔ sitemap ↔ hreflang ↔ внутренние ссылки ↔ редиректы) Google вправе выбрать другой URL. Следствие: все сигналы должны быть консистентны между собой — именно это создаёт доверие к canonical, а не сам тег в одиночку. Canonical на noindex-страницу или на URL с redirect — уничтожает передачу сигнала ранжирования.

### 1.3 SSR обязателен для всего SEO-критичного контента
**[MUST · Dev]** Проверка: `curl -s https://example.com/page | grep -c "<h1>"` > 0; title и canonical видны без JS. Симптом: URL Inspection в GSC показывает пустую страницу или «rendered differently».

CSR не означает «невидимость», но означает **риск задержки рендера и нестабильной индексации**: Googlebot ставит JS-рендер в низкоприоритетную очередь.

**Что обязательно должно быть в HTML при первичной загрузке (SSR-гейт):**
- `<title>`, `<meta name="description">`, `<meta name="robots">`
- Один `<h1>`, основной текст контента
- Breadcrumbs HTML + JSON-LD
- `<link rel="canonical">`, `<link rel="alternate" hreflang>` (если multilingual)
- JSON-LD schemas (Article / Organization) — FAQPage если на странице есть FAQ-секция
- Внутренние ссылки на связанные страницы

Допустимо загружать через JS: комментарии, интерактивные калькуляторы, рекомендательные виджеты, личный кабинет.

### 1.4 HTTPS + HSTS — без исключений; HTTP → HTTPS только 301, не 302
**[MUST · Infra]** Проверка: `curl -I http://example.com` → 301 (не 302); HSTS header присутствует. Симптом: утечка PageRank на HTTP-версию.

302 — временный редирект, PageRank не передаётся. HSTS защищает от downgrade-атак.

### 1.5 Google Indexing API: строго ≤ 200 URL/день, батч по 50, задержка 1500ms
**[SHOULD · Dev]** Жёсткая квота Google. Превышение тихо сжигает дневной лимит без ошибки. Приоритизировать: новые/изменённые страницы первыми.

### 1.6 Sitemap: только канонические 200-OK URL, ≤ 5000 URL/день при submission
**[MUST · Dev/Data]** Проверка: sitemap не содержит редиректов, 404, noindex-URL; `lastmod` = реальная дата `updated_at` из БД. ОК, если: все URL в sitemap → 200, имеют реальный `lastmod`. Симптом: «Discovered — currently not indexed» растёт без кратного роста нового контента.

**Стандарт качества sitemap:**
- Только канонические 200-OK URL — без редиректов, 404, noindex
- `lastmod` реальный из БД (фиктивные одинаковые даты Google игнорирует)
- Разделение по типам: pages / articles / news / media + `sitemap_index.xml` если > 50K URL
- Sitemap — подсказка, не гарантия индексации; без нормальной перелинковки недостаточна
- ≤ 50K URL / 50MB на файл; ≤ 5 000 новых URL в день при submission

### 1.7 robots.txt: блокируй параметры, не страницы по смыслу
**[MUST · Dev]** Проверка: `curl https://example.com/robots.txt` → содержит `User-agent: Googlebot-Extended Allow: /` и `Disallow: /*?utm_`. Симптом: crawl budget тратится на параметризованные URL; выпадение из AI Overviews.

`Disallow: /*?utm_`, `Disallow: /*?ref=`, `Disallow: /*?currency=` — параметры создают дубли и сжирают crawl budget. Не блокировать `Googlebot` и `Google-Extended` — выпадешь из AI Overviews.

**Важно:** `robots.txt Disallow` ограничивает **сканирование**, но **не удаляет URL из индекса**. Если на заблокированный URL ведут внешние ссылки — он останется в индексе без контента. Для реального удаления нужен `noindex` (meta или X-Robots-Tag). Подробнее — Блок 9.

### 1.8 Core Web Vitals — LCP ≤ 2.5s, INP ≤ 200ms, CLS ≤ 0.1
**[SHOULD · Dev/Infra]** Проверка: GSC → Core Web Vitals → Mobile field data → доля «Poor» < 5%. ОК, если: CrUX mobile «Good» для целевых URL. Симптом: drop в ранжировании коррелирует с деградацией в CWV report.

**Методология измерения:**
- Приоритет: **field data (CrUX)** — реальные пользователи, не lab
- Lab (Lighthouse) — только для диагностики и before/after сравнений
- **Mobile-first**: именно mobile field data определяет статус в GSC
- INP заменил FID с марта 2024; CLS ≥ 0.1 — немедленный откат на mobile

**Anti-паттерны:** preload всего подряд (конкурирует с LCP); тяжёлые шрифты без `font-display: swap`; render-blocking `<script>` без async/defer; изображения без explicit `width`/`height` (CLS).

### 1.9 TTFB ≤ 600ms — nginx cache обязателен, proxy_ignore_headers для Next.js
**[SHOULD · Infra]** Проверка: `curl -o /dev/null -s -w "%{time_starttransfer}" https://example.com/page` < 0.6s. Симптом: LCP плохой при хорошем CLS; cache hit rate в nginx < 60%.

Без `proxy_ignore_headers Cache-Control Set-Cookie Expires` nginx сбрасывает кэш при каждом Set-Cookie от Next.js/Prisma. Реальный результат: TTFB с 800ms до 50ms после патча.

```nginx
proxy_cache_path /var/cache/nginx levels=1:2 keys_zone=blog:100m max_size=10g inactive=60m;
server {
    location / {
        proxy_cache blog;
        proxy_cache_valid 200 302 10m;
        proxy_cache_key "$scheme$host$request_uri";
        proxy_ignore_headers Cache-Control Set-Cookie Expires;
        proxy_hide_header Set-Cookie;
        proxy_pass http://localhost:3000;
    }
}
```

### 1.10 Все 3rd-party скрипты — async/defer, не blocking
**[SHOULD · Dev]** Симптом: Lighthouse → «Eliminate render-blocking resources» → внешние скрипты.

**Политика допуска новых скриптов:**
- **MUST-класс** (аналитика): только `async`
- **SHOULD-класс** (маркетинг/AB): только `defer`; Lighthouse before/after до мёржа
- **NICE-класс** (хитмапы/эксперименты): `window.addEventListener('load', () => setTimeout(..., 3000))`

Каждый новый скрипт требует CWV-сравнение before/after — без этого скрипты разрастаются бесконтрольно.

### 1.11 Изображения: WebP, explicit width/height, lazy ниже fold, preload hero
**[SHOULD · Dev]** Проверка: Lighthouse → no images without explicit width/height. Симптом: CLS скачок при загрузке.

`loading="lazy"` без `width`/`height` = CLS. Hero image — `<link rel="preload" as="image">`. Srcset обязателен: экономит 60–70% трафика на mobile.

### 1.12 Orphan rate внутренних страниц < 3%
**[SHOULD · Dev/SEO]** Проверка: Screaming Frog → «No incoming internal links» ≤ 3% SEO-ценных URL. Симптом: качественный контент не набирает позиции — PageRank не доходит.

### 1.13 Редиректы: максимум 1 хоп, петля = P0, политика 404 vs 410
**[MUST · Dev]** Проверка: `curl -L -I <URL>` → не более одного 3xx до финального URL. Симптом: «Page with redirect» в GSC; рост 3xx в access logs.

**Стандарт:**
- **Redirect chain запрещены** — максимум 1 хоп (A→B, не A→B→C)
- **Redirect loop = P0** — мгновенная блокировка индексации; fix приоритет P0
- **301** — постоянный; передаёт PageRank; должен существовать вечно
- **302** — временный; не передаёт PageRank; только если URL вернётся
- **410 Gone** — удалено навсегда; Google очищает из индекса быстрее чем 404
- **301 на главную** при удалении = soft-404 в глазах Google (расценивается как ошибка)

### 1.14 SSR release gate: автоматический тест перед деплоем шаблонов
**[MUST · Dev]** Проверка: автоматический curl-тест на staging — grep на наличие всех элементов из 1.3 перед мёржем. Симптом: URL Inspection показывает пустую страницу после «безобидного» шаблонного деплоя.

---

## 2. Архитектура сайта

### 2.1 Flat architecture: все ценные страницы ≤ 3 клика от главной
**[SHOULD · Dev/SEO]** Проверка: Screaming Frog crawl → «Crawl Depth» → SEO-ценные URL (> 100 impressions/мес в GSC) не глубже 3. Управляемый критерий: **доля SEO-ценных страниц глубже 3 кликов < 2%** (избавляет от споров про 3 vs 4, даёт измеримый результат).

Глубокие иерархии хоронят crawl budget и размазывают link equity. DiscoverCars — 341K URL, всё на 2 клика — эталон.

### 2.2 Один URL = одна страница = один search intent
**[MUST · SEO]** Каждая страница отвечает ровно на один основной запрос. Множество URLs под похожие запросы без уникального контента = каннибализация = Google выбирает одну случайно.

### 2.3 URL: lowercase, hyphens, ≤ 60 символов, без keyword stuffing
**[MUST · Dev]** Проверка: `grep -E "[A-Z_]" urls.txt | wc -l` = 0.

**Расширенная политика URL:**
- Только lowercase + hyphens (underscores Google не разбивает как разделители)
- Длина slug ≤ 60 символов; нет keyword stuffing
- Нет технических хвостов: session_id, tracking tokens, numeric ID в SEO-URL
- Нелатиница: транслитерация, если аудитория мультиязычная; локальный slug — только если ЦА исключительно этот язык
- Trailing slash / его отсутствие — единый стандарт из правила 1.1

### 2.4 URL никогда не меняй после публикации — только через 301 на замену
**[MUST · Dev/SEO]** 301 только на семантически релевантную замену. 301 на главную = soft-404 в глазах Google.

### 2.5 Canonical + hreflang: самоссылающийся canonical на каждой языковой версии
**[MUST · Dev]** Проверка: `curl https://example.com/ru/page | grep canonical` = `https://example.com/ru/page` (не EN-версия). Симптом: переводы выпадают из индекса.

Canonical — **сильная подсказка, не директива**. При конфликте canonical ↔ hreflang ↔ sitemap ↔ внутренние ссылки Google самостоятельно выбирает «правильный» URL. Именно поэтому все четыре сигнала **обязаны быть консистентны** — нельзя исправить только canonical и надеяться, что он перевесит несогласующиеся ссылки.

Ошибка #1 в multilingual: canonical на EN-master со всех языков → переводы не индексируются.

### 2.6 Hreflang только bidirectional: если A→B, то B→A обязательно
**[MUST · Dev/Data]** Проверка: `scripts/hreflang_audit.py --sitemap sitemap.xml` → 0 missing back-references. Симптом: не те версии ранжируются в не тех регионах.

Google игнорирует одностороннее hreflang. Hreflang только для `isReal=true` переводов.

### 2.7 x-default обязателен в hreflang-матрице
**[MUST · Dev]** Без x-default Google не знает куда отправить пользователей неохваченных языков. Обычно = EN-версия.

### 2.8 Paginated pages: self-canonical на каждой, не canonical на page 1
**[MUST · Dev]** Проверка: `curl "https://example.com/articles?page=2" | grep canonical` = тот же URL с параметром. Симптом: страницы пагинации выпадают из индекса; дубли в Coverage.

### 2.9 Фильтры/facets: noindex+follow или canonical на main category
**[MUST · Dev]** Проверка: `curl -I "https://example.com/cars?color=red" | grep -i noindex` → noindex (если нет отдельного спроса). Симптом: GSC Coverage раздувается на комбинациях фильтров.

Оставлять индексируемыми только коммерчески ценные комбо с реальным поисковым спросом. `noindex, follow` (не `disallow` — иначе noindex не работает).

### 2.10 Breadcrumbs HTML + BreadcrumbList schema, position starts at 1
**[SHOULD · Dev]** Position 0 = ошибка схемы → Manual Action риск.

### 2.11 Каталог типов страниц: index/canonical/sitemap статус
**[MUST · SEO/Dev]** Проверка: для каждого типа страниц зафиксирована строка в матрице проекта. Симптом: новые типы улетают в индекс непредсказуемо.

Для каждого проекта документировать матрицу (хранить в CLAUDE.md / wiki):

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

Матрица обновляется при добавлении каждого нового типа страниц.

### 2.12 Система внутренней перелинковки: обязательные модули на шаблонах
**[SHOULD · Dev/SEO]** Проверка: Screaming Frog → каждая SEO-ценная страница получает ссылки из ≥ 2 шаблонных модулей. Симптом: orphan rate > 3%; новые страницы медленно набирают вес.

**Обязательные модули:**
- **Breadcrumbs** — на всех страницах кроме главной
- **«Похожие / Related»** — на карточках и статьях (3–5 ссылок)
- **Родительская категория** — в sidebar или footer
- **«Следующий / предыдущий»** — для последовательного контента
- **Hub-ссылки** — «всё об [теме]» со страниц кластера

Запрет: публиковать страницы без единой шаблонной входящей ссылки (orphan).

### 2.13 Политика каннибализации: выбор primary и действие по дублю
**[MUST · SEO]** Проверка: GSC → выбрать URL → «Search results» → несколько URL конкурируют за один запрос. Симптом: позиции «прыгают» между похожими URL; ни один не закрепляется выше 10.

**Механика:**
- Выбрать «primary» (больший трафик + лучший link profile)
- Второй: 301 → primary / canonical → primary / объединить контент / noindex
- Правило: **лучше меньше сильных страниц, чем много слабых**
- Типовые триггеры: теги, похожие подкатегории, параметризованные листинги, городские вариации

### 2.14 URL migration: обязательная redirect map перед любым изменением структуры
**[MUST · Dev/SEO]** Симптом: трафик исчезает после деплоя без видимых ошибок 5xx. Детали — Блок 11.

---

## 3. Контент и семантика

### 3.1 Минимум 1200 слов для информационных статей; абсолютный минимум 300 слов
**[SHOULD · Content]** Страницы < 300 слов = автоматический thin_content флаг. Для лонгридов: 1800+.

### 3.2 Один H1 на страницу; H1 ≠ просто keyword, это литературный заголовок
**[MUST · Content/Dev]** H2/H3 — иерархичные, логичные. H2 лучше формулировать как вопросы пользователя — матчат fan-out AI queries.

### 3.3 Keyword density ≤ 3%; exact-match anchor ratio < 0.60
**[SHOULD · Content]** Выше 3% — SpamBrain поднимает флаг. Точные анкоры > 60% от профиля = Unnatural Links маркер.

### 3.4 Параграфы максимум 2–3 предложения; прямой ответ в начале секции
**[SHOULD · Content]** Для GEO extractability — AI парсит блоки знаний, не стены текста.

### 3.5 5–6 именованных citations на статью с name/title/company attribution
**[SHOULD · Content]** «Studies show...» = vague claim. «Maria Rossi, head of pricing at Sixt Italia, told us: '...'» = E-E-A-T + GEO citation + human signal. Без sources → AI Overviews не цитирует.

### 3.6 Transition words ≤ 12/1000 слов; не начинать абзацы с Furthermore/Moreover/Additionally
**[SHOULD · Content]** SpamBrain и AI-детекторы смотрят на это буквально. AI начинает КАЖДЫЙ абзац с формальных транзишенов.

### 3.7 Нулевая терпимость к TIER-1 AI словам в published контенте
**[MUST · Content]** Проверка: `scripts/ai_detector.py article.md` → 0 TIER-1 hits. Симптом: Originality.AI → 90%+ AI probability.

`delve, tapestry, landscape, multifaceted, pivotal, realm, commendable, intricate, intricacies, noteworthy, meticulous, meticulously, testament, underpinning, underscores, nuanced, showcasing, embark, endeavor, encompass, spearhead, groundbreaking` — частота 3–10× выше человеческой нормы.

### 3.8 Sentence burstiness: CV(sentence length) ≥ 0.30
**[SHOULD · Content]** AI пишет всё в medium-range длин. Inject 3–4 коротких «punchy» предложений в каждую 1000-словную секцию.

### 3.9 E-E-A-T — косвенный, но весомый фактор
**[SHOULD · Content/SEO]** Google официально: E-E-A-T не прямой ranking factor. Реально: quality raters используют E-E-A-T для тренировки моделей. Без Author Bio + sameAs + experience markers → scaled content abuse риск.

### 3.10 Author Bio обязателен; один автор ≤ 100 статей
**[MUST · Content/Data]** Проверка: каждый автор имеет страницу с LinkedIn + фото + credentials. Симптом: Manual Action «Scaled Content Abuse» при масштабировании.

James Miller на 8614 статьях = ban. Нужны 3–5 реальных авторов с ротацией.

### 3.11 Fake AggregateRating = мгновенный CRITICAL Manual Action
**[MUST · Dev/Data]** Проверка: `scripts/schema_validator.py <url>` → no fake aggregateRating. Симптом: Manual Action «Structured data issue» → CRITICAL; blog.gettransfer.com — 6 месяцев recovery.

Без реальных отзывов — не ставить aggregateRating / Review schema вообще.

### 3.12 Контент обновлять ≤ раз в 3 месяца для конкурентных тем
**[NICE · Content]** AI recency bias — старше 3 мес → меньше citations в AI Overviews.

### 3.13 Information Gain — если контент воспроизводим из top-выдачи, SoM = 0
**[SHOULD · Content]** Проприетарные данные, кейсы, личный опыт, уникальная методология = единственное что даёт share-of-model. Скрейпинг + перефраз конкурентов = semantic duplicate.

### 3.14 FRES 60–70, ARI ≤ 9 — машинно проверяемые пороги
**[SHOULD · Content]** ARI ≥ 11 = переписывать. Коррелируют с bounce rate и scroll depth, которые SpamBrain учитывает как quality proxy.

### 3.15 FAQPage schema — для GEO/AI Overviews, не для SERP rich results
**[SHOULD · Dev/Content]** Fake FAQ без реального контента на странице = structured data violation.

С 7 мая 2026 Google удалил FAQPage rich results (аккордеоны в SERP). Причина ставить schema сохраняется: ChatGPT / Claude / Gemini / Perplexity используют FAQPage JSON-LD для извлечения структурированных Q/A в synthesized answers (GEO). H2 в формате вопросов пользователя — для покрытия fan-out AI queries. Не ставить FAQPage schema ради SERP-оформления: этого эффекта больше нет.

### 3.16 Не >3–5 новых статей/день с одного домена
**[MUST · Data/Dev]** Проверка: `SELECT COUNT(*) FROM articles WHERE published_at > NOW() - INTERVAL '24 hours' AND domain = ?` ≤ 5. Симптом: трафик падает через 2–4 недели без Manual Action.

SpamBrain отслеживает publishing velocity как primary scaled content abuse signal.

### 3.17 Near-duplicate контент: консолидация или noindex
**[MUST · SEO/Content]** Проверка: `scripts/cross_page_similarity.py` → similarity ≥ 0.85 при > 5 страниц = флаг. Симптом: кластер страниц не ранжируется несмотря на отсутствие явных нарушений.

**Политика:** similarity > 0.85 → консолидация в primary + 301; программатические страницы: minimum 60% уникального контента.

---

## 4. UX и поведенческие сигналы

### 4.1 Engagement Rate ≥ 50% (GA4) — минимальная планка
**[SHOULD · Content/Dev]** Engaged session = ≥10s OR ≥2 pageviews OR conversion event. ER < 40% при высоком трафике = фундаментальное несоответствие интента контенту.

### 4.2 Scroll depth 75% — критический порог для лонгрида
**[NICE · Dev]** GTM триггеры на 25/50/75/100%. Прямо влияет на позиции через «helpful content» сигналы.

### 4.3 Первый viewport — прямой ответ, не вводные абзацы
**[SHOULD · Content]** «In today's world...» — мусор для AI и для пользователя. Первые 2–3 предложения = direct answer на запрос.

### 4.4 Table of Contents для лонгридов — снижает pogo-sticking на 15–25%
**[NICE · Dev]** Якорные ссылки = навигационные микроконверсии.

### 4.5 CTA на 50% скролла, не в конце
**[NICE · Content/Dev]** Пользователи редко доходят до конца. Второй CTA в середине даёт прирост micro-conversions.

### 4.6 Mobile-first: tap targets ≥ 48px, text ≥ 16px, no sneaky redirects
**[MUST · Dev]** Sneaky mobile redirects — отдельный Manual Action. Часто появляются через взломанные 3rd-party скрипты.

### 4.7 Seasonal pages — permanent URL с ежегодным обновлением контента
**[SHOULD · Dev/Content]** `/black-friday/` вместо `/black-friday-2026/`. Постоянный URL накапливает backlinks годами.

---

## 5. Ссылочный профиль и авторитет

### 5.1 Branded anchors 40–60%, naked URL 15–25%, exact-match ≤ 3%
**[SHOULD · SEO]** SpamBrain смотрит на распределение, не на количество. Organic growth = хаотичный профиль.

### 5.2 Плохие ссылки не штрафуют — они аннулируют вес (Penguin умер в 2022)
**[NICE · SEO]** После Dec 2022 Link Spam Update SpamBrain нейтрализует spam-ссылки (0 PageRank, не минус). Удаление токсичных ссылок **не восстанавливает** позиции — кредит уже изъят.

### 5.3 Disavow ТОЛЬКО при Manual Action «Unnatural links» + документированные попытки removal
**[MUST · SEO]** Без попытки contact owner = rejection reconsideration. Disavow без Manual Action = потенциальное ухудшение.

### 5.4 Brand mentions без ссылок > traditional link building в 2026
**[SHOULD · SEO]** Brand mentions на Reddit/YouTube/Wikipedia коррелируют с AI Overviews citations сильнее чем DR-rich backlinks.

### 5.5 Organization + sameAs → Wikipedia/Wikidata/LinkedIn/Crunchbase — entity clarity
**[SHOULD · Dev/SEO]** Без entity clarity бренд «невидим» для AI-ответов. Wikidata entity = источник для Knowledge Panel.

### 5.6 Expired domain abuse — мгновенный алгоритмический фильтр
**[MUST · SEO]** SpamBrain отслеживает «semantic vector domain shift». Медицинский домен → казино = Entity Shift = обнуление траста. Буст 3–4 недели → падение.

### 5.7 PBN детектируется по Network Footprints: IP, DNS, nameservers, templates, analytics ID
**[NICE · SEO]** Даже при разных хостах SpamBrain строит граф пересечений.

---

## 6. Инсайдерские нюансы

### 6.1 SpamBrain выносит вердикт ДО анонса update — реагировать в момент анонса уже поздно
**[MUST · SEO]** March 2026 Spam Update: 19.5 часов rollout = синхронизация pre-computed scores. Сайт оценён за недели до анонса.

### 6.2 «Algorithmic penalty» = нет уведомления в GSC, нет Reconsideration Request
**[MUST · SEO]** Если трафик упал без Manual Action — это SpamBrain filter. Только: исправить паттерны + накопить чистую историю + ждать 3–6 месяцев.

### 6.3 Google Content Warehouse API утечка 2024: spambrainData, scamness, anchorMismatchDemotion — реальные атрибуты
**[NICE · SEO]** `spambrainData` — глобальная spam probability домена; обновляется непрерывно. `anchorMismatchDemotion` — пессимизация за семантическое несоответствие anchor и целевой страницы.

### 6.4 Helpful Content System — sitewide classifier, не page-level
**[MUST · SEO]** HCU оценивает САЙТ целиком. Если 30%+ контента thin → под ударом весь домен.

### 6.5 Fake translations → «crawled, currently not indexed» эпидемия без Manual Action
**[MUST · Data/Dev]** Проверка: `scripts/fake_translation_detector.py` → 0 fake translations в индексируемых URL. Симптом: «Crawled, currently not indexed» растёт коррелированно с конкретным языком.

DeepL под нагрузкой возвращает original language без ошибки. Решение: 3-уровневая валидация + поле `isReal` в БД.

### 6.6 AI Overviews отсутствует на transactional/action queries
**[SHOULD · SEO]** ~7% запросов = AI Overview. Transactional («buy», «book», «rent») = обычный SERP. Оптимизировать transactional страницы для traditional SERP — там CTR не «съеден».

### 6.7 Reddit #1 источник citations в ChatGPT, YouTube #1 в Google AI Mode
**[SHOULD · SEO]** Бренд без Reddit presence и YouTube канала = ~0% organic AI citations.

### 6.8 Supabase/hosted DB может быть root-cause производительности
**[NICE · Infra]** Кейс key-g.com: миграция на локальный PostgreSQL = 12s → 268ms (45×). Прямо влияет на TTFB → CWV → ранжирование.

### 6.9 Параметр `isReal: boolean` для каждого перевода — обязательный инфраструктурный паттерн
**[MUST · Data]** Проверка: `SELECT COUNT(*) FROM translations WHERE is_real IS NULL` = 0. Без этого поля невозможно управлять hreflang корректно в multilingual проекте с автопереводом.

### 6.10 Prisma 7 несовместим с Next.js standalone — использовать Prisma 5
**[MUST · Dev]** Продакшн-краш. Технический долг убивает SEO быстрее любого алгоритмического обновления.

### 6.11 «Нейтрализация» ссылок ≠ «штраф» — позиции не упадут, но и не вырастут
**[NICE · SEO]** После Dec 2022 bad links просто не помогают. Усилия на removal = потеря времени для algorithmic cases.

### 6.12 Site Reputation Abuse: перемещение к субдомену = попытка circumvent policy → broader action
**[MUST · SEO]** Move к new domain + nofollow от старого. НЕ subdomain/subdirectory.

### 6.13 Reconsideration Request: конкретика, числа, документированные actions
**[MUST · SEO]** «Мы улучшили качество» = reject. «Удалено 17,879 статей (список), stripped AggregateRating со 341 оставшихся, editorial policy опубликована...» = шанс на approval.

### 6.14 0% hallucination у LLM не существует; Qwen3-235B = 52% — не использовать на фактах
**[MUST · Data/Content]** Все даты, цифры, имена, адреса — верифицировать по первоисточникам. Даже Grok-4 (~15%) галлюцинирует.

---

## 7. Управление файлом правил

### Версионирование по SEMVER

Файл версионируется по стандарту [Semantic Versioning 2.0.0](https://semver.org/): `MAJOR.MINOR.PATCH`.

| Тип изменения | Когда применять | Пример |
|---|---|---|
| **MAJOR** (X.0.0) | Удаление блоков или правил; смена фундаментального принципа; реструктуризация, ломающая ссылки на разделы | Удалён блок 5; блок 3 переименован и разбит на два |
| **MINOR** (x.Y.0) | Добавление новых правил; добавление нового блока; значимое расширение существующего правила с изменением рекомендации | Добавлен блок 9; добавлено правило 3.17 |
| **PATCH** (x.y.Z) | Уточнение формулировки без изменения смысла; исправление опечатки; обновление числового порога; добавление примера | Уточнён порог в 1.8; исправлена опечатка в 3.7 |

### Соглашение об именовании файла

```
seo_rules_{MAJOR}.{MINOR}.{PATCH}.md
```

Старые версии не удаляются — хранятся рядом для сравнения и отката.

### Заголовок файла

```markdown
# SEO Rules — v{MAJOR}.{MINOR}.{PATCH}
**Версия:** {MAJOR}.{MINOR}.{PATCH} | **Дата:** YYYY-MM-DD
**Основано на:** {источники}
```

### Порядок внесения изменений

1. Определить тип изменения (MAJOR / MINOR / PATCH).
2. Внести правки в содержание.
3. Обновить версию в заголовке файла.
4. Сохранить файл под новым именем с обновлённой версией.
5. Добавить запись в CHANGELOG ниже.

### CHANGELOG

```
## [2.3.0] — 2026-05-26
### Changed
- 3.15: переименовано и расширено — FAQPage schema больше не даёт SERP rich results (Google удалил с 07.05.2026); мотивация переключена на GEO/AI Overviews extractability (ChatGPT/Claude/Gemini citations); H2 как вопросы — для AI fan-out queries
- 1.3: FAQPage убрана из обязательного перечня SSR-гейта → ставить только если на странице есть FAQ-секция
- 12.1: FAQPage удалена из Weekly Enhancement reports — отчёт удалён в GSC в июне 2026

## [2.2.0] — 2026-05-18
### Added
- Легенда (уровни строгости MUST/SHOULD/NICE + слои ответственности)
- Метки [MUST/SHOULD/NICE · Слой] ко всем существующим правилам
- Правило 1.13: Redirect chain ≤ 1 хоп, loop = P0, политика 404/410/301
- Правило 1.14: SSR release gate (автоматический тест перед деплоем)
- Правила 2.11–2.14: каталог типов страниц, система перелинковки, политика каннибализации, redirect map
- Правило 3.17: near-duplicate политика (консолидация / noindex)
- Блок 9: Индексируемость — разделение noindex/robots/canonical/redirect; redirect chains; 404 vs 410
- Блок 10: Параметры, фасеты, пагинация
- Блок 11: Миграции — pre-flight checklist, redirect map стандарт, post-release мониторинг
- Блок 12: Мониторинг и наблюдаемость — GSC daily, P0/P1 SLA, 72h window, диагностика падений
- Приложение: Pre-release / Post-release / Monthly чек-листы

### Changed
- 1.2: softened «canonical = директива» → «canonical = сильная подсказка; при конфликте сигналов Google выбирает сам»
- 1.3: softened «CSR = невидимость» → «CSR = риск задержки рендера и нестабильной индексации»; добавлен список SEO-критичных элементов для SSR-гейта
- 1.6: расширен до стандарта качества sitemap (только 200-OK, реальный lastmod, разделение по типам)
- 1.7: добавлено разъяснение «robots.txt не удаляет из индекса»
- 1.8: добавлена методология измерения CWV (field vs lab, mobile-first, anti-паттерны)
- 1.10: добавлена политика допуска 3rd-party скриптов (MUST/SHOULD/NICE классы)
- 2.1: заменён абсолютный «≤3 клика» на управляемую метрику «< 2% SEO-ценных страниц глубже 3»
- 2.3: расширена URL-политика (нелатиница, технические хвосты)
- 2.5: softened «canonical консистентен» → «canonical = подсказка; все 4 сигнала должны совпадать»

## [2.1.0] — 2026-05-15
### Added
- Блок 8: Масштаб и управление качеством (12 правил)

## [2.0.0] — 2026-04-17
### Added
- Все 7 блоков: Техническая основа, Архитектура, Контент, UX, Ссылочный, Инсайдерские нюансы, Управление файлом
- 55 правил + блок 7 (SEMVER)
```

---

## 8. Масштаб и управление качеством

### 8.1 «N страниц в неделю» = N системных изменений, а не N URL вручную
**[MUST · SEO]** На сайтах 10K+ страниц единица работы — шаблон, правило, класс страниц, гейт. Одна ошибка в шаблоне тиражируется на тысячи URL.

### 8.2 Ручное участие — только в четырёх функциях
**[MUST · SEO]** (1) владелец SEO-качества, (2) владелец контент-шаблонов, (3) владелец локализации, (4) аналитика и мониторинг. Функции можно совмещать; полностью убрать — нельзя.

### 8.3 Pipeline routing: каждая страница проходит через три исхода
**[MUST · Dev/SEO]** **Auto publish** (прошла гейты, низкий риск) → **Hold** (не прошла блокирующий гейт) → **Human review** (техника ок, но новый шаблон / новый язык / money page / аномальный score). Без явного routing «опубликовать всё» — это отсутствие контроля, не скорость.

### 8.4 Blast radius: ограничить выход нового паттерна за сутки
**[MUST · Dev]** Новый шаблон или логика генерации — не более сотен URL за первый выход. Защита от тиражирования слабого паттерна до обнаружения SpamBrain.

### 8.5 Канареечный релиз для каждого нового шаблона, языка или логики
**[MUST · Dev/SEO]** (1) малая выборка URL → (2) 1–2 языка → (3) мониторинг 7–14 дней → (4) расширение. SpamBrain оценивает паттерн до анонса — к моменту «измерили падение» санкция уже применена.

### 8.6 Каждый page type должен иметь задокументированный «Unit of Value»
**[MUST · SEO/Content]** Проверка: правило 2.11 (матрица типов) → для каждого типа описан уникальный слой ценности. Симптом: «Crawled, currently not indexed» эпидемия на программатических страницах.

Уникальные данные / уникальная интерпретация / уникальные FAQ / уникальные следующие шаги — хотя бы один слой обязателен.

### 8.7 Doorway red flags — операционный чеклист (2+ совпали = высокий риск)
**[MUST · SEO/Dev]** Проверка: `scripts/cross_page_similarity.py` + template ratio. Симптом: массовые «Crawled, not indexed» или ручной Manual Action «Doorway pages».

Красные флаги: 80–90% одинакового контента в кластере / нет реального интента и спроса / все страницы ведут в одну конверсию / страницы только в sitemap без органической перелинковки.

### 8.8 Multilingual Gate — блокирующий, срабатывает до индексации
**[MUST · Dev/Data]** Не прошёл → страница публикуется с `noindex` до исправления. Минимальный набор: hreflang-граф (самоссылка + взаимность) / canonical sanity / language detection / anti-fake translation / near-duplicate проверка.

### 8.9 Политика hold-first: новые шаблоны и языки = noindex по умолчанию
**[MUST · Dev/SEO]** Новая локаль или шаблон → staging-режим (публикация есть, индекс нет) до прохождения Gate + LQA. Переход в индекс — явное действие, не дефолт.

### 8.10 Pre-publish checks — минимальный блокирующий набор (4 группы)
**[MUST · Dev/SEO]** Проверка: `scripts/checklist.py` + `scripts/schema_validator.py` + `scripts/hreflang_audit.py`.

A. Duplicate/doorway-risk: near-duplicate внутри кластера; шаблонный текст vs уникальные данные; cannibalization.
B. Multilingual integrity: language detection; hreflang-граф; canonical sanity.
C. Value & consistency: прямой ответ на интент; entity consistency с источником; trust-блоки.
D. Indexability: статус-коды, noindex/canonical/robots, sitemap inclusion, orphan-статус.

### 8.11 Матрица page types × риск — основа для приоритизации ручного контроля
**[MUST · SEO]** Для каждого типа: уровень риска (высокий/средний/низкий) × масштаб × цена ошибки. Высокий риск = human review. Средний = канарейка. Низкий = auto publish при прохождении гейтов.

### 8.12 При «not indexed эпидемии» — найти 1–3 доминирующих паттерна, не лечить поштучно
**[MUST · SEO]** Порядок: (1) сегментация по языкам/директориям/типам → (2) технические первопричины → (3) pruning → (4) усиление Unit of Value на шаблоне → (5) канареечный rollout → мониторинг.

---

## 9. Индексируемость: noindex / robots / canonical / redirect

### 9.1 Четыре инструмента — четыре разные функции: разделить и не смешивать
**[MUST · Dev/SEO]** Проверка: в wiki/CLAUDE.md есть таблица «когда что применять». Симптом: «canonical на noindex», «disallow для удаления из индекса», «301 вместо noindex» — типичные конфликты, дающие непредсказуемые результаты в GSC.

| Инструмент | Что делает | Чего НЕ делает |
|---|---|---|
| `robots.txt Disallow` | Запрещает **сканирование** | Не удаляет из индекса; не блокирует ссылочные сигналы |
| `noindex` (meta / X-Robots) | Исключает из **индекса** | Не запрещает сканирование; не передаёт вес |
| `canonical` | Указывает **предпочтительный URL** | Не гарантия; может быть проигнорирован при конфликте сигналов |
| `301 redirect` | Передаёт **PageRank** на новый URL | Не удаляет из индекса мгновенно; создаёт hop |

**Правило консистентности:** canonical ↔ hreflang ↔ sitemap ↔ internal links — все четыре указывают на один URL. Любой конфликт снижает доверие Google к canonical.

### 9.2 Иерархия инструментов: когда что применять
**[MUST · Dev]** Проверка: URL Inspection → не индексируется + причина чёткая из инструментов. Симптом: «мусорные» URL появляются в GSC Coverage.

| Сценарий | Инструмент | Обоснование |
|---|---|---|
| URL никогда не должен быть в индексе (корзина, аккаунт, поиск) | `noindex` + `Disallow` | Нет смысла краулить и индексировать |
| URL временно не должен (staging, preview) | `noindex` (без Disallow) | Crawl нужен для проверки, индекс нет |
| URL удалён навсегда (ROT, некачественный контент) | `410 Gone` | Быстрая очистка из индекса |
| URL переехал (реорганизация, slug изменён) | `301 → new URL` | PageRank передаётся |
| URL дубль (параметры, сессии) | `canonical → primary` + `noindex, follow` | Сигналы агрегируются на primary |
| URL временно недоступен | `404` | Googlebot подождёт и проверит снова |

**Запрет:** `canonical` указывает на `noindex`-страницу — Google не знает, что делать (индексировать? нет?); результат непредсказуем.

### 9.3 Redirect chains запрещены: максимум 1 хоп до финального URL
**[MUST · Dev]** Проверка: `curl -L -I <URL>` → не более одного 3xx. Batch: `for url in $(cat top_urls.txt); do hops=$(curl -sL -o /dev/null -w "%{num_redirects}" "$url"); [ "$hops" -gt 1 ] && echo "CHAIN: $url ($hops hops)"; done`. Симптом: «Page with redirect» в GSC Coverage; ослабление PageRank.

Каждый дополнительный хоп ослабляет передачу PageRank. При обнаружении цепочки A→B→C: обновить redirect A напрямую на C.

### 9.4 Redirect loop = P0 инцидент: мгновенная блокировка
**[MUST · Dev]** Проверка: `curl -L --max-redirs 5 <URL>` → завершается финальным URL, не ошибкой «Too many redirects». Симптом: URL полностью выпадает из индекса; краулер прекращает обход.

Fix — немедленный, приоритет P0, независимо от трафика страницы. После fix — переотправить URL через Indexing API.

### 9.5 Политика 404 vs 410 vs 301 для удалённых страниц
**[SHOULD · Dev/SEO]** Проверка: список удалённых страниц за последние 6 мес → каждая имеет явный статус-код. Симптом: «Not found» страницы продолжают потреблять crawl budget месяцами.

| Сценарий | Код |
|---|---|
| Удалена навсегда (ROT, некачественный контент) | **410 Gone** |
| Временно недоступна (тех. ошибка) | **404** |
| Переехала на другой URL | **301 → target** |
| Объединена с другой страницей | **301 → primary** |
| Категория расформирована | **301 → parent category** (не на главную) |

### 9.6 Ежемесячный аудит «индексационного мусора»
**[SHOULD · SEO]** Проверка: GSC → Coverage → «Excluded» → аномальный рост «Crawled, currently not indexed» или «Discovered, currently not indexed». ОК, если: рост Excluded коррелирует с плановым noindex, а не с непредвиденными выпадениями.

Раз в месяц: топ-100 Excluded URL → классифицировать (намеренное / непредвиденное) → исправлять непредвиденные.

---

## 10. Параметры, фасеты, пагинация

### 10.1 Tracking-параметры не должны создавать уникальных URL для Googlebot
**[MUST · Dev/Infra]** Проверка: `curl -I "https://example.com/page?utm_source=google"` → либо `robots.txt Disallow: /*?utm_`, либо canonical без параметра. Симптом: сотни/тысячи дублей UTM-страниц в GSC Coverage.

Canonical на страницу без UTM + `Disallow: /*?utm_` в robots.txt. Аналогично для `?ref=`, `?currency=`, `?distance=`, `?preview=true`.

### 10.2 Стратегия для filter/facet параметров: явное решение для каждого типа
**[MUST · Dev/SEO]** Проверка: каждый тип параметра имеет зафиксированную стратегию. Симптом: Coverage раздувается на комбинациях фильтров; crawl budget уходит на бесполезные URL.

| Тип параметра | Реальный спрос? | Стратегия |
|---|---|---|
| Фильтр с реальным спросом (brand+color) | Да | index + canonical self + sitemap |
| Фильтр без спроса (size=XL) | Нет | `noindex, follow` + canonical → base |
| Сортировка (sort=price) | Нет | `noindex, follow` + canonical → base |
| Tracking (utm, ref) | Нет | `Disallow` в robots.txt + canonical |
| Пагинация (page=2) | Частично | self-canonical каждой; не в sitemap |
| A/B вариант (?variant=b) | Нет | `noindex` или canonical → control |

### 10.3 Пагинация: canonical self на каждой; «page 1 с параметром» = дубль
**[MUST · Dev]** Проверка: `curl "https://example.com/articles?page=1"` и `curl "https://example.com/articles"` → один из вариантов 301 → другому. Симптом: два URL конкурируют за одну позицию.

**Стандарт:**
- Page 1 с `?page=1` и без параметра → один 301 → другой (победившая форма — canonical self)
- Страницы 2+ → self-canonical; **не canonical на page 1** (иначе выпадают из индекса)
- Страницы 2+ → **не в sitemap**
- Бесконечная прокрутка: нужны статические URL `/articles?page=2` для краулера

### 10.4 Параметризованные URL не генерировать как `<a href>` если noindex
**[MUST · Dev]** Проверка: `grep -r "?color=\|?size=\|?sort=" templates/` → все такие URL через `<button>` / JS, не `<a href>`. Симптом: Screaming Frog обнаруживает тысячи параметризованных URL через crawl ссылок, несмотря на noindex.

Если URL noindex — не нужно чтобы краулер его «находил» через HTML-ссылки. Используй JS-навигацию или `<button>` для client-side фильтрации.

### 10.5 Бесконечная лента: статическая альтернатива для Googlebot
**[SHOULD · Dev]** Проверка: Screaming Frog → JS crawl → весь контент «ленты» доступен через статические URL. Симптом: контент ниже первого экрана не индексируется.

Для Googlebot нужна статическая альтернатива: либо `/articles?page=2`, либо `<noscript>` блок с прямыми ссылками.

### 10.6 Мониторинг параметрного «взрыва» после деплоя
**[SHOULD · SEO]** Проверка: GSC → Coverage → аномальный рост «Crawled, currently not indexed» → анализ примеров URL на наличие параметров. Симптом: 10K+ новых Excluded URL за неделю без изменений в контенте.

Если деплой создал параметрный взрыв → откат или немедленное добавление `noindex` / `Disallow`.

---

## 11. Миграции: переезды, редизайны, массовые правки URL

### 11.1 Pre-migration checklist: обязателен до любого деплоя с изменением URL
**[MUST · Dev/SEO]** Проверка: наличие заполненного migration doc перед мёржем. Симптом: трафик исчезает после «безобидного» редизайна.

```
□ 1:1 redirect map готов (старый URL → новый URL, status_code, reason)
□ Redirect chains проверены: A→B (не A→B→C); curl -L -I по всем новым URL
□ Redirect loops проверены: curl -L --max-redirs 5 по всем
□ Sitemap обновлён на новые URL
□ Internal links обновлены на новые URL (или redirects достаточны)
□ Canonical теги обновлены
□ hreflang матрица обновлена
□ robots.txt не блокирует новые пути
□ Schema JSON-LD обновлены (mainEntityOfPage, URL)
□ GSC: оба URL (старый + новый) отслеживаются
□ Staging smoke test: топ-50 URL проверены вручную
```

### 11.2 Redirect map: формат и правила хранения
**[MUST · Dev]** Симптом: 404 spike после деплоя без объяснений.

**Требования к redirect map:**
- Формат: CSV/JSON `{source_url, target_url, status_code, reason}`
- Нет дублей `source_url`; нет `target_url` который сам редиректит (chain)
- `target_url` проверен на 200 OK на staging перед деплоем
- Хранится в git с историей изменений (не в spreadsheet без версионирования)
- `status_code`: 301 (постоянный) / 302 (только если URL вернётся — A/B, сезонная акция)

### 11.3 Post-release monitoring: 72 часа после деплоя
**[MUST · Dev/SEO]** Проверка: дашборд активен и просматривается непрерывно первые 72h. Симптом: поздно обнаруженная ошибка = потерянные недели индексации.

| Временной слот | Что смотреть | Инструмент |
|---|---|---|
| Часы 0–4 | 5xx rate, 404 spike, redirect chains в server logs | Server logs / UptimeRobot |
| День 1 (12–24h) | GSC Coverage delta — новые «Page with redirect», «Not found» | GSC Coverage |
| Дни 2–3 | Organic traffic WoW, топ-50 URL spot-checks, canonical consistency | GSC Performance + URL Inspection |

### 11.4 Стадированная миграция для крупных изменений (> 10K URL)
**[SHOULD · Dev/SEO]** При масштабном изменении URL-структуры мигрировать порциями:
1. 1–5% URL → мониторинг 7 дней
2. 20–30% URL → мониторинг 7 дней
3. Остальные → мониторинг

Позволяет обнаружить системные ошибки до масштабирования.

### 11.5 Обновление топ-20 внешних доноров ссылок
**[NICE · SEO]** После крупной миграции: уведомить топ-20 внешних доноров о смене URL. Уменьшает redirect hops для значимого PageRank.

### 11.6 Post-migration SEO audit через 30 дней
**[SHOULD · SEO]** Через 30 дней:
- GSC Coverage: indexed count vs pre-migration baseline (без просадки > 5%)
- GSC Performance: клики/impressions без резкого падения
- Топ-100 URL: позиции ± 3 от pre-migration
- При просадке > 20%: `scripts/penalty_triage.py`

---

## 12. Мониторинг и наблюдаемость

### 12.1 GSC: ежедневный минимум и еженедельный расширенный
**[MUST · SEO]** Проверка: дашборд просматривается ежедневно; `scripts/gsc_coverage.py --alert-threshold 500` работает в cron. Симптом: проблема обнаружена через 2–3 недели вместо 24 часов.

**Ежедневный минимум:**
- Manual Actions → пусто? (появление = P0 немедленно)
- Coverage: «Crawled, currently not indexed» delta → alert если > 500 новых
- Security Issues → пусто?
- Performance: clicks WoW → alert если падение > 20%

**Еженедельный расширенный:**
- Enhancement reports (Article / BreadcrumbList / Product) → 0 errors
  *(FAQPage Enhancement report удалён Google в июне 2026 — больше не отслеживать)*
- Core Web Vitals → mobile «Poor» < 5%
- Coverage: все сегменты с аномальным ростом
- Top queries: новые появления и выпадения из топ-20

### 12.2 Server-side SLA: P0 и P1 инциденты
**[MUST · Infra]** SLA определены и автоматизированы:

| Тип | Триггер | Время реакции |
|---|---|---|
| **P0** | 5xx > 1% / Redirect loop / Manual Action / robots.txt блокирует всё / Organic traffic –50% за 1 час | 15 мин |
| **P1** | TTFB p95 > 1500ms / 404 spike > 2× baseline / CWV «Poor» > 10% нового шаблона | 1–4 часа |
| **P2** | «Crawled, not indexed» > 500 новых/день / AVI tier «<70» > 30% статей | 24–48 часов |

### 12.3 Post-release monitoring window: 72 часа
**[MUST · Dev/SEO]** Каждый деплой, затрагивающий шаблоны или роутинг, = 72h monitoring window. Детали — правило 11.3.

### 12.4 Диагностика «необъяснимого» падения трафика
**[MUST · SEO]** Проверка: `scripts/penalty_triage.py --domain <domain>` → full diagnostic. Симптом: трафик упал без Manual Action и без технического инцидента.

**Декционное дерево:**
1. GSC Manual Actions → есть? → `topics/22-recovery-penalties.md`
2. Timeline совпадает с Google update? → `topics/23-algorithm-updates-log.md`
3. `scripts/trailing_slash_audit.py` → canonical/sitemap/served mismatch?
4. `scripts/fake_translation_detector.py` → fake translations?
5. GSC Coverage → аномальный рост «Crawled, not indexed»?
6. `scripts/cross_page_similarity.py` → scaled content abuse risk?
7. TTFB / 5xx / CWV деградация?

### 12.5 Crawl log analysis: ежемесячный аудит crawl budget
**[NICE · Infra/SEO]** Анализ nginx access logs для Googlebot:
- Сколько URL/день сканирует? Распределение по типам страниц
- Нет ли «мусорных» URL с высокой crawl frequency (параметры, фильтры)
- Response time distribution для Googlebot (p50/p95)

Аномалия: Googlebot тратит > 20% crawl budget на параметризованные URL или 404-страницы.

---

## Приложение: Чек-листы

### A. Pre-release (деплой шаблонов, роутинга, структуры)

```
TECHNICAL FOUNDATION
□ trailing-slash: canonical = sitemap = served = internal links — один формат
□ SSR gate: curl → title, H1, canonical, JSON-LD, внутренние ссылки в HTML
□ noindex: нет accidental noindex на продакшен-страницах (grep "$" templates/)
□ canonical: все canonical → 200 OK без redirect (batch curl check)
□ robots.txt: Googlebot + Googlebot-Extended Allow: /; UTM/tracking Disallow
□ redirect chains: max 1 hop (curl -L -I на изменённых URL)
□ redirect loops: нет (curl -L --max-redirs 5)
□ schema: Rich Results Test → 0 errors на изменённых шаблонах
□ 3rd-party scripts: новые скрипты — Lighthouse before/after

ARCHITECTURE
□ page types matrix обновлена при добавлении нового типа
□ перелинковка: новые страницы получают ссылки из ≥ 2 шаблонных модулей
□ pagination: self-canonical на каждой; page 1 без дублей
□ параметры: noindex/Disallow/canonical стратегии зафиксированы
□ migration doc: redirect map готов если меняются URL

CONTENT (при деплое новых шаблонов контента)
□ Author Bio присутствует и верифицируем
□ no fake aggregateRating (без реальных отзывов)
□ no TIER-1 AI words (scripts/ai_detector.py)
□ publishing velocity ≤ 5 URL/день на домен
```

### B. Post-release (первые 72 часа)

```
Часы 0–4:
□ 5xx rate < 0.5% в server logs
□ 404 rate не более чем 2× baseline
□ redirect loops: нет (UptimeRobot / curl)
□ robots.txt не сломан (curl https://domain/robots.txt)

День 1 (12–24h):
□ GSC Coverage: нет аномального роста «Page with redirect» или «Not found»
□ URL Inspection spot-check: 5–10 ключевых URL → canonical и индексация ОК

Дни 2–3:
□ GSC Performance: clicks/impressions без резкого падения WoW
□ Core Web Vitals: нет регрессии по изменённым шаблонам
□ Manual Actions report: пусто
```

### C. Monthly SEO audit

```
INDEXATION
□ GSC Coverage: indexed count vs прошлый месяц (без падения > 5%)
□ Top-100 Excluded URL: классифицировать намеренные vs непредвиденные
□ «Crawled, not indexed»: нет аномалий, связанных с параметрами
□ Sitemap: нет редиректов / 404 / noindex URL

CONTENT QUALITY
□ scripts/avi_calculator.py --all → доля AVI < 70 ≤ 30%
□ «Жёлтая зона» (позиции 8–25, impressions > 500): refresh top-20
□ ROT pages (0 impressions 6+ мес, нет backlinks): 410 или 301 → related
□ Near-duplicate audit: scripts/cross_page_similarity.py → новые флаги

TECHNICAL
□ Trailing-slash audit: scripts/trailing_slash_audit.py → 0 mismatches
□ Hreflang audit: scripts/hreflang_audit.py → 0 missing back-references
□ Fake translation audit: scripts/fake_translation_detector.py → 0 fakes
□ Manual Actions check: scripts/manual_action_scanner.py → пусто
□ Backlink delta: spammy TLDs → disavow candidates (только при Manual Action)

PERFORMANCE
□ CWV: mobile field data — «Poor» < 5% (GSC)
□ TTFB: p95 < 600ms (server logs)
□ nginx cache hit rate > 80%

CONTENT PIPELINE
□ Publishing velocity: ≤ 5 URL/день соблюдается
□ isReal field: SELECT COUNT(*) FROM translations WHERE is_real IS NULL = 0
□ L1 RAG enrichments: ≥ 3/неделю (self-learning healthy)
□ Pass rate checklist: ≥ 70% (scripts/pipeline_health.py)
```

---

*Документ создан на основе production-опыта: key-g.com, blog.getrentacar.com, blog.gettransfer.com, GetModel.com. Все инциденты — реальные, все числа — проверены.*
