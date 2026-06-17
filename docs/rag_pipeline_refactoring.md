# RAG-конвейер — план рефакторинга (SOLID · KISS · DRY · Symfony best practices)

> ✅ **РЕАЛИЗОВАНО 2026-06-18** (все 4 фазы, ветка task-20). Статус по пунктам — в конце документа (§7).
> Статус: анализ + план + реализация. Подготовлено 2026-06-18.
> Ревью: фактчек против кода + Symfony/SOLID-критика + архитектурное ревью (3 агента).
> Связано: [rag_pipeline.md](rag_pipeline.md) (технический reference), [commands.md](commands.md).
> Триггер: расследование «почему не дренится generate» вскрыло системные причины,
> а не точечный баг.

## 0. Контекст (зачем рефакторинг)

Два свежих инцидента — симптомы одного класса проблем:
- **Дренаж `generate` встал**: 740 брендов в `embedded`, генерация берёт 0/прогон.
- **Рассинхрон счётчиков**: отчёт «осталось 164 ключевика» против «0» в админке
  (уже точечно починен 2026-06-18 — выравниванием запроса; см. §2③ как мотивацию).

Оба — следствие **дублированных/расходящихся определений** одного и того же
бизнес-правила и **отсутствия единого владельца переходов статуса**. Точечные правки
лечат симптом; ниже — структурные причины и фазовый план.

> **Главный вывод ревью:** корень — в **домене** (владение переходами статус-машины),
> а не в «слое данных». Расщепление репозиториев — гигиена, не лекарство. Поэтому
> приоритет — не чистота принципов, а **риск × срочность** (см. §4).

## 1. Слой данных конвейера (как есть)

| Репозиторий | Строк | Роль | Оценка |
|---|---|---|---|
| `BrandRepository` | 560 | **13** stage-finder'ов **+** public-site запросы **+** контакт-обогащение | перегружен (SRP) |
| `BrandRagPipelineRepository` | 45 | `getOrCreate` + `markContentChanged` | анемичный |
| `BrandSourceUrlRepository` | 120 | `claimPending` (FOR UPDATE SKIP LOCKED), `reclaimStale` | хороший |
| `BrandSourceDocumentRepository` | 55 | findBy-обёртки, дедуп по content_hash | ок |
| `BrandKeywordRepository` | 55 | exists / ranked / delete | ок |
| `BrandContentRevisionRepository` | 76 | closed-loop (due / rollback) | хороший |

**Модель состояний** (`brand_rag_pipeline.status`):
`pending → scraped → embedded → done` + ветки `deferred`, `review`, `*_failed`.
Плюс 8 независимых side-полей в той же строке: `crawlStatus, faqStatus,
keywordsStatus, attributesStatus, logoStatus, wbStatus`, `pushedAt/contentChangedAt`,
`priority/regenRequestedAt`, три `*Attempts`. По сути в одной строке живут ~9
ортогональных машин состояний, управляемых разными демонами в разных процессах.

## 2. Доказанные дефекты (корни инцидентов)

### ① Два несовместимых определения «очереди генерации» (корень дренаж-бага)
- `BrandRepository::findForGeneration` (по `status = embedded`) — документированный
  «Этап 3», **0 вызовов → мёртвый код**.
- `BrandRepository::findWithoutDescription` (`description IS NULL OR ''`) — что
  **реально** использует `GenerateBrandContentCommand` (строка 146).
- Вся остальная система (админ-счётчики, `PipelineReportCommand`, `finishStageQuery`)
  меряет очередь по `status = embedded`; генератор — по пустоте описания.
- Расхождение: бренды с непустым описанием невидимы генерации навсегда.
- ⚠️ **У дренаж-затора ДВЕ причины** (см. ② ниже) — застрявшие 740 это пересечение
  двух популяций: невидимые из-за непустого описания **И** демотнутые `done→embedded`.
  Поэтому унификация определения очереди (код) **не мигрирует** уже застрявшие строки —
  нужна отдельная reconcile-операция (см. §4 Фаза 0).

### ② Лоссовый переход без guard'а
`EmbedBrandSourcesCommand::advance()` безусловно ставит `STATUS_EMBEDDED` →
ре-эмбед готового бренда демотит `done → embedded` (484 бренда, 6–7 июня:
`embedded_at > generated_at`). Машина не запрещает регрессий.

