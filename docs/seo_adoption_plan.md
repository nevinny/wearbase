# SEO: что берём из пакета `_seo` (CLOSEDLOOP-SEO-FULL + SEO 4.9)

**Дата анализа:** 2026-06-12
**Контекст:** сверка доктрины пакета с фактическим состоянием GSC.

---

## Состояние GSC на 2026-06-12

- Синк живой: Search Analytics по 2026-06-10 (лаг GSC 2–3 дня — норма), URL Inspection — 2026-06-12 13:01.
- **В индексе 23 из 438 активных брендов (5.3%).**

| Coverage state | Брендов |
|---|---|
| URL is unknown to Google | 219 |
| Discovered – currently not indexed | 142 |
| Crawled – currently not indexed | 53 |
| Submitted and indexed | 23 |
| Duplicate (другой canonical) | 1 |

- Трафик: показы 40→90/день (3–10 июня), клики 0–2/день, средняя позиция ~15–19.
- Топ по показам: gate31 (поз. 14), neverlate (10.6), wahhid (8.0, 2 клика).
- ⚠️ Дубль-хост: 59 строк статистики с `www.wearbase.ru`, часть страниц ранжируется под www.
- Дрип-публикация фактически не раскатилась: `published_at` стоит у 1 бренда (2026-06-04); 5789 брендов в `status='new'` ждут пайплайн.

## Что в пакете

- `docs/LESSONS_FROM_HISTORY.md` — дистиллят 8 боевых сессий на 7 сайтах: пороги ramp, грабли, доктрина value-not-volume. Читать первым.
- `engine/closedloop-seo` — python-движок замкнутого цикла (GSC-аудит → план → генерация → 9-стадийный гейт → деплой → трекинг) с ramp по живой индексации.
- `engine/self-writer/seoloop/indexer.py` — anti-trifecta индексация: IndexNow + ровно один Google-канал.
- `engine/article-qa-toolkit` — QA-гейт после LLM: SB/RV/HL/AVI скоринг, near-dup.
- `docs/SEO_Guide_4.9.md` — мастер-методичка (30 тем), `knowledge/` — первоисточники.

## План внедрения (в порядке отдачи)

### 1. Фикс hreflang/canonical + 301 www→apex — ✅ сделано 2026-06-12 (ждёт деплоя)

Мы воспроизводим их «эпидемию fake translations» (правило 6.5 / hreflang_gate G4.5):
`brand_translation` и `product_translation` **пустые (0 строк)**, а `tailwind/base.html.twig:8-17`
объявляет hreflang на 9 локалей — все отдают один русский текст с self-canonical.
Это паттерн «crawled, currently not indexed растёт без Manual Action».

Баги в самом блоке hreflang:
- `path_without_locale` срезает только `/ru` и `/en` → для zh/ar/tr/de/fr/es/ko hreflang генерит битые URL вида `/en/zh/brands/...`;
- replace по подстроке портит слаги, начинающиеся на `ru`/`en` (`/ru/brands/rubchik` → `/brands/bchik`);
- hreflang и canonical строятся от `app.request.schemeAndHttpHost` → на www-хосте страница объявляет canonical/альтернативы на www (кормит дубль-хост).

Действия:
- [x] hreflang только ru + x-default (base.html.twig); canonical не-ru → ru-версия (дедуп);
- [x] canonical/hreflang/sitemap-loc от `SITE_BASE_URL` (twig-глобал `site_base_url`),
  не от request; фикс substring-бага `path_without_locale`; canonical главной `/ru/`
  (консистентность со слешем sitemap); 11 дублирующих canonical-оверрайдов удалены;
- [x] 301 www→apex в `public_html/.htaccess` (проверить после деплоя: SSL на www);
- [⏸] **`is_real` — отложено по дизайну (не делать сейчас).** Условие из доктрины 6.5/6.9
  ещё не наступило: `brand_translation`/`product_translation` пусты,
  `BrandTranslationRepository::upsert` никто не вызывает, hreflang отдаёт только
  `ru`+`x-default` (переводы в него не входят), авто-перевода (источник fake-translation)
  нет. Колонка была бы мёртвой схемой без потребителя. **Когда появится пайплайн
  переводов** (авто-генерация brand/product translation + многоязычный hreflang):
  (1) миграция `is_real TINYINT(1)` в обе translation-таблицы; (2) писать `is_real`
  при upsert перевода (true=человек/проверено, false=сырой автоперевод);
  (3) `TranslationExtension`/hreflang отдавать локаль только при `is_real=1`;
  (4) `fake_translation_detector` перед публикацией.

### 2. Перелинковка («латентные победы > новый контент») — ✅ жёсткий граф (2026-06-12)

Кейс пакета: un-noindex + перелинковка → индекс 374→852 без новых страниц.
У нас было 219 брендов «URL is unknown to Google» — Google их не находил.

