# Карта семантики с бронью ключей — проект

Проектирование 2026-07-28 (агент `deep-reasoner`). Пункт 5 из
[seo_guide_vasin_gap.md](seo_guide_vasin_gap.md).

## Вывод и рекомендация

**Полноценная карта кластеров сейчас — оверинжиниринг, но не вся.** Факты по БД: 49 живых
статей, 559 опубликованных брендов, 4 city_hub, 208 743 уникальные фразы в `brand_keyword`.
Конфликтная поверхность — десятки страниц, а не тысячи; кластеризация «много фраз → один
кластер» окупается от ~150 статей.

Делать надо **реестр «нормализованная фраза → URL-владелец» в одной таблице `seo_cluster`,
которая по схеме уже является будущей таблицей кластеров** (фразовая таблица добавляется
аддитивно позже, без переписывания). Владелец — полиморфный (`owner_url` как канон +
`owner_type/owner_id` как резолв), а не nullable-FK-на-тип: страница города может существовать
без строки `CityHub`, а `/styles/{slug}`, `/brands`, home вообще не имеют сущности-владельца.

**Предусловие пункта «детектор каннибализации» уже выполнено:** пар «запрос × URL» в проекте не
было (`gsc_page_stats.query` заполнен в 0 строках из 4353 — так и задумано), и 2026-07-28 в
рамках пробела №1 добавлена таблица `gsc_query_page` + третий pull GSC. ⚠️ Расхождение с
исходным проектом агента: он предлагал `gsc_query_page_stats` с колонкой `day` (история по дням),
реализована **оконная** версия без `day` (UNIQUE(query, page_url), `captured_on` = дата синка).
Значит окно «за 28 дней» из детектора недоступно — детектор работает по текущему снимку окна GSC
(~7 дней). Если история понадобится, добавлять `day` отдельной миграцией.

**Главная ловушка:** `app:seo:publish-blog` запускается НА ПРОДЕ (память `blog-publish-to-prod`),
а карта живёт на Mac. Жёсткий guard внутри publish-blog требует карты на проде → второй экземпляр
истины (тот же класс граблей, что `publish-truth-is-on-prod`). Поэтому этап 0 — **проверка
`.md`-файлов на Mac ДО rsync**, без врезки в генераторы и без прод-зависимости.

## Схема

```sql
CREATE TABLE IF NOT EXISTS seo_cluster (
  id INT AUTO_INCREMENT NOT NULL,
  phrase_norm  VARCHAR(255) NOT NULL COMMENT 'нормализация в PHP (mb_strtolower, ё→е) — ключ брони',
  head_query   VARCHAR(255) NOT NULL,
  intent_group VARCHAR(32)  NOT NULL DEFAULT 'other' COMMENT 'имя группы AioQueryClassifier',
  volume_month     INT      DEFAULT NULL COMMENT 'СНИМОК спроса, не истина (истина — brand_keyword)',
  volume_synced_at DATETIME DEFAULT NULL,
  state       VARCHAR(16) NOT NULL DEFAULT 'planned' COMMENT 'planned|reserved|published|retired|orphaned',
  owner_url   VARCHAR(255) DEFAULT NULL COMMENT 'путь без домена — КАНОН владения',
  owner_type  VARCHAR(16)  DEFAULT NULL COMMENT 'brand|article|city_hub|author|style|none',
  owner_id    INT          DEFAULT NULL COMMENT 'без FK — полиморфно',
  claimed_by  VARCHAR(64)  DEFAULT NULL, note VARCHAR(255) DEFAULT NULL,
  reserved_at DATETIME DEFAULT NULL, published_at DATETIME DEFAULT NULL,
  deleted_at  DATETIME DEFAULT NULL COMMENT 'soft-delete',
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  PRIMARY KEY(id),
  UNIQUE INDEX uniq_seo_cluster_phrase (phrase_norm),
  INDEX idx_seo_cluster_state (state, deleted_at),
  INDEX idx_seo_cluster_owner (owner_type, owner_id),
  INDEX idx_seo_cluster_owner_url (owner_url)
);
```

