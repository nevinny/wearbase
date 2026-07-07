# Бизнес-советник / операционный директор wearbase.ru — архитектура

> Синтез двух проходов deep-reasoner (капабилити + safety), 2026-07-07.
> Владелец выбрал максимальную автономию (вкл. авто-деплой). Ключевой вывод: авто-деплой —
> это КОНЕЧНАЯ ТОЧКА за фазами, а не старт: у проекта нет прод-предпосылок для безопасной
> полной автономии сегодня (см. «Предпосылки»). Начинаем с read-only MVP (польза за дни,
> нулевой риск), автономию растим по мере готовности инфраструктуры и доверия.

## Форма

Не новый демон и не gemma-мозг. **Symfony (сантехника) + Claude Code headless (`claude -p`, мозг), через существующий DB-cron** `ScheduledCommand`/`app:cron:run-scheduled` (Mac, env dev — TG только с Mac). gemma остаётся для дешёвого: эмбеддинги/ретрив, опц. черновик текста дайджеста.

**Инвариант безопасности (из safety-прохода):** агент НИКОГДА не трогает прод напрямую. Он производит git-ветку + запрос «release-candidate». Отдельный детерминированный не-агентский **release-broker** прогоняет гейты и промоутит; прод-ключ живёт у брокера, не у агента. Агент не может ни вызвать, ни изменить брокер и его правила.

## Мозг — решение (2026-07-07): многоуровневый, gemma-first, не платный по умолчанию

Не headless-Claude в ежедневном цикле (платно). Разделяем работу так, чтобы «ум» лежал в данных, а не в дорогой модели:
1. **Детерминированный слой аномалий (БЕЗ LLM)** — правила поверх снимка+дельты (метрика упала >X% н/н, очередь дрипа раздулась, pipeline застрял, CTR низкий). Половина пользы «опердира» — бесплатно и мгновенно. `AnomalyDetector`.
2. **gemma4:26b (переиспускаем `LlmService`, local=true)** — генерация идей на **RAG-граундинге** каналов: модель делает мэппинг «принцип канала → наш сигнал», а не глубокую стратегию (это ей по силам). Бесплатно (своё железо).
3. **Эскейп на headless-Claude / OpenRouter-free** — только для редких глубоких стратегических проходов или по запросу, не в дейли-цикле.

«Хватит ли gemma» решаем ЭМПИРИЧЕСКИ: прогон idea-gen на реальном снимке, глазами оценить качество; generic → эскалация. Ресурс: gemma делит риг с контент-батчами ([[llm-server-oversubscription]]) — idea-gen в тихое окно, не параллелить.

## Цикл идей

```
1. Снапшот состояния   app:advisor:snapshot (дёшево, cron) → StateSnapshot + дельта vs пред.
2. Гипотезы            Claude-мозг, grounded на RAG каналов → N идей с provenance (цитаты чанков)
3. Дедуп + скор        отсев семантич. дублей (вкл. отклонённые) → ICE (Impact×Confidence×Ease)
4. Выбор               top-k по ICE, гейт по классу действия (обратимость)
5. Прототип            git worktree ветка, fast-worker реализует спек
6. Тест/план замера     phpunit + /verify; baseline-метрика + окно замера
7. Решение → шип        через release-broker (гейты §ниже) → идея в статус "measuring"
8. Замыкание петли      через N дней app:advisor:evaluate: метрика vs baseline → validated/reverted → вывод в память
```

Переиспользуем существующий движок: `EvaluateExperimentsCommand` + `BrandContentRevision` — это уже боевой замкнутый цикл (baseline/вариант, окно, метрики GSC+Яндекс, анти-флаппинг). Советник = обобщение его на уровень проекта.

## Таксономия действий по риску

