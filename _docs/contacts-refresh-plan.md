# План: app:contacts:refresh — независимый контактный конвейер

## Проблема

- Обогащение контактов (`app:brand:enrich-contacts`) — разовый проход вне конвейера
- После `contactStatus=enriched` контакты больше никогда не проверяются
- Hard bounce в outreach не приводит к ревалидации контактов
- Нет механизма самоподтверждения найденных контактов
- Нет метрик в дайджесте

## Выявленные проблемы корпуса (на примере Телодвижения)

1. **Yandex Store SPA** — trafilatura извлекает ~12% контента (только статический HTML)
2. **Pinterest gate31** — классифицирован как `own_site`, но это чужой бренд
3. **VK/Instagram не скрейпятся** — VK блокирует, Instagram требует JS
4. **Телефон и `info@`** — в БД есть, но ни в одном скрейп-документе не найдены (вероятно, из начального импорта)

## Архитектура

### Независимый процесс

Контакты не в `RagDaemonCommand`. Отдельный конвейер:
```
app:contacts:refresh --ttl=180 --limit=50 [--daemon] [--only-email] [--full] [--force] [--dry-run]
```

Демон-режим: `--daemon --interval=3600`

### Self-verification loop

Каждый цикл извлекает контакты из корпуса и сравнивает с текущими Brand-полями:

```
извлечь контакты → {email, phone, address, social_urls}

для каждого поля:
  если Brand.поле === null:
    заполнить, contactVerifyCount = 1

  если Brand.поле === извлечено:
    contactVerifyCount++
    если verifyCount >= 2 И BrandDatapoint.provenance === 'enrichment':
      → upgrade до 'confirmed'
    contactVerifiedAt = now

  если Brand.поле !== извлечено И Brand.поле !== null:
    НЕ ТРОГАТЬ Brand.поле
    залогировать разницу
    contactVerifyCount = 0  // сброс — данные разошлись
```

### Selection strategy

Приоритет:
1. `BrandOutreach.bouncedAt IS NOT NULL` — bounced email, срочно
2. `contactStatus = 'partial'` — был неполный
3. `contactEnrichedAt + TTL < NOW()` — TTL=180 по умолчанию
4. `contactEnrichedAt IS NULL` — никогда не обогащался
5. Появились новые `BrandSourceDocument` после `contactEnrichedAt`

### Механизм очистки невалидных данных

| Сценарий | Что происходит |
|---------|---------------|
| **Bounce** → найден новый email | Замена, снятие bounce-флага |
| **Bounce** → альтернативы нет | Brand.email = null |
| **Crowd rejected** (голоса) | state=hidden, очередь ре-обогащения |
| **HTTPS 404** N раз подряд | state=doubtful → hidden |
| **Просто не найдено в корпусе** | contactVerifyCount=0, НЕ удаляем |

Только hard bounce автоматически очищает данные. Всё остальное — демотируется.

## Изменяемые файлы

### 1. `src/Entity/Brand.php`

Добавить поля:
```php
#[ORM\Column(type: 'integer', options: ['default' => 0])]
private int $contactVerifyCount = 0;

#[ORM\Column(type: 'datetime', nullable: true)]
private ?\DateTimeInterface $contactVerifiedAt = null;
```

`contactVersion` уже есть (строка 173).

### 2. `src/Entity/BrandDatapoint.php`

Добавить константы provenance:
```php
public const PROV_IMPORT     = 'import';
public const PROV_CONFIRMED  = 'confirmed';
```

Уже есть: `enrichment`, `owner`, `crowd_confirmed`.

### 3. `src/Repository/BrandRepository.php`

Новый метод `findForContactRefresh()` — приоритетная выборка:
- Bounce → partial → stale → never → new-content
- Параметры: TTL, limit, force

### 4. `src/Command/BrandRefreshContactsCommand.php`

Новая команда `app:contacts:refresh`.

Заменяет `app:brand:enrich-contacts`.