Этап 3, аддитивно: `seo_cluster_phrase` (cluster_id FK, phrase_norm UNIQUE, phrase, source,
is_head, deleted_at).

`seo_cluster` — **сущность** (`src/Entity/SeoCluster.php` + репозиторий) → SQLite-схема тестов
провижинится сама. `state` — своё поле, **не трейт `Status`**: в `Statuses` нет `reserved`, а
`isPublished()` начал бы врать. Прецедент полиморфизма в проекте — `brand_datapoint.target_type/
target_id`.

## Связь с `brand_keyword`

- Истина по спросу — `brand_keyword` (208 743 фразы) и `*_query_stats`. В карте частотность
  только как снимок (`volume_month` + `volume_synced_at`).
- **`brand_keyword_id` не хранить**: `BrandKeywordRepository::deleteForBrand()` физически удаляет
  и пересоздаёт строки на каждом прогоне `app:brand:keywords` → id текучие, FK/CASCADE молча снёс
  бы карту. Связь — по `phrase_norm` через новый `App\Service\Seo\KeywordDemandResolver`
  (`MAX(monthly_shows)`, fallback `yandex_query_stats.shows` → `gsc_query_stats.impressions`).
- **559 карточек брендов НЕ материализуем.** Правило: карточка бренда — неявный владелец всех
  фраз, содержащих её title/slug. Строки в карте — только для не-брендовых страниц и спорных
  случаев. Логику неявного матча вынести из `SeoGapReportCommand::fetchPublishedBrandNames()`/
  `matchesKnownBrand()` в `App\Service\Seo\BrandNameMatcher` и переиспользовать в обоих местах.
- Интент — только `AioQueryClassifier::classify()['name']`. Вторую классификацию не заводим.

## Точки врезки

| Что | Где | Как |
|---|---|---|
| **Guard брони (главная точка)** | `SeoPublishBlogCommand` — после `$slug`, перед `persist` | `SeoClusterGuard::check($title, $metaTitle, "/ru/blog/{$slug}")` → при конфликте переиспользовать **готовый draft-путь судьи**: `Statuses::Disabled` + `publishedAt=null` + строка в отчёт. ~25 строк, батч не рушится. Опция `--ignore-map`. При успехе — `claim(state=published, owner_type='article')` после `flush()` |
| **Генераторы: НЕ врезаться на этапах 0–2** | `GenerateListicleCommand`, `SeoGuideCommand`, `ReplaceListicleCommand` | Они пишут `.md` в `var/seo/**` — файлы не каннибализируют. Вместо трёх правок — одна команда `app:seo:map-audit --scan-files=var/seo`, читающая `<!-- meta-title: … -->` / H1 и сверяющая с картой ДО публикации |
| Похожесть заголовков | `NearDuplicateDetector` (`TITLE_THRESHOLD=0.70`) | переиспользовать для «почти та же цель», чтобы не заводить фразовую таблицу на этапе 0 |
| query×page pull | ✅ уже сделано: `GscClient::searchAnalyticsByQueryPage` + `gsc_query_page` | детектор читает эту таблицу |
| Перенацеливание | `SeoRepublishBlogCommand` | детектор печатает готовые вызовы `app:seo:republish-blog <slug>` |

**Детектор каннибализации** (`App\Service\Seo\CannibalizationAnalyzer` — чистая функция над
массивом, тестируема без БД): группировка `gsc_query_page` по `query`, оставить запросы с ≥2 URL
(каждый ≥5 показов, суммарно ≥20); лидер = max(clicks), tie по impressions;
`overlap = min_impr/max_impr`; интент — `AioQueryClassifier`; сверка с картой → вердикт:

| Ситуация | Вердикт |
|---|---|
| Владелец в карте = лидер | оставить (нормальная многостраничность) |
| Владелец ≠ лидер | перенацелить: `owner_url ← лидер` либо правка title/H1 проигравшего |
| Оба URL — наши статьи, тот же интент, `overlap ≥ 0.5`, Jaccard тел ≥ 0.60 | слить (см. риск: 301 в проекте нет) |
| Карточка бренда vs листикл/гид, интент brand_entity/навигация | **карточка всегда владелец** → листикл сузить, имя бренда убрать из title/H1, оставить упоминание + внутренняя ссылка |
| Разные интенты ИЛИ `overlap < 0.3` ИЛИ оба собирают клики | оставить, только в отчёт |
| Две разные брони на один запрос | баг карты → в `map-audit` |