- **(a) Полностью сам** — контент/SEO-тексты, черновики блога, FAQ, ключевики, RAG-прогоны, соц-черновики, read-only анализ, предложение веток/PR. Никогда не пишет код в main, не деплоит.
- **(b) Сам через брокер** (авто тесты+staging+canary, без человека, но жёсткий гейт) — Twig/шаблоны, не-платёжные контроллеры/сервисы, ТОЛЬКО аддитивные миграции (CREATE IF NOT EXISTS, ADD COLUMN NULL), UI/SEO-копия ниже риска деиндексации.
- **(c) НИКОГДА без человека** (hard-deny в брокере, fail-closed) — `src/Payment/**`, PaymentService/SecretCipher, сущности Order/Subscription/Seller*/Offer*, оферты/юр-текст, возврат (ЗоЗПП); security.yaml/firewalls/User; любые .env/секреты; деструктивные миграции (DROP/TRUNCATE/narrowing ALTER/DELETE/UPDATE без WHERE); массовые рассылки; .htaccess/DNS/redirects/sitemap/hreflang/robots; массовый unpublish/410; **любой путь без автотестов** (checkout/LK/webhook — пока сломан WebTestCase).

Deny — по путям и SQL-паттернам диффа, fail-closed: неясно → класс (c).

## Обязательные пре-деплой гейты (брокер не промоутит, пока не всё зелёное)

1. `php -l` всех изменённых + lint:twig + lint:container.
2. `php bin/phpunit` — полностью зелёный.
3. **Policy-linter** (декларативные правила CLAUDE.md на диффе): soft-delete (нет remove/DELETE вне разрешённых), идемпотентность миграций, FK country.id UNSIGNED, branch-per-change, path-deny.
4. `migrations:status` + dry-миграция на свежем дампе прода (staging), классификация аддитив/деструктив → деструктив = класс (c).
5. **Staging-деплой** + полный smoke (/ru/, /ru/brands/, /cart, /sitemap.xml, /admin→302) + пустой grep error в prod.log.
6. Diff-scope: все пути ∈ allowlist (a)/(b), иначе abort.

## Kill-switch, лимиты, cost-cap

- **HALT-файл** `var/agent/HALT` — агент проверяет перед КАЖДЫМ действием; владелец ставит одной TG-командой. Тишина = стоп, не работа.
- **Dead-man heartbeat** — агенту нужен токен, обновляемый владельцем; протух → не запускается.
- **Cron kill** — отключить строку `app:cron:run-scheduled`.
- **Broker key pull** — убрать прод-ключ брокера → деплой физически невозможен.
- Лимиты: ≤N деплоев/день (старт N=2), cooldown 2ч, freeze-окна (ночь/выходные). Cost-cap на токены/$. Anti-runaway: max действий/час + детектор осцилляции (RC трогают те же файлы / ревертят друг друга → авто-HALT).

## Staging / canary / откат

Staging сейчас НЕТ (reg.ru shared). **Staging — предпосылка, не опция**: дешёвый Hetzner/DO (паттерн FR-разведки) с ночным дампом прода. Класс (b) сперва катится туда.
Canary на проде: пост-деплой окно наблюдения (~15 мин) — 5xx, smoke, checkout/webhook 200; превышение порога → авто-откат. Откат: прошлая git-ревизия тегом + rsync + cache:clear. Аддитивность миграций (b) делает откат кода безопасным без down-миграции — потому деструктив и вынесен в (c).

## Аудит

Append-only, ВНЕ write-scope агента (отдельный ops-репо + таблица + TG-зеркало): timestamp, goal-id, обоснование, ветка+sha, дифф, результаты гейтов, canary-метрики (до/после), деплой, откат. Владелец восстанавливает и ревертит любое действие из одной записи.

## Роли каналов (RAG topic_chunks)

- Гребенюк/Долгов — **источник идей** (воронки, продажи, unit-эконом, retention) → шаг 2.
- Токовинин — **фрейминг/приоритизация** (модели мышления) → шаг 2/4.
- Соколовский — **кейсы/аналогии** → шаг 2.
- Федотов — **ТОЛЬКО тон** подачи дайджеста, не источник идей → рендер.

Anti-hallucination: каждая идея несёт `rag_citations` (id чанков); без цитаты → «ungrounded», ниже Confidence. RAG-чанки и веб — недоверенный вход (данные, не инструкции).