**Сделано — жёсткий граф перелинковки** (вместо динамического `findSimilarBrands`
с `ORDER BY created_at`, состав которого плыл при каждой публикации):

- таблица `brand_related` (brand_id, related_brand_id, position 1–5, source) —
  рёбра фиксированы, не пересчитываются на запрос;
- `app:brand:build-link-graph` — построение: средний вектор чанков бренда из Qdrant →
  глобальный поиск → топ-5 похожих (source=embedding), fallback стили→город→fill;
  повторный запуск НЕ трогает существующие рёбра (только добивка/ремонт);
- `BrandLinkGraphService` — чистый SQL (работает на проде без Qdrant):
  `weave()` вшивает бренд в граф, `ensureIncoming()` гарантирует **in-degree ≥ 2**
  (вытесняя только слабые fill/city-рёбра, чей target не осиротеет),
  `replaceDeadEdges()` чинит рёбра на скрытые бренды;
- блок «Похожие бренды» на карточке читает граф (fallback на динамику, пока граф пуст);
- **publish-tick**: новый бренд при публикации сразу вплетается в граф
  (исходящие + входящие — страница не рождается сиротой), fail-open.

**Результат первого построения:** 2190 рёбер (438×5), embedding 972 / city 688 /
fill 494 / style 36, **сирот 0** (min in-degree 2, max 19).

Норматив пакета: 5–15 внутренних ссылок на 1000 слов (мин. 5 в тексте);
«20–40» в пакете НЕТ. Карточка бренда: 5 рёбер графа + крошки + hub — в норме.

- [x] OUT_DEGREE 5 → 12 (кратно сетке 2/3/4 колонок); локально 5256 рёбер (438×12);
- [x] рёбра в payload агент-пуша: `related: [{slug, position, source}]`, приёмник
  delete-and-replace, неизвестные slug'и скипает (`BrandIngestService::replaceRelated`);
- [x] граф построен на проде (2026-06-12, SQL-fallback без Qdrant): 4440 рёбер
  (370 активных × 12), city 2879 / fill 1471 / style 90, сирот 0; embedding-рёбра
  доедут с ре-пушами брендов;
- [x] Obsidian-визуализация: `../wearbase-graph-obsidian/` (вне репо) — 438 нод,
  рёбра wikilinks, теги #hub/#popular/#normal по in-degree.

- [x] **hub-топология карточка↔город (2026-06-13):** city-хаб `brand_city/{slug}` уже
  листил ВСЕ активные бренды города (входящая ссылка на каждую карточку); добавлена
  реципрокная ссылка карточка→свой city-хаб в хлебные крошки (видимые + BreadcrumbList
  JSON-LD, позиция города 3, бренд 4; без города — прежние 1-3). Делает дедицированный
  city-хаб достижимым с каждой карточки (модуль «родительская категория», правило 2.12)
  — вторая шаблонная точка входа поверх графа brand_related.
  Дедицированных category/style-хабов как страниц нет (только фильтры `brand_index?style=`)
  — отдельная фича, в этот объём не входит.

### 3. IndexNow + один Google-канал — ✅ сделано 2026-06-12

- [x] IndexNow уже был (`IndexNowPinger`, пинг из publish-tick) — Яндекс/Bing закрыты;
- [x] Google Indexing API: `GoogleIndexingClient` (SA из GSC_CREDENTIALS_PATH, scope
  indexing) + `app:google:index-ping` — cap 180/день (потолок 200), cooldown 14 дней
  (`google_index_ping`), приоритет свежим публикациям, стоп на 429/403, --dry-run.
  Креды только на Mac → cron `0 7 * * *` с Mac (после app:gsc:sync);
- [x] anti-trifecta соблюдена: Google — ровно один канал (Indexing API), IndexNow
  Google не трогает.

### 4. Пороги ramp в publish-tick — ✅ сделано 2026-06-13

Их index-guards жёстче нашего drip-health (×0.25/×0.5):
- **indexed-ratio <5% → 0 новых; <10% → cap 1/день**.

- [x] `PublishTickCommand::indexHealthCap()` — жёсткий ПОТОЛОК дрипа по общей доле
  индексации домена (доля active в индексе среди проверенных GSC): <5% → 0,
  <10% → 1/день, иначе без ограничения. Применяется СОВМЕСТНО с когортным
  `dripHealthMultiplier` (тот — множитель темпа по динамике когорты 7-21д; этот —
  потолок по здоровью всего домена). Fail-open сохранён (нет данных / <20 проверено → null).
- [x] точный матч GSC-статусов — наш `GscClient` судит по verdict PASS (`indexed=$verdict==='PASS'`),
  substring-ловушки `'indexed' in 'not indexed'` нет.