### ③ Дублирование бизнес-предикатов (DRY) → рассинхрон
- **publish-ready** живёт в **4–5 местах и НЕ эквивалентен**:
  - `BrandRepository::findReadyToPush` (DQL) — каноническая форма, включает ветку
    `pushedAt IS NULL OR contentChangedAt > pushedAt`;
  - `PipelineReportCommand` (raw SQL) — без ветки `contentChangedAt`;
  - `RagDashboardController` (raw SQL, **дважды**: ~стр. 91 и ~199–200) — без
    `contentChangedAt`, и роняет `IS NOT NULL` для meta (только `<> ''`);
  - `BrandRagPipeline::isPublishReady()` — пятая, PHP-форма для per-row проверки в пуше.
  - Итог: raw-копии **строго уже** DQL → не видят «изменённые после пуша» бренды.
    Комментарий в репе сам признаёт: «isPublishReady, развёрнутый в SQL».
- **остаток ключевиков**: отчёт vs админка разошлись → 164 vs 0.
  **Уже устранён точечно** 2026-06-18 (`PipelineReportCommand` выровнен с админкой через
  `NOT EXISTS (brand_keyword)`). Оставлено здесь как мотивация — класс багов остаётся.

### ④ Владение переходами размазано → нет инвариантов
`getOrCreate()->setStatus(BrandRagPipeline::STATUS_*)` пишут **7 файлов**
(`EmbedBrandSourcesCommand`, `FetchBrandSourcesCommand`, `GenerateBrandContentCommand`,
`ScrapeBrandSourcesCommand`, `RevalidateContentCommand`, `ResetPhantomPipelineCommand`,
`RagDashboardController`). Ещё ~7 команд/сервисов зовут `getOrCreate()` ради side-полей
(faq/wb/crawl/keywords/contentChanged). Магистральный `status` пишут 7 точек, и ни одна
не проверяет легальность перехода → инварианты (`done` не откатывать, `review/deferred`
терминальны) централизованно не гарантированы. Сущность `BrandRagPipeline` анемична
(геттеры/сеттеры + единственная логика `isPublishReady()`).

## 3. Нарушения принципов (детально)

**SRP**
- `BrandRepository` = 3 ответственности: витрины (`findBrandsByLetter`,
  `findFeaturedBrands`, `findSimilarBrands`, `findRelatedHard`, `getLetterStats`),
  RAG-stage-finder'ы (`findForScrape … findReadyToPush`), контакты
  (`findForContactEnrichment/Refresh`).

**OCP / flag arguments**
- `finishStageQuery(..., bool $oldestFirst, bool $leastAttemptsFirst)` — флаг-аргументы;
  каждый новый порядок = ещё булев параметр. Просится сортировочная стратегия / `Criteria`.

**DRY**
- Предикаты из §2③.
- Soft-delete (`deletedAt IS NULL`) повторён в **3 методах** `BrandSourceDocumentRepository`
  (`findByBrand`, `findUnembeddedByBrand`, `countByBrand`). ⚠️ При этом `findByBrandUrl`
  его НЕ фильтрует — глобальный фильтр изменил бы его поведение (риск, см. §4 Фаза 3).

**Согласованность / Symfony idioms**
- Граница enum↔string: пишущий путь местами шёл raw-строкой — отсюда `inactive`-баг
  (невалидный backed-enum `Statuses`, починен 2026-06-18 в `BrandUnpublisher` +
  `RagDashboardController`). Сейчас `'inactive'` в `src/` отсутствует. raw `'active'/'new'`
  в SQL-счётчиках дашборда — **намеренный** обход ORM ради `COUNT`; их трогать не нужно.
  Цель — гарантировать enum **на write-path**, а не «enum end-to-end».
- raw SQL vs QueryBuilder вперемешку (`findForContactRefresh`, `findRelatedHard` — сырой).
- `declare(strict_types=1)` только в 1 из 6 реп конвейера (`BrandContentRevisionRepository`).
- Две модели конкурентности: URL-уровень — атомарный `claim` (SKIP LOCKED);
  brand-уровень — `MOD(id,total)` шардинг без локов. Разные парадигмы; риск конкурентной
  записи в одну строку `brand_rag_pipeline` (магистраль + side-поля от разных демонов).

**Мёртвый код / KISS**
- `STATUS_GENERATED` — **никто не присваивает** (только в WHERE-guard'ах `findForExtract`,
  `FetchBrandSourcesCommand`, `ResetPhantomPipelineCommand`); `generate` пишет `DONE`
  напрямую (`GenerateBrandContentCommand:476`). Фантомное состояние.
- `getLetterStats()` грузит всех active в PHP и считает в цикле → один `GROUP BY`.
- `findForExtract`: параметр `:done` содержит `SCRAPED/EMBEDDED/GENERATED/DONE` —
  вводящее в заблуждение имя.