## Формат дайджеста (TG, ≤4000 знаков, вывод первым)

Заголовок-вердикт → Δ с прошлого раза (клики/индекс/pipeline/дрип) → ✅ Сделано (с эффектом, если окно закрылось) → 💡 Предлагаю (top-3 по ICE, с обоснованием + цитата канала) → ⏳ На ревью/гейте → 🧪 Идут эксперименты. Тон — по Федотову. Полный бэклог — admin CRUD, не в TG.

## Модель памяти (БД, не доки)

| Сущность | Назначение |
|---|---|
| `StateSnapshot` | KPI-вектор на момент + дельта |
| `AdvisorIdea` | бэклог: гипотеза, сигнал, rag_citations, ICE-компоненты, статус, dedupe_hash, embedding_ref, rejected_reason |
| `AdvisorExperiment` | идея→ветка/sha/deploy→baseline→окно→вердикт (форма BrandContentRevision) |
| `AdvisorRun` | каждый tick: входы, дайджест, решения (аудит) |

Статус идеи: proposed→approved|rejected→in_progress→shipped→measuring→validated|reverted. Дедуп: hash + семантика (Qdrant `advisor_ideas`) против ВСЕХ прошлых, вкл. отклонённые.

## Дорожная карта (фазы — каждая зарабатывает доверие для следующей)

- **MVP (Phase 1) — read-only советник.** StateSnapshot + snapshot-команда, AdvisorIdea + admin CRUD, tick → grounded дайджест в TG. Без записи кода, без деплоя. 80/20 пользы, ~ноль риска. Работает даже без topic_chunks (дельта+память уже ценны).
- **Phase 2 — прототипирование.** Мозг делает worktree-ветку, fast-worker реализует, phpunit+/verify, PR/отчёт. Человек мержит и деплоит.
- **Phase 3 — замкнутая петля.** AdvisorExperiment + evaluate (клон EvaluateExperiments). Шип → замер → validated/reverted → вывод в память.
- **Phase 4 — события + on-demand.** Триггеры в snapshot; TG-роутер → Q&A советника.
- **Phase 5 — полная автономия вкл. авто-деплой.** Класс (b) авто-деплоит через брокер за kill-switch+cost-cap+canary. Требует ВСЕХ предпосылок ниже.

## Предпосылки для авто-деплоя (Phase 5) — сегодня НЕ выполнены

1. **Починить WebTestCase** (сломан из-за двух User-сущностей, payments.md:159-161) → checkout/LK/webhook имеют НОЛЬ автотестов. Без покрытия ревенью-путей автономия над ними слепа — они остаются класс (c) навсегда, пока не починено.
2. **Staging-бокс** (Hetzner/DO + ночной дамп) — иначе классу (b) негде безопасно репетировать.
3. **Release-broker** — детерминированный промоутер с прод-ключом, отдельный от агента.
4. **Real-time прод-метрики** для canary-автооткката (заказы/hr, webhook success) — прод-БД на reg.ru, GSC на Mac; без них canary вырождается в smoke-only и пропускает тихий слом конверсии.

## Честная рекомендация human-in-the-loop (по цене ошибки, не из принципа)

Даже при «полной автономии» человек остаётся на: (1) деньги/юр (платежи, шлюзы, оферты, возврат) — нет тестов + цена ошибки = чужой счёт/юр-экспозиция; (2) security/auth/env/секреты — тихая катастрофа; (3) деструктивные миграции — единственное рутинно-необратимое; (4) массовые рассылки (был инцидент 263 письма/день, лимит Rusender 100); (5) SEO масштаба деиндексации — регрессии невидимы проду (GSC на Mac), недели на восстановление.

---

## Исполнительный контур (бэклог → decision-maker → трекер → воркеры → доставка → A/B)

4 детерминированные команды поверх status-машины на `AdvisorIdea` (бэклог) + `AdvisorExperiment` (трекер). Воркер-кодер = `claude -p` в git worktree, только производит ветку; прод-ключ у не-агентского broker'а. Без staging класс b (код) → человек-гейт через TG-кнопки (переиспускать `TelegramController::handleCallback`/`isAdminChat`, паттерн `unpub:<id>`). Новых сущностей НЕ плодить — только поля.