⚠️ **Операционный разрыв:** на ПРОДЕ `gsc_index_status` пуст (GSC синкается на Mac/.43,
не на прод) → и `indexHealthCap`, и существующий `dripHealthMultiplier` сейчас inert
на проде (fail-open → потолка нет). Guard станет действующим, когда индекс-данные GSC
попадут на прод (push в рамках агент-API или отдельный sync-таргет). Логика верная и
готовая — ждёт данных. Локально (где есть GSC) guard срабатывает: 5.3% → потолок 1/день.

### 5. CTR-оптимизация позиций 5–20 (приоритет №1 их доктрины) — ✅ сделано 2026-06-13

Оптимизировать существующее, не минтить новое. `_fit` по границам слов (доктрина
«ремонт длины/структуры вместо реджекта»).

- [x] `SeoMetaService`: `fit()` (трим по границе слова, не mid-word), `buildTitle()`
  (самый информативный вариант ≤60 с branded-anchor + суффиксом WEARBASE),
  `buildDescription()` (из description/anons по границе слова ≤155, иначе шаблон).
- [x] `GenerateBrandContentCommand::applyMeta` — заменён mid-word `mb_substr(…,60/155)`
  на `SeoMetaService::fit` (резал слова посередине).
- [x] `app:seo:meta-repair` — чинит ТОЛЬКО дефектные поля (пустые / title>60 / desc>155),
  приоритет по показам GSC (gsc_page_stats, LEFT JOIN), `--dry-run`, `--min-impressions`.
  Локально починено 2 active-бренда (ostrovimenitebya md 226→145 — был обрезан Google;
  Moncecy title 63→54), 0 дефектов осталось.
- [x] unit-тесты `SeoMetaServiceTest` (7).

**🐞 Найден и исправлен скрытый баг (2026-06-13):** `TranslationExtension::getBrandOriginal`
для `metaTitle`/`metaDescription` возвращал `$brand->getTitle()`/`getAnons()`, а НЕ сами
колонки `meta_title`/`meta_description`. Т.к. `brand_translation` пуст, вся
RAG-сгенерированная meta НЕ рендерилась — `<title>` был «<Бренд> | WEARBASE», meta
description — анонс. Фикс: `getMetaTitle() ?: getTitle()` (и аналогично description).
Теперь keyword-rich RAG-meta живёт в выдаче.

Следствие — render-safety: шаблон добавляет « | WEARBASE», если его нет в meta_title.
`SeoMetaService::fitTitleForRender()` режет с учётом этого суффикса (нет WEARBASE →
бюджет 60−11=49), `meta-repair` ловит render-overflow (no-WEARBASE & >49). Прогнано:
прод 135 + локально 141 title нормализованы render-safe, рендер-overflow = 0.

⚠️ meta-ремонт гонять на Mac/.43 (GSC + канонический brand-слой RAG); прод-дефекты
лечатся прямым прогоном `app:seo:meta-repair` на проде (detect по длине без GSC).

### 6. article-qa-toolkit как гейт RAG-генерации — ✅ сделано 2026-06-13

- [x] **SB/HL-гейт уже был** (`ArticleQaService`): гоняет article-qa-toolkit на
  сгенерированном описании (SpamBrain≥7, Human-likeness≥8, overall≥75), fail-open;
  Reader Value сознательно не применяем (откалибрована под статьи 1200+ слов).
- [x] **Near-dup добавлен** (был главный пробел — scaled-content по однотипным карточкам):
  `NearDuplicateDetector` (Jaccard по word-3-shingles, пороги пакета: ≥0.85 DROP,
  0.60–0.85 WARN, title 0.70). Встроен в `GenerateBrandContentCommand` после QA-гейта:
  сравнение с описаниями остальных active-брендов, ≥0.85 → бренд в review (повтор
  корпуса не лечит); принятое описание добавляется в корпус (дубли внутри прогона).
- [x] `app:seo:near-dup` — аудит существующего корпуса (попарный Jaccard, DROP/WARN,
  `--threshold`, `--export`). Текущий каталог: **дублей нет** (RAG-генерация уникальна).
- [x] unit-тесты `NearDuplicateDetectorTest` (6).

## Доктрина пакета — короткая выжимка

- **Value-not-volume**: безопасного «N страниц/день» не существует; Google карает тонкий/scaled интент, не число.
- **Verify-LIVE до выводов**: curl сырого HTML + GSC URL Inspection до любого finding/фикса (WebFetch/subagent = гипотеза).
- **Fail-closed гейты**: нет judge-ключа ⇒ не PASS ⇒ не публикуем. DRY_RUN/AUTO_DEPLOY=false по умолчанию.
- **Web-search фактчек неотключаем** перед автопубликацией; флагать только вероятно-ложное.
- Деиндексирован/наказан → новые=0, усилие в external trust + латентные победы.