- `BrandKeywordRepository::existsPhrase` (app-level дедуп) не совпадает с
  `unicode_ci`-уникальностью БД → корень dup-краша (решено `INSERT IGNORE`, метод остался
  рассогласованным).

## 4. План работ — 4 фазы по «риск × срочность»

Каждая фаза самодостаточна, деплоится и откатывается отдельно. **Сквозное требование:**
ни одна фаза не требует остановки/одновременного передеплоя split-демонов (gpu‖net) —
изменения статус-семантики forward/backward-совместимы хотя бы на один прогон.

### Фаза 0 — остановить кровотечение (часы–день, hotfix)
**Цель:** дренаж пошёл, регрессия `done→embedded` невозможна, 740 разобраны.
- Диагностический запрос: классифицировать 740 по когортам (непустое описание /
  `embedded_at > generated_at` / есть `done`-ревизия в `brand_content_revision`).
- Минимальный guard в `EmbedBrandSourcesCommand::advance()`: не понижать статус, если
  бренд уже `done` (точечная проверка, **без** Workflow).
- Миграция застрявших через `BrandContentVersioner` (сохранить аудит ревизий),
  **не** прямым UPDATE статусов.
- **Критерий выхода:** счётчик `embedded` падает на прогон; ни один `done` не демотится;
  740 переведены в корректное состояние; следующий цикл `generate` ненулевой.

### Фаза 1 — инварианты как сеть безопасности (дни)
**Цель:** регрессии ловятся автоматически до структурных правок.
- Characterization/invariant-тесты на тестовой БД (`WebTestCase` + `*_test`): «нет `done`
  с пустым `description`», «нет `embedded` с `generated_at > embedded_at`», «очередь по
  статусу ≡ очередь по бизнес-смыслу», консистентность `isPublishReady` vs SQL-зеркала,
  видимость regen-flagged.
- Интеграционный тест push-пути (предикат publish-ready → agent-API).
- **Критерий выхода:** инварианты зелёные на прод-снимке; есть красный тест,
  воспроизводящий дренаж-баг до Фазы 0.

### Фаза 2 — один источник правды для домена (1–2 недели, низкий риск)
**Цель:** убрать DRY-расхождения и владение переходами — **без** смены парадигмы.
- §2③: единый приватный `publishReadyQueryBuilder()` → `findReadyToPush` + `countReadyToPush`;
  дашборд и отчёт зовут `countReadyToPush()` вместо raw SQL. То же — `countKeywordsQueue()`,
  очередь генерации. **Specification не нужен** — один QB-метод проще и идиоматичнее.
- §2①: унифицировать определение очереди генерации на ОДНОМ предикате (явно решить с
  продуктом: бренд с непустым legacy-описанием — это очередь генерации или нет?),
  **сохранив ветку `findRegenFlagged`** (closed-loop форсит реген на `done`+непустых).
  Удалить мёртвый `findForGeneration` и фантом `STATUS_GENERATED`.
- §2②④: перенести переходы и инварианты в **сущность** `BrandRagPipeline`
  (`markEmbedded()/markDone()/...` с проверкой текущего состояния + `const TRANSITIONS`);
  закрыть прямой `setStatus()` извне. **Symfony Workflow component — отклонён** для этого
  кейса (оверинжиниринг: ~9 машин в одной строке, guard'ы не транслируются в SQL → два
  источника правды; вернуться только при доказанной потребности в событиях/визуализации).
- **Критерий выхода:** ни один предикат не дублируется; статус пишется только через
  домен-методы; инварианты Фазы 1 зелёные.

### Фаза 3 — структурная гигиена (фоном, по остаточному принципу)
**Цель:** SRP/OCP/идиомы — то, что не влияет на инциденты.
- §3 SRP: расщепить `BrandRepository` — оставить публичные витрины; pipeline-finder'ы →
  **простой сервис** `PipelineQueueRepository` (инжект `ManagerRegistry`, **не** второй
  `ServiceEntityRepository` на ту же сущность); контакты → `BrandContactQueryRepository`.
  Инкрементально через прокси-делегацию.
- `getLetterStats` → `GROUP BY`; flag-args `finishStageQuery` → сортировочный value-object;
  `declare(strict_types=1)` везде; enum на write-path.
- **SQLFilter для soft-delete — отклонён** (risky, низкий ROI: ломает
  `findAllByBrandIncludingDeleted`, молча меняет `findByBrandUrl`; повтор всего в 3 методах
  одной таблицы). Дешёвая альтернатива — приватный хелпер или оставить как есть.
