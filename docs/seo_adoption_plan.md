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
- [ ] при появлении переводов — поле `is_real` в translation-таблицах; hreflang только для `isReal=true`.

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

Осталось:
- [ ] hub-ссылки из городов/категорий на карточки (вторая шаблонная точка входа).

### 3. IndexNow + один Google-канал — ✅ сделано 2026-06-12

- [x] IndexNow уже был (`IndexNowPinger`, пинг из publish-tick) — Яндекс/Bing закрыты;
- [x] Google Indexing API: `GoogleIndexingClient` (SA из GSC_CREDENTIALS_PATH, scope
  indexing) + `app:google:index-ping` — cap 180/день (потолок 200), cooldown 14 дней
  (`google_index_ping`), приоритет свежим публикациям, стоп на 429/403, --dry-run.
  Креды только на Mac → cron `0 7 * * *` с Mac (после app:gsc:sync);
- [x] anti-trifecta соблюдена: Google — ровно один канал (Indexing API), IndexNow
  Google не трогает.

### 4. Пороги ramp в publish-tick

Их index-guards жёстче нашего drip-health (×0.25/×0.5):
- **indexed-ratio <5% → 0 новых; <10% → cap 1/день**;
- ramp UP только после 14-дн index-rate ≥0.6–0.7 И стабильных позиций;
- young-домен: потолок 3–4 новых/неделю.

У нас общий ratio 5.3% → по их доктрине дрип должен стоять почти на нуле.
- [ ] перенять пороги в publish-tick, СОХРАНИВ наш fail-open (нет GSC-данных = множитель 1.0);
- [ ] точный матч GSC-статусов: `'indexed' in s and 'not indexed' not in s` (наш GscClient судит по verdict PASS — ок).

### 5. CTR-оптимизация позиций 5–20 (приоритет №1 их доктрины)

Оптимизировать существующее, не минтить новое. Кандидаты уже есть:
wahhid (поз. 8, 2 клика), barka (5.2), ostrovimenitebya (8.3), neverlate (10.6).
- [ ] title/meta-правка 23 индексированных страниц под CTR (title ≤60, meta 150–158, `_fit` по границам слов).

### 6. article-qa-toolkit как гейт RAG-генерации

Наш `ContentValidator` проверяет только стоп-фразы и word count. Их гейт:
- SB≥7, RV≥7, HL≥8, AVI≥70;
- near-dup: title Jaccard <0.70, body <0.60, **≥0.85 = DROP**;
- закрывает риск scaled-content по 438+ однотипным карточкам.

## Доктрина пакета — короткая выжимка

- **Value-not-volume**: безопасного «N страниц/день» не существует; Google карает тонкий/scaled интент, не число.
- **Verify-LIVE до выводов**: curl сырого HTML + GSC URL Inspection до любого finding/фикса (WebFetch/subagent = гипотеза).
- **Fail-closed гейты**: нет judge-ключа ⇒ не PASS ⇒ не публикуем. DRY_RUN/AUTO_DEPLOY=false по умолчанию.
- **Web-search фактчек неотключаем** перед автопубликацией; флагать только вероятно-ложное.
- Деиндексирован/наказан → новые=0, усилие в external trust + латентные победы.