Яндекс page-разрез не даёт вообще → пары только по GSC; Яндекс остаётся источником спроса.

## Команды

```bash
app:seo:map-seed   [--dry-run] [--limit=N]         # бэкфилл: 49 статей + 4 city_hub + автор
app:seo:map-audit  [--scan-files=var/seo] [--refresh-volumes] [--orphans] [--json] [--notify]
app:seo:map-claim  "фраза" --owner=/ru/blog/slug [--state=reserved|published] [--release] [--retire]
app:seo:cannibal   [--min-impressions=5] [--min-total=20] [--apply-planned] [--json] [--notify]
```

Крон (Mac): `app:seo:map-audit --notify --no-debug` — `5 8 * * 1`; `app:seo:cannibal --notify
--no-debug` — `10 8 * * 1` (после `app:seo:gap-report` 08:00, до `app:report:weekly`).

## Тесты

Юнит: `PhraseNormalizerTest` (идемпотентность, ё→е, регистр — нормализация **обязательно в PHP**:
у SQLite BINARY-коллация против MySQL `utf8mb4_unicode_ci`); `SeoClusterGuardTest` (свободна →
allow; чужой owner → conflict; тот же owner → allow при переиздании; soft-deleted игнорируется;
**implicit-владелец бренда → warn, а не block**); `CannibalizationAnalyzerTest` (все вердикты).
Функциональные (SQLite, `CommandTester`): `SeoMapClaimCommandTest` (`--dry-run` не пишет,
`--release` ставит `deleted_at`, а не DELETE); **`SeoPublishBlogGuardTest`** — главный регресс.

## Этапы

- **Этап 0** (полезен сам, генераторы и прод не тронуты): `PhraseNormalizer` + `BrandNameMatcher`
  (вынос из gap-report) + `seo_cluster` + `app:seo:map-seed` + `app:seo:map-audit --scan-files`.
  ~250 строк.
- **Этап 1:** `app:seo:map-claim` + guard в `SeoPublishBlogCommand`. Требует решить прод-вопрос:
  брони с Mac синкать по образцу `app:blog:pull-articles`, либо guard fail-open.
- **Этап 2** (отдельная ценность, независим от карты): `app:seo:cannibal` на `gsc_query_page`
  + крон + TG. Эти данные и покажут, нужны ли кластеры вообще.
- **Этап 3, условный — триггер: >150 живых статей ИЛИ ≥5 подтверждённых пар в месяц:**
  `seo_cluster_phrase`, advisory в генераторах, авто-ссылка на владельца, калибровка брифа
  (пункт 6 гайда). Раньше триггера — не делать.

## Риски

- **Риск каннибализации уже материализовался:** в `gsc_page_stats` одновременно живут
  `ulichnyi-stil-gid-po-brendam-v-gorode-sankt-2026` (24 показа), `...-moskva-2026` (14) и
  `ulichnyi-stil-gid-po-rossiiskim-brendam-2026`. Матрица стиль×город (102 ячейки,
  `docs/seo_boost.md`) — генератор дублей по построению.
- **Две БД (Mac/прод).** Этап 0 обходит это файловым сканом; этап 1 обязан явно выбрать сторону.
- **Неявное владение брендов даёт ложные срабатывания:** словарные имена (ТВОЕ, МЕЧ, ТАЙНА)
  заблокируют легитимные статьи → implicit только warn, block по explicit-брони, фильтр
  `mb_strlen(title) >= 5`.
- **«Слить» технически невыполнимо:** таблицы редиректов / `Article.redirect_to` нет, физический
  DELETE запрещён → ограничиться `retire` + внутренняя ссылка, либо 301 оформлять отдельной
  задачей. **Открытый вопрос к владельцу.**
- **GSC query×page семплирует** и вырезает анонимизированные запросы: слабые пары (<5 показов)
  невидимы. Для детекции достаточно, но это слепая зона, а не полнота.