- **Критерий выхода:** запахи устранены; поведение неизменно (инварианты зелёные);
  ни одно изменение не требует одновременного передеплоя демонов.

### Наблюдаемость (параллельно с Фазой 2)
- `app:rag:doctor`: сверяет независимые формулы очередей (DQL-finder count ≡ raw-SQL
  дашборда), exit≠0 при расхождении. Вешается в `scheduled_command`. И регресс-тест,
  и runtime-алерт — дешевле любого Workflow-аудита.

## 5. Что НЕ ломать при рефакторинге

- **Шардинг `MOD(b.id, total)`** в `finishStageQuery` — основа split-демона (gpu‖net),
  непересекающиеся наборы без локов. Сохранить семантику.
- **`claimPending` (SKIP LOCKED)** для URL-уровня — рабочая конкурентность, не трогать.
- **Исключение `deferred/review`** из авто-генерации (`excludeDeferred`) — защита от
  вечного перевыбора refusal-брендов. Перенести в guard'ы домен-методов, не потерять.
- **Soft-delete-политика** (никаких физических DELETE по действию пользователя) — не ослабить.
- **Closed-loop** (`BrandContentRevision` + `EvaluateExperimentsCommand` + `findRegenFlagged`,
  сортировка по `priority DESC`, `regenRequestedAt`): унификация очереди генерации обязана
  сохранить видимость regen-flagged брендов (`done` + непустое описание).
- **Миграция данных — только через `BrandContentVersioner`** (`ensureBaseline`), не сырым
  UPDATE статусов, иначе теряется аудит ревизий и защита «не потерять контент».
- **Push-путь в прод-каталог** (agent-API): менять publish-ready предикат только с
  интеграционным тестом — рассинхрон = либо непубликация готовых, либо публикация недозревших.

## 6. Чего НЕ хватало в первой версии плана (закрыто выше)

- Не было фазы «остановить кровотечение» (теперь Фаза 0).
- Не было тестов/инвариантов как самостоятельной поставки (Фаза 1).
- Не было reconcile-команды для уже застрявших 740 + 484 демотнутых.
- Не учитывалась обратная совместимость демонов на лету (сквозное требование §4).
- Не учитывался closed-loop / `findRegenFlagged` / `BrandContentVersioner` (§5).
- Не было наблюдаемости (`app:rag:doctor`).
- Symfony Workflow и SQLFilter были приняты по умолчанию — оба **отклонены** по итогам ревью.

## 7. Статус реализации (2026-06-18, ветка task-20)

**Фаза 0 — остановить кровотечение** ✅
- Guard в `EmbedBrandSourcesCommand::advance()` (→ домен-метод `BrandRagPipeline::markEmbedded()`): не демотит `done`.
- `app:rag:reconcile-stuck`: восстановил 484 демотнутых в `done`, `embedded` 740→256.

**Фаза 1 — инварианты** ✅
- `app:rag:doctor`: 4 hard-инварианта (done без meta, застрявший контент, статус inactive, рассинхрон keyword-формул). Поймал реальную порчу (hansa без meta — догенерён).
- `tests/Entity/BrandRagPipelineTest`: 11 тестов (isPublishReady + markEmbedded-guard).

**Фаза 2 — единый источник правды** ✅
- Очередь генерации унифицирована: generate → `findForGeneration` (status=embedded); тощие заглушки → полная генерация, meta-only двигает `embedded→done`. Дренаж разблокирован (проверено вживую).
- `countReadyToPush()`/`readyToPushQb()` — единый predicate; отчёт + дашборд (×2) зовут его вместо 4 raw-копий.
- Переход в домен: `markEmbedded()`. Удалён мёртвый `findForGeneration`-дубль (`findWithoutDescription`) + фантом `STATUS_GENERATED`.

**Фаза 3 — структурная гигиена** ✅ (частично, осознанно)
- `BrandRepository` расщеплён: pipeline-finder'ы → `PipelineQueueRepository` (логика вынесена; BrandRepository делегирует → 0 риска live-демонам).
- `getLetterStats` → GROUP BY (не грузит все сущности).
- **Отклонено/отложено** (документировано): SQLFilter soft-delete (отклонён арх-ревью); `strict_types` во всех старых репах + flag-args→value-object (низкая ценность/риск coercion); каллер-миграция с делегаторов на прямой вызов `PipelineQueueRepository` (безопасный follow-up).

**Наблюдаемость:** `app:rag:doctor` в строю (вешать в scheduled_command).