Опции:
- `--ttl=180` — дней между ревалидациями
- `--limit=50` — брендов за запуск
- `--only-email` — только email (умолчание)
- `--full` — все контакты
- `--daemon` — цикличный режим
- `--interval=3600` — пауза между циклами
- `--force` — игнорировать TTL
- `--dry-run` — ничего не писать

### 5. `src/Service/LlmService.php`

Новый метод `extractEmailFromText()` — лёгкий промпт только для email:
```php
public function extractEmailFromText(string $brandName, string $text): ?string
```

### 6. `src/Service/Outreach/BrandOutreachMailer.php`

При hard bounce: `Brand.contactStatus = 'bounced'`

### 7. `src/Command/DailyReportCommand.php`

Новая секция "Контакты":
```
📬 Контакты брендов:
├─ С primary email:       342 / 779 (44%)
├─ С телефоном:           156 / 779 (20%)
├─ enriched:              280
├─ partial:                45
├─ not_found:              17
├─ bounced:                12 ← срочно
├─ stale (>180д):          89
└─ обновлено за 24ч:       8
```

### 8. `src/Command/PushBrandsCommand.php`

- `contactVersion` уже передаётся в payload
- Если `contactBouncedAt` — не пушить bounced email
- Логировать изменения контактов

### 9. Миграция Doctrine

```sql
ALTER TABLE brand
  ADD COLUMN contact_verify_count INT DEFAULT 0 NOT NULL,
  ADD COLUMN contact_verified_at DATETIME DEFAULT NULL;
```

## Что НЕ меняем

| Компонент | Причина |
|-----------|---------|
| `EnrichBrandContactsCommand` | работает отдельно, может пригодиться legacy |
| `BrandRagPipeline` | contacts — вне основного конвейера |
| `BrandOutreach` / `BrandOutreachMailer` | своя логика, минимальные изменения |
| `BrandIngestController` | owner-guard не трогаем |
| `RagDaemonCommand` | contacts — отдельный конвейер |
| Скрапинг/краул | отдельная проблема (Pinterest, VK, SPA) |

---

# Ревизия v2 (сверено с кодом 2026-06-08)

> Цель уточнена: **актуализация контактов (в первую очередь email) для онбординга брендов**.
> Корпус уже собран и доступен локально через RAG — `app:brand:ask <brand> "..." --local`
> вытащил `help@telodvigeniya.ru`, `marketing_o@telodvigeniya.ru`, адрес и ник соцсети из
> чанков (chunks=5, score≈0.61). **Корпус надо использовать как источник, а не скрести заново.**
>
> Ниже — что в плане v1 не сходится с фактическим кодом, и как переформулировать дизайн.

## A. Критичные расхождения с кодом

### A1. Контактных датапоинтов локально нет — self-verification loop апгрейдить нечего

Запрос по локальной БД:
```sql
SELECT provenance, state, COUNT(*) FROM brand_datapoint
WHERE target_type='brand_contact' GROUP BY provenance, state;  -- → пусто (0 строк)
```
При этом 586 брендов с email, 948 с телефоном, 1220 `contact_status=enriched`.
Контакты живут **скалярами на `Brand`** (`email`/`phone`/`address`), без датапоинт-провенанса.

Причина: `EnrichBrandContactsCommand::applyContacts()` (та, что v1 «заменяет») пишет
`Brand.setEmail()`, `BrandLink`, `BrandStore` — **датапоинты не создаёт**. Единственные
писатели `brand_contact`-датапоинтов — **прод-путь**: `BrandIngestService` (приёмник
`/api/v1/brands/upsert`), `BrandStoreController` (ЛК), `DatapointVoteService` (голоса).

**Следствие:** условие v1 «`verifyCount>=2 И BrandDatapoint.provenance==='enrichment'` →
upgrade до confirmed» (стр. 42–48) выполняется над строками, которых на локали нет.
`provenance/state/confirmCount` — **прод-сторона**. Локальная команда не должна их трогать.

**Резолюция топологии (раньше была открытым вопросом):**
команда работает **локально, над RAG-корпусом**; подтверждение/провенанс — **на проде через push**.

### A2. `BrandDatapoint` уже = per-field FSM, которую v1 переизобретает скалярами на `Brand`