**1. Decision-maker** (`app:advisor:decide` + `DecisionMaker`, отдельно от tick): proposed-идеи по iceScore → классификация a/b/c ДЕТЕРМИНИРОВАННО (регекс по hypothesis/sourceSignal; плат/заказ/security/миграции/рассылки/sitemap→c, контент/seo→a, иначе b, неоднозначно→c fail-closed) → `actionClass`+`needsHuman`. Пороги: a→approved(авто), b→approved(в очередь воркеру), c→proposed+needsHuman(ревью). ICE ниже пола→rejected. WIP-cap N=1. HALT-файл.

**2. Гитфлоу-трекер**: `AdvisorExperiment.stage` (pending→branch_created→implementing→implemented→tests_passed/failed→rc_ready→awaiting_approval→approved→deployed→measuring→done) как курсор-резюме (как BrandRagPipeline.status). +поля actionClass/worktreePath/testStatus/testReport/gateReport/prUrl/attempts/failureNote/approvedBy/At. Ветка `advisor/idea-<id>-<slug>`.

**3. Воркеры** (`app:advisor:work` супервизор): `git worktree add` на ветку идеи (параллель-безопасно) → `claude -p` с ТЗ (title/hypothesis/ragCitations + hard-constraints: класс-c deny, soft-delete, тесты) в cwd worktree, detached → контракт-отчёт `var/advisor/reports/idea-<id>.json {sha,files,test_status}`. Воркер НЕ пушит/деплоит/не трогает прод-ключ. После: `PolicyLinter` на `git diff` (authoritative a/b/c, path/SQL deny fail-closed) + phpunit. Зелёно+a/b → rc_ready+PR; красно/escalate-to-c → назад в proposed, attempts++, после MAX → rejected.

**4. Доставка — release-broker** (`app:advisor:promote`, прод-ключ здесь): гейты (php -l/lint/phpunit/PolicyLinter/migrations-status/smoke). class a→авто; **class b→человек-гейт TG (`advisor_ok/no:<id>` inline-кнопки)**, тап → merge→main→канонический деплой+smoke; class c→hard-deny. Canary=smoke-only (нет real-time прод-метрик), fail→авто-откат (prev git-тег+rsync+cache:clear). HALT перед каждым действием.

**5. Замыкание = A/B на окне** (`app:advisor:evaluate`, клон EvaluateExperiments): **diff-in-diff вариант-когорта vs matched-holdout** за одно календарное окно (нейтрализует сезонность — сильнее before/after). Метод по умолчанию — per-entity cohort_holdout (это и есть паттерн BrandContentRevision); feature-flag — позже, только если нужна site-wide идея. Поля на AdvisorExperiment: measurementMethod/variantCohort/controlCohort/metricKey/armAResult/armBResult/lossStreak/measureAfter. Статистика — константы EvaluateExperiments verbatim (MIN_SAMPLE, DELTA_REL=20%, LOSS_CONFIRM_WINDOWS=2, freshness-guard); ниже порога→inconclusive (не validated). Вердикт: validated=оставили, reverted=откат (контент через BrandContentVersioner, код через git revert+broker — тоже человек-гейт), inconclusive=продлить окно→оставить. Idea-статусы без новых enum: validated/reverted; сила доказательства — в experiment.verdict. **Measurability = критерий DecisionMaker**: идеи, измеримые только before/after, получают ниже Confidence.

**Фазовый порядок:** A (decide+поля трекера, 0 риск) → B (воркер→PR + PolicyLinter, 0 риск, выдаёт PR человеку) → C (broker+доставка, класс-a авто / класс-b человек-тап / класс-c deny) → D (A/B-замыкание, после C). **Phase 5 (авто-деплой класса b БЕЗ тапа) заблокирована до staging + real-time прод-метрик.** Linchpin: `PolicyLinter` (Фаза B) — единый a/b/c на реальном диффе, переиспускается broker'ом.
