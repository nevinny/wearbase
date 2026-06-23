# Ahrefs — что есть полезного и как читать аудит

Снимок на **2026-06-22**. Что Ahrefs показывает по WEARBASE, как не попасться на ложные
тревоги (как было с «tailwind 404»), приоритеты по найденным проблемам и какие ещё
инструменты Ahrefs стоит использовать.

> Связанные: [seo_rules.md](seo_rules.md) (канон правил), [seo_tools.md](seo_tools.md)
> (обзор инструментов), [marketing_seo.md](marketing_seo.md) (SEO-канал).

## Доступ

- Аккаунт: `Alesha Popovich` (логин делает пользователь вручную в браузере).
- Site Audit проект **Wearbase**, id `9996432` → `https://app.ahrefs.com/site-audit/9996432/overview`.
- Краулится `wearbase.ru` целиком (~4 500 внутренних URL), есть always-on аудит (Basic),
  плановый рекраул раз в неделю. Можно форсировать кнопкой **New crawl**.
- Кроме Wearbase в аккаунте есть проекты coffeeproservice / li-catalog / minerdash.

## Как читать (чтобы не путать Notice и Error)

Ahrefs делит проблемы на **Errors (красный)** / **Warnings (жёлтый)** / **Notices (синий)**.
Цифра рядом — сколько URL затронуто, **это не «потери», а охват**. Типичная ошибка чтения:
принять Notice за критичную ошибку.

⚠️ **Кейс «404 на tailwind css/js» (2026-06-22) — ложная тревога.** В отчётах CSS/JavaScript
было `Broken = 0`. То, что выглядело как 404, — два **Notice**: «Page has redirected
JavaScript» (3 533) и «JavaScript redirects» (2). Причина: `cdn.tailwindcss.com/?plugins=…`
отдаёт **302** на версионированный URL — нормальное поведение play-CDN, на SEO не влияет.
CSS-ресурсов у нас нет вообще (Tailwind грузится как JS с CDN). **Вывод: сначала смотреть
тип (Error/Notice) и колонку Broken, а не только название.**

## Инвентарь проблем аудита (снимок 2026-06-22)

Health score 81%. Issues: 1 828 errors / 12 091 warnings / 12 046 notices.

### Errors (красные) — приоритет

| Issue | URL | Что это / план |
|---|---|---|
| **Canonical points to 4XX** | 1 702 | ✅ **Исправлено 2026-06-22.** Все `/catalog*` (200) ссылались canonical'ом на `/ru/catalog` (404): роут `catalog_index` без префикса локали, а `base.html` строит canonical как `/ru/{path}`. Фикс: self-canonical → `/catalog` + `noindex,follow` (каталог пуст). Отвалится после рекраула. |
| **404 page** | 31 | Реально битые страницы. Помимо `/ru/catalog` (старый canonical) — страницы брендов: `/ru/brands/{slug}` для `tatyana-parfionova, clanvi, brightest, concept-club, shi-shi, dede, ascentelle` и др. Похоже на dead/удалённые бренды, на которые остались внутренние ссылки/записи в sitemap. **Корень искать в статусе бренда (dead) + откуда ссылки.** |
| **4XX page** | 31 | То же множество, что 404. |
| **Page has links to broken page** | 55 | Страницы со ссылками на 404. Всего **5 510 ссылок ведут на 404-страницы** (львиная доля — старый canonical `/ru/catalog`; уйдёт с фиксом каталога, остаток — ссылки на dead-бренды). |
| **Image file size too large** | 8 | Тяжёлые картинки — пережать. |
| **Canonical URL has no incoming internal links** | 1 | Canonical-цель без внутренних ссылок. |

### Warnings/Notices — по убыванию полезности

| Issue | URL | Комментарий |
|---|---|---|
| Missing alt text | 3 533 | Картинки без `alt`. Image-SEO + доступность. Логотипы брендов уже с alt — проверить товары/контент. |
| Structured data has schema.org validation error | 274 | Ошибки валидации JSON-LD — может резать rich-результаты. Проверить, какие типы. |
| Meta description too short | 1 711 (+ 9 indexable) | В основном не-индексируемые. Для индексируемых — дотянуть. |
| Slow page | 124 | TTFB/загрузка. |
| Title too long | 63 / Meta description too long | 47 | По длине — `app:seo:meta-repair` это ловит. |
| External 4XX | 52 | Битые **исходящие** ссылки (на сайты брендов и т.п.) — починить/убрать. |
| More than three parameters in URL | 1 668 | Фасеты каталога. После `noindex` каталога не критично. |
| Page in multiple sitemaps | 540 | Дубли в sitemap — проверить генерацию. |
| Noindex page | 2 359 | **В основном осознанно**: не-ru локали (`noindex,follow` by-design) + теперь каталог. |
| Open Graph / X card incomplete | 9 / 7 | OG/Twitter-теги на части страниц. |
| Pages to submit to IndexNow | 546 | Можно пушить в IndexNow для ускорения переобхода. |

## Другие инструменты Ahrefs (помимо Site Audit) — что брать для WEARBASE

| Инструмент | Зачем нам |
|---|---|
| **Site Explorer** | Профиль бэклинков (кто ссылается), органические ключи и страницы-доноры трафика, история. Смотреть свой домен и конкурентов (vitrine.market и др. из [competitors_seo_audit.md](competitors_seo_audit.md)). |
| **Keywords Explorer** | Объём/сложность/intent ключей. Дополняет Yandex Wordstat (который уже в RAG-пайплайне) данными по Google. Для кластеров «уйти с маркетплейса», «бренд + город», «бренд одежды {стиль}». |
| **Content Explorer** | Поиск популярного контента по теме — идеи для блога/лендингов, что «выстреливает» у конкурентов. |
| **Rank Tracker** | Отслеживание позиций по целевым запросам во времени (по регионам РФ). |
| **Site Audit → Page explorer / Structure explorer** | Срез по любым полям (глубина, статус, PR, размер) — для точечной диагностики. |
| **Internal link opportunities** | Подсказки, откуда куда поставить внутренние ссылки (anchor-suggest) — усилить важные хабы. |
| **GSC Insights** | Связка с Google Search Console (у нас GSC синкается на Mac/.43, на проде пусто — см. [production.md](production.md)). |
| **Bulk export / Looker Studio / API** | Выгрузка проблем для регулярного контроля. |

## Приоритетные действия (по убыванию ROI)

1. ✅ **Каталог: canonical→404 + noindex** — сделано (деплой 2026-06-22).
2. **Битые страницы брендов (31×404)** — найти источник: dead-бренды всё ещё в sitemap/внутренних
   ссылках. Либо 301 на актуальную страницу/каталог, либо убрать ссылки + из sitemap. Снимет и
   «links to broken page».
3. **Structured data errors (274)** — проверить, какие типы валятся (Product/Organization/Breadcrumb),
   починить шаблоны JSON-LD.
4. **External 4XX (52)** — почистить битые исходящие ссылки на сайты брендов.
5. **Картинки** — пережать тяжёлые (8), добить `alt` где осмысленно.

## На будущее (мультиязычность)

Когда появятся товары и страновые бренды: завести `/{_locale}/catalog` + 301 с легаси `/catalog`,
снять общий `noindex` (вернуть условный — только фасеты/пагинация), добавить каталог в sitemap.
Тот же латентный баг canonical→`/ru/product/...` (404) на `/product/{uuid}` чинится в том же заходе.