v1 (стр. 79–84) добавляет на `Brand`: `contactVerifyCount:int`, `contactVerifiedAt`.
`BrandDatapoint` **уже** содержит то же, но правильной (по-полю) гранулярности:

| v1 кладёт на Brand (скаляр) | Уже есть в BrandDatapoint (на поле) |
|---|---|
| `contactVerifyCount` | `confirmCount` (+ `rejectCount`, `rejectWindow`) |
| `contactVerifiedAt` | `revalidatedAt` (+ `queuedRevalidateAt`, `stateChangedAt`) |
| «upgrade до confirmed» | `state` ACTIVE/DOUBTFUL/HIDDEN/**PINNED** + `provenance` (`enrichment`/`owner`/`crowd_confirmed`) |

Один счётчик на бренд схлопывает независимо-проверяемые сущности (email + phone + address +
N ссылок + N магазинов). **Не добавляем скаляры на `Brand`.** Счётчик подтверждений —
это `confirmCount` на датапоинте, и живёт он на проде.

→ Отменяет пункты v1 **§1** (поля на Brand) и **§9** (миграция `contact_verify_count`/`contact_verified_at`).
→ `PROV_IMPORT`/`PROV_CONFIRMED` из v1 §2 не вводим: «подтверждён» — это уже
   `state=PINNED`+`provenance=crowd_confirmed`; второй параллельный «confirmed» создаст коллизию смыслов.

### A3. Самоподтверждение из одного кеша — циклично

v1 «извлечь из корпуса → при совпадении `verifyCount++`» (стр. 36–48). Но `WebScraperService`
кеширует 30 дней, а `BrandRagService::retrieve()` возвращает **слепленный `context`-строкой**,
теряя привязку чанк→документ. Повторное чтение тех же байтов и инкремент счётчика — ложная
уверенность («прочитал одно и то же дважды»).

**Фикс:** подтверждением считать согласие ≥2 **различных** `BrandSourceDocument`.
Для этого извлечение должно работать с **сырыми hits** (`payload` с источником), а не только
с `context`-строкой. Т.е. либо расширить `retrieve()` (отдавать hits), либо извлекать поверх
`searchByBrand()` напрямую и группировать значения по source-url.

## B. Переформулированный дизайн (локаль → RAG → push)

```
app:contacts:refresh --limit=50 --ttl=180 [--full] [--force] [--dry-run] [--daemon --interval=3600]

для каждого выбранного бренда:
  res = BrandRagService::retrieve(brand)          // тот же путь, что app:brand:ask --local
  если res.context == null:        skip           // нет grounding — извлекать нечего
  extracted = LLM-СТРУКТУРНОЕ извлечение из res    // JSON {email, phone, address, socials[]} + source-url каждого
                                                   // (не freeform, как в app:brand:ask)
  для каждого поля:
    Brand.поле == null   И extracted валиден  → заполнить, contactVersion++   (дырка закрыта)
    Brand.поле == extracted (совпало)         → если из ≥2 различных источников:
                                                    пометить confirm-кандидатом → флаг в push-payload
    Brand.поле != extracted, оба не null      → НЕ трогать; записать расхождение в отчёт
    поле не найдено в корпусе (напр. соцсети)  → в очередь ре-fetch own_site (BrandSourceUrl), НЕ выдумывать
```

Подтверждение/провенанс ставит **прод** в `BrandIngestService` при приёме push-payload с
confirm-флагом → бампает `confirmCount`/`state` на нужном датапоинте. Локаль провенанс не ведёт.

### Что переиспользуем (а не строим заново)

| Проблема (из «Проблема» сверху) | Существующий механизм | Что добавить |
|---|---|---|
| Контакты не ревалидируются | `BrandDatapoint.queuedRevalidateAt` + `BrandDatapointRepository::findQueuedForRevalidation()` + `GET /api/v1/revalidation-queue` | по TTL ставить `queuedRevalidateAt` контактным датапоинтам (прод) |
| Hard bounce не триггерит | `BrandOutreach.bouncedAt` (уже «только hard bounce, suppression») + та же очередь | при bounce → `queuedRevalidateAt=now` на email-датапоинт |
| Нет самоподтверждения | `confirmCount`/`provenance`/`PINNED` + `DatapointVoteService` FSM | confirm-сигнал едет в push-payload; прод инкрементит |
| Невалидные данные | `DatapointVoteService` (reject≥3→doubtful, ≥5→hidden, confirm≥5→pinned) | **не** изобретать вторую таблицу очистки (отменяет таблицу v1 «Механизм очистки») |
| Нет метрик | `DailyReportCommand` | секция «Контакты» — ок, оставляем (v1 §7) |
| Извлечение | `BrandRagService::retrieve()` (gate, multi-aspect, приоритет own-site) | структурный JSON-промпт вместо freeform |

`BrandOutreach.bouncedAt` — **единственный** источник правды о bounce. Поле
`contactStatus='bounced'` из v1 §6 не вводим (двойная правда).

## C. Гигиена (фиксы, не переписывание)

| # | Что | Фикс |
|---|-----|------|
| C1 | `--only-email` дефолтом (v1) сужает покрытие vs текущий enrich (email+phone+links+stores) | дефолт `--full`; `--only-email` — опционально для онбординг-прохода |
| C2 | `extractEmailFromText()` через LLM в часовом демоне (v1 §5) дорог/медлен | email — regex + `ContactVerifier::validateEmail()` (уже есть); LLM только fallback для обфускации `at`/`dot`. Структурное извлечение прочих полей — поверх уже полученного RAG-`context`, без отдельного скрейпа |
| C3 | `--daemon` без `--no-debug`/`em->clear()` → OOM (профайлер Doctrine, см. CLAUDE.md) | в демоне обязательно `em->clear()` по батчам + `memory_limit=512M --no-debug`, как `RagDaemonCommand`/`EnrichBrandContactsCommand` |
| C4 | Нет claim/локов: демон + cron `enrich-contacts` → двойная обработка | `SKIP LOCKED`-claim (как RAG-fetch) или взаимоисключение |
| C5 | Priority-выборка по `contactEnrichedAt+TTL`/`contactStatus`/`bouncedAt` на 6227 брендах без индексов | индекс на `brand(contact_status, contact_enriched_at)` |
| C6 | «Залогировать разницу» (v1 стр. 48) без приёмника | сток расхождений — отчёт прохода + (на проде) `state=doubtful` на датапоинте |
| C7 | Метрики «342/779» (v1 §7) — устаревший знаменатель | знаменатель = реальные 6227 брендов |
| C8 | Противоречие «заменяет enrich» (§4) vs «не меняем enrich» (раздел в конце) | refresh **дополняет**, не заменяет; перенести из `applyContacts()` дедуп ссылок (тип+URL), нормализацию, обработку магазинов, HTTP-верификацию website |

## D. Корпус неполон — отдельная ветка (подтверждено на Телодвижении)

Соцсети есть на сайте, но фетч их не достал → RAG вернул только ник. Refresh **не выдумывает**
недостающее: помечает `own_site` бренда в очередь ре-discover/ре-fetch (`BrandSourceUrl`).
Сам скрейп (Pinterest/VK/SPA, trafilatura ~12% на Yandex-Store SPA) — отдельная проблема,
вне этой команды (как и в v1, раздел «Что НЕ меняем»).

## E. Что меняется в списке файлов v1

- **Отменить:** §1 (поля на `Brand`), §9 (миграция), таблицу «Механизм очистки», `PROV_*`-константы из §2, `contactStatus='bounced'` из §6.
- **Переориентировать:** §4 — команда поверх `BrandRagService::retrieve()` + структурный extract (не скрейп); §5 — `extractContactsFromContext()` (JSON, поверх RAG-context), не `extractEmailFromText()`.
- **Оставить:** §3 (`findForContactRefresh()`), §7 (секция «Контакты» в дайджесте), §8 (push не шлёт bounced email; теперь ещё везёт confirm-флаг).
- **Добавить:** прод-сторону — в `BrandIngestService` обработку confirm-флага (бамп `confirmCount`/`state` на датапоинте) и TTL-постановку `queuedRevalidateAt`.

## F. Онбординг: протухший email и кросс-брендовая ссылка (кейс Телодвижения)

Онбординг = при публикации бренда `BrandOutreachMailer::send()` шлёт `brand_published`
на `Brand.email`. Шаблон + suppression проверяются, **свежесть/корректность — нет**.
На Телодвижении: в БД протухший email, а в корпусе — актуальный; плюс в ссылках бренда —
ссылка на **чужой** бренд.

### F1. Правило «при расхождении НЕ трогать» ломает онбординг — нужно различать по провенансу

Правило из v1 (стр. 46) и из моего B («mismatch → не трогать») безопасно против шума, но
**замораживает протухший email навсегда** → онбординг-письмо уходит в никуда. Это и есть баг.
Безопасный апдейт правила различает источник текущего значения:

| Текущее `Brand.поле` | Корпус даёт другое (grounding силён: ≥2 own-site источника) | Действие |
|---|---|---|
| `provenance=owner` (правка в ЛК) | — | **никогда** не трогаем (owner-guard, уже есть в `BrandIngestService`) |
| `provenance=enrichment`/`import` | да | **заменяем** (корпус свежее enrichment); старое → в отчёт |
| провенанса нет (локаль, см. A1) | да | локально **не** перезаписываем вслепую (рискуем затереть owner-правку) → помечаем «proposed replacement» + источники → решение принимает прод-ingest по провенансу |

То есть «не трогать» сужается до «не трогать `owner`». Для `enrichment`/`import` сильный
own-site-корпус **побеждает**. На локали (провенанса нет) замену не делаем молча — отправляем
кандидата на замену в push-payload, а прод применяет с учётом owner-guard.

### F2. Гейт свежести перед онбординг-письмом

Перед публикацией (в `PublishTickCommand`/перед `BrandOutreachMailer::send()`) — сверка
`Brand.email` с корпусом:

```
если корпус (own-site, ≥2 источника) уверенно даёт ДРУГОЙ email, чем Brand.email:
   • НЕ слать письмо на протухший адрес
   • заменить на корпусный (если Brand.email не owner) ИЛИ придержать публикацию + флаг на ревью
```

Это превращает «актуализацию email» из фоновой задачи в **жёсткое предусловие онбординга**:
письмо не уходит на адрес, который корпус опровергает.

### F3. Кросс-брендовая ссылка — нужна проверка принадлежности бренду

`BrandRagService::isOwnSite()` смотрит только `source_type`, без проверки, что домен реально
принадлежит **этому** бренду (та же дыра, что «Pinterest gate31 как own_site» в «Проблемах корпуса»).
Ссылка на чужой бренд → её контакты заражают извлечение.

**Фикс при извлечении контактов:** значение, source-url которого не проходит проверку
принадлежности бренду, **не** считать подтверждением и не подмешивать в `Brand`:
- домен ссылки совпадает с `Brand.website`/`{slug}`-доменом, **или**
- корпус упоминает имя бренда на этой странице.

Не прошло — ссылка/контакт демотируется (на проде → `state=doubtful`/`hidden` + очередь
ре-discover), но **не удаляется** (soft-delete политика). Аудит чужих ссылок в существующих
данных — отдельный одноразовый проход, вне демона.

## G. TG-карточка одобрения публикации (human-in-the-loop, 1 тап)

> Резолюция открытого вопроса F2: **не «авто vs придержать», а «авто-актуализация + один тап».**
> Система делает всю работу (извлечение из корпуса, замена протухшего, флаги расхождений и
> чужих ссылок), а решение «публиковать / отклонить» — единственный ручной шаг, в один тап в TG.

### Точка гейта — вход в дрип-очередь, не каждый тик

Сейчас бренд готов → `publishPending=true` → `PublishTickCommand` (cron, ORDER BY RAND())
активирует. Гейт встраивается **перед** очередью, чтобы не ломать дрип-каденс:

```
бренд isPublishReady  →  app:contacts:refresh актуализирует контакты  →  approvalState='pending'
                      →  TG-карточка админу
   ✅ Опубликовать  →  approvalState='approved', publishPending=true   →  дрип-cron публикует сам
   ❌ Отклонить     →  approvalState='rejected', publishPending=false  →  не в очереди, в отчёт
```

Approve гейтит **вход** в очередь — дальше дрип-каденс (5→28/день, окно 9–23 МСК) не трогаем.

### Карточка

Шлётся автоматически, когда контакты актуализированы. Содержит всё для решения без захода в админку:
```
🏷  Телодвижения  (ID 1)
✉️  help@telodvigeniya.ru   ⚠️ заменён: info@… → help@… (корпус, 2 ист.)
☎️  —  (нет в корпусе)
📍  г. Энгельс, ул. Пролетарская, 19
🔗  vk.com/telodvigeniia  ⚠️ соцсети не дофетчены — в очереди ре-fetch
⛔  отброшена чужая ссылка: pinterest.com/gate31 (не принадлежит бренду)
📊  grounding: chunks=5, score 0.61, own-site ист.: 2
[ ✅ Опубликовать ]  [ ♻️ Переобогатить ]  [ ❌ Отклонить ]
```

♻️ **Переобогатить** → бренд в очередь ре-обогащения (см. §H), карточка приходит заново с
обновлёнными данными. `callback_data`: `pub:e:{brandId}:{hmac}`. Кнопка для случая «email под
вопросом, замены в корпусе нет» (как телефон Телодвижения) — вместо публикации со старым адресом.

### Инфра — что есть и что доделать

| Есть | Доделать |
|---|---|
| `TelegramNotifier::send()` (текст, HTML) | расширить: `reply_markup` с `inline_keyboard` (callback-кнопки) |
| `TelegramController` `/telegram/webhook` (обрабатывает `message`) | ветка `callback_query`: парс `callback_data` → решение → `editMessageReplyMarkup` (убрать кнопки, показать вердикт) |
| `AdminNotifier` → `ADMIN_TELEGRAM_CHAT_ID` | слать карточку через него |
| `Brand.publishPending` | добавить `approvalState` ∈ {pending, approved, rejected} |

`callback_data` (TG-лимит 64 байта): `pub:a:{brandId}:{hmac}` / `pub:r:{brandId}:{hmac}`.

### Безопасность (вебхук — PUBLIC_ACCESS, нельзя доверять вслепую)

1. **Secret-token**: `setWebhook(secret_token=…)` + сверка заголовка `X-Telegram-Bot-Api-Secret-Token` (сейчас `setWebhook()` без него — добавить).
2. **HMAC** в `callback_data` от `(brandId, action, APP_SECRET)` — защита от подделки колбэка.
3. **Только админ**: `callback_query.from.id` === `ADMIN_TELEGRAM_CHAT_ID`, иначе игнор.
4. **Идемпотентность**: если `approvalState != 'pending'` — no-op (повторный тап/ретрай TG), отвечаем `answerCallbackQuery` «уже обработано».
5. **TTL**: карточка-кандидат протухает (напр. 7д) → требует повторной актуализации.

### Топология

Публикация и карточка — **прод** (там дрип-cron и `ADMIN_TELEGRAM_CHAT_ID`). Локальный
`app:contacts:refresh` готовит данные + флаги; они едут в push-payload; прод формирует карточку
при приёме бренда в `pending`. Отклонённые/«нет контактов» — в дайджест (секция «Контакты»).

### Файлы (дополнительно к §E)

- `Brand.approvalState` + миграция (ENUM-строка, default `pending`).
- `TelegramNotifier`: перегрузка `send()` с `reply_markup`; `secret_token` в `setWebhook()`.
- `TelegramController`: обработка `callback_query` (валидация → флип `approvalState` → `publishPending` → edit message).
- `BrandIngestService`: при приёме `pending`-бренда — отправка карточки (или постановка в очередь карточек).
- `PublishTickCommand`: выбор только `approvalState='approved'` (уже неявно через `publishPending`, но добавить явный фильтр для ясности).

## H. «Переобогатить» — что менять, чтобы re-enrich не был no-op

> Главное требование: повторный прогон должен **менять входы**, иначе вернётся тот же корпус.
> Сверено с кодом — наивный re-enrich сейчас бесполезен по трём причинам:
> - `FetchBrandSourcesCommand` дренирует только `status IN (pending, claimed)`; URL уже `fetched` → пропущен.
> - `WebScraperService::fetch()` — кеш 30д, **нет** cache-bust; вернёт те же байты.
> - скрейпер «только статический HTML» → SPA/соцсети (Instagram, Yandex-Store) так и дадут ~12%.
> - Perplexity Sonar fallback **удалён** (`EnrichBrandContactsCommand:177`, 2026-06-04) — рычага нет.

### Что меняем на каждый re-enrich (хотя бы один вход — иначе no-op)

| Рычаг | Что делает | Что доработать в коде |
|---|---|---|
| **1. Сброс очереди** | own_site/own_page URL `fetched → pending`, чтобы fetch их перечитал | новая `app:brand:reenrich --id=N`: `UPDATE brand_source_url SET status='pending' WHERE brand_id=N AND type IN (own_site,own_page)` |
| **2. Cache-bust fetch** | перечитать сайт живьём (он мог измениться) | `WebScraperService::fetch($url, force=true)` — обход 30д-кеша (сейчас параметра нет) |
| **3. Gap-targeted discover** | новые SearXNG-запросы под **дыру** из карточки (нет телефона → «{бренд} телефон контакты»; нет соцсетей → «{бренд} instagram vk») | в `DiscoverBrandSourcesCommand`/`BrandSourceFinder` — режим запросов по недостающим полям, не общий повтор (общий повтор INSERT IGNORE → 0 нового) |
| **4. Сброс яда** | удалить кросс-брендовые/демотированные `BrandSourceDocument` + их URL → не отравляют извлечение и не воскреснут | по флагам из §F3 |

После 1–4: `discover → fetch → embed → retrieve → extract` → новая карточка.

### Граница — иначе кнопка крутится вечно

Если раунд re-enrich не дал **ни одного нового** `BrandSourceDocument` (dry-счётчик, как
loop-until-dry в RAG) — стоп, поле помечается `corpus-exhausted`, карточка приходит с пометкой
«переобогащение нового не нашло» и **без** кнопки ♻️ (только ✅/❌). Не обещаем то, что корпус не может дать.

### Честный предел

SPA/соцсети (Instagram, Yandex-Store SPA) без **headless-фетчера** повторный проход не вытащит —
это net-new возможность, не флаг. До неё ♻️ помогает только с **статическими own_site**
(перечитать живьём + gap-запросы), что и есть кейс «соцсети есть на сайте, но фетч не достал»:
сброс кеша + перечтение own_site-страницы со ссылками. Headless-фетчер — отдельная задача бэклога.

### Топология (prod-кнопка → local-движок)

RAG-стек (discover/fetch/embed, LLM-сервер) — **локальный**; кнопка нажата на **проде**. Канал
prod→local — это **существующая** очередь ревалидации (§B): `BrandDatapointRepository::findQueuedForRevalidation()`
+ `GET /api/v1/revalidation-queue`. ♻️ ставит `queuedRevalidateAt` (+ список дыр) → локальный
агент опрашивает очередь → запускает `app:brand:reenrich` с gap-инфой → пушит новую карточку.
То есть кнопка — ручной триггер в уже существующий pull-конвейер, новый канал не нужен.

### Файлы (дополнительно к §G)

- `app:brand:reenrich --id=N --gaps=phone,social` — оркестратор рычагов 1–4 (локально).
- `WebScraperService::fetch()` — параметр `force`/cache-bust (рычаг 2).
- `DiscoverBrandSourcesCommand`/`BrandSourceFinder` — gap-targeted запросы (рычаг 3).
- `TelegramController` — ветка `pub:e:` → `queuedRevalidateAt` + гэпы в очередь ревалидации.
- dry-счётчик раундов на бренд (поле или из `revalidatedAt` без новых доков) → `corpus-exhausted`.
