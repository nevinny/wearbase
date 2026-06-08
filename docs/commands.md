# Справочник console-команд WEARBASE

Ревизия на 2026-06-08. Всего **34** команды (`src/Command/*.php`).

## Как читать

**Где запускать** (инфра расщеплена — см. CLAUDE.md):
- 🖥 **.43** — локальный LLM-сервер (ollama/Qdrant/SearXNG/trafilatura). Весь RAG-стек.
- ☁️ **prod** — боевой сервер (regru), каталог, публикация. Telegram заблокирован.
- 🍎 **Mac** — машина разработчика. Telegram доступен только отсюда.

**Как часто:**
- ⏰ **cron** — по расписанию (приведено).
- 🔁 **демон/фон** — долгоживущий процесс или фоновый батч по мере наполнения базы.
- 👆 **по запросу** — вручную, когда нужно.
- 1️⃣ **разово** — one-off (миграция/фикс/бэкофилл); после исполнения не нужна.

> ⚠️ Долгие батчи — всегда `--no-debug` (иначе OOM на dev-профайлере Doctrine, см. CLAUDE.md).

---

## ⏰ Что реально в cron (сводка)

| Команда | Расписание | Где | Назначение |
|---|---|---|---|
| `app:brand:publish-tick` | `0 * * * *` (раз в час) | ☁️ prod | дрип-публикация брендов |
| `app:report:pipeline` | `0 */3 * * *` (раз в 3ч) | 🖥/🍎 | сводка RAG-конвейера в TG |
| `app:report:daily` | `17 9 * * *` (ежедневно) | 🍎 Mac | дайджест публикаций+GSC в TG |
| `app:gsc:sync` | `0 6 * * *` (ежедневно) | 🖥 .43 | синк Google Search Console |
| `app:currency:update-rates` | `0 12 * * *` (ежедневно) | ☁️ prod | курсы валют ЦБ РФ |
| `app:subscription:expire` | ежедневно (рекоменд.) | ☁️ prod | истечение подписок |
| `app:brand:enrich-contacts` | `*/10 * * * *` (легаси) | 🖥 .43 | обогащение контактами |
| `app:rag:daemon` | непрерывно (или supervised) | 🖥 .43 | оркестратор RAG-стадий |

---

## 1. RAG-конвейер (генерация контента брендов)

Поток: `discover → (crawl) → fetch → embed → keywords → generate-content → faq`.
Статус-машина в `brand_rag_pipeline`. Запускаются либо через демон-оркестратор, либо
фоновыми батчами с шардингом. Все — 🖥 **.43**.

| Команда | Зачем | Как часто |
|---|---|---|
| `app:rag:daemon` | Оркестратор: бесконечный цикл, каждая стадия — отдельным дочерним процессом (память освобождается ОС). Два демона по типу ресурса: `discover,fetch` (сеть/CPU) и `embed,generate` (GPU). | 🔁 непрерывно |
| `app:brand:discover` | Этап 0: SearXNG/DB-ссылки → URL-кандидаты в очередь `brand_source_url` (без скачивания). Cap'ы по типу источника. | 🔁 фон (через демон) |
| `app:brand:crawl` | Этап 0.5: для брендов с own_site разворачивает sitemap/ссылки в `own_page` → дренит обычный fetch. Прокси не нужен. | 🔁 фон |
| `app:brand:fetch` | Этап 1: дренит очередь URL → скачивает текст (trafilatura) → `brand_source_document` (кеш 30д, дедуп по content_hash). Финализирует pipeline в `scraped`. | 🔁 фон (через демон) |
| `app:brand:scrape` | **Легаси-монолит** discover+fetch в одном проходе. Fallback по `--id`. | 👆 по запросу |
| `app:brand:embed` | Этап 2: чанки → эмбеддинги (bge-m3) → Qdrant (`brand_chunks`). Статус → `embedded`. | 🔁 фон (через демон) |
| `app:brand:extract` | Извлечение атрибутов (стили/категории/размеры/гео) из краула → `brand_attribute` (qwen). | 🔁 фон |
| `app:brand:keywords` | Wordstat-ключевики → `brand_keyword` (заранее, для генерации). **Квота 100/час** — сам встаёт на паузу; НЕ шардить (квота общая). | 🔁 долгий процесс в окне |
| `app:brand:generate-content` | Генерация описания + SEO-meta (RAG-grounded если корпус прошёл gate, иначе legacy). `--grounded-only` → бренд в `deferred` вместо воды. Статус → `done`. | 🔁 фон (GPU-стадия) |
| `app:brand:faq` | SEO: FAQ из Wordstat-фраз, grounded-ответы 27b → `brand_faq` + FAQPage JSON-LD. Без ключевиков → `skipped`. | 🔁 фон |
| `app:brand:wb-enrich` | Ингест товаров с Wildberries в корпус + переэмбедд + регенерация grounded-описания. | 👆 по запросу |
| `app:brand:ask` | **Диагностика**: задать вопрос про бренд через RAG (Qdrant+LLM). Проверить, что в корпусе. | 👆 по запросу |
| `app:brand:pipeline:reset-phantoms` | **Ремонт**: сброс фантомных pipeline-статусов (прогресс заявлен, документов нет). Dry-run по умолчанию. | 👆 по запросу |

---

## 2. Доставка и публикация (dev → prod)

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:brand:push` | Доставка готовых брендов (`isPublishReady`) на прод через `/api/v1/brands/upsert` (HMAC). Приземляются как `new`+`publish_pending`. | 🔁 фон / 👆 ре-пуш с `--force` | 🖥 .43 |
| `app:brand:publish-tick` | Дрип-публикация: часовой тик с ramp-up (5→28/день), окно 9–23 МСК, случайный выбор. Имитирует ручной ввод (анти-SpamBrain). | ⏰ `0 * * * *` | ☁️ prod |

---

## 3. Контакты брендов

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:contacts:refresh` | Актуализация контактов из RAG-корпуса (новый конвейер, см. `_docs/contacts-refresh-plan.md`). TTL-ревалидация, демон-режим. | ⏰/🔁 | 🖥 .43 |
| `app:brand:enrich-contacts` | **Легаси**: разовое обогащение из скрейп-корпуса (27b). Терминальные статусы, HTTP-проверка URL. Вытесняется `contacts:refresh`. | ⏰ `*/10` (пока) | 🖥 .43 |

---

## 4. Outreach (email-активация брендов)

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:outreach:send` | Warmup: activation-письма когорте A (опубликованные бренды с данными), малыми батчами (10→15→25). После warmup — авто-врезка в publish-tick (`OUTREACH_AUTO=1`). | 👆 ручные батчи (warmup) | ☁️ prod |
| `app:outreach:test` | Тест рендера письма на указанный адрес (RuSender REST), без записи в БД. | 👆 по запросу | 👆 |

---

## 5. Отчёты и мониторинг

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:report:pipeline` | Сводка RAG-конвейера в TG: парсинг/генерация/ключевики/готовность + темпы за час. | ⏰ `0 */3 * * *` | 🖥/🍎 (TG) |
| `app:report:daily` | Ежедневный дайджест: публикации прода (агент-API) + индексация GSC. **Только Mac** (TG заблокирован на .43 и проде). | ⏰ `17 9 * * *` | 🍎 Mac |
| `app:brand:stats` | Статистика по брендам (консоль). | 👆 по запросу | 👆 |
| `app:brand:check-content` | Проверка качества контента (`--type=description\|meta\|all`, `--export` в JSON). | 👆 по запросу | 🖥 |

---

## 6. SEO / Google Search Console

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:gsc:sync` | Search Analytics → `gsc_page_stats` + URL Inspection (cap 1500/день, приоритет свежим) → `gsc_index_status`. Fail-open. | ⏰ `0 6 * * *` | 🖥 .43 |
| `app:gsc:auth` | **Разовый** OAuth (refresh_token) вместо запрещённого SA-ключа. | 1️⃣ при настройке | 🖥 |

---

## 7. Подписки / биллинг

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:subscription:expire` | Истечение trial/active подписок после end_date (→ past_due/expired) + уведомление владельцам. | ⏰ ежедневно | ☁️ prod |
| `app:subscription:backfill` | Free-trial подписка legacy-брендам с владельцем, но без подписки. Идемпотентно. | 1️⃣ разово/редко | ☁️ prod |

---

## 8. Импорт, обслуживание, one-off

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:currency:update-rates` | Курсы валют из ЦБ РФ (cbr.ru) / Fixer.io → `exchange_rate`, сброс кеша. | ⏰ `0 12 * * *` | ☁️ prod |
| `app:import:brands` | Импорт брендов с russianstreetwear.club. | 1️⃣ / 👆 | 🖥 |
| `app:import:brand-media` | Импорт изображений и ссылок брендов с russianstreetwear.club. | 1️⃣ / 👆 | 🖥 |
| `app:fetch:lamoda-brands` | Скрейп списка брендов Lamoda → JSON. | 1️⃣ / 👆 | 🖥 |
| `app:brand:fix-slugs` | **Разовый фикс**: транслитерация кириллических слагов (инцидент 06-2026). ⚠️ один алгоритм на dev И проде. | 1️⃣ | 🖥+☁️ |
| `app:migrate-images-to-subdirs` | **Разовая миграция**: плоское хранилище → `ab/cd/` (Vich SubdirNamer). | 1️⃣ | 🖥/☁️ |
| `app:seed:test-products` | Тестовые товары для проверки карточки/заказа. | 👆 dev/тест | 🖥 |

---

## Ревизия — наблюдения

**Дубли / перекрытия:**
1. **`app:brand:scrape` vs `discover`+`fetch`** — scrape это легаси-монолит того же, что делают раздельные этапы 0/1. Используется только как fallback по `--id`. Кандидат на пометку `@deprecated` или удаление, когда демон закроет все кейсы.
2. **`app:brand:enrich-contacts` vs `app:contacts:refresh`** — явно объявлено вытеснение. Держать обе нет смысла после миграции; дать enrich-contacts `@deprecated`-докблок и срок снятия из cron.

**Слабая документированность (нет докблока — для справочника пришлось читать код):**
`app:brand:ask`, `app:brand:stats`, `app:brand:check-content`, `app:brand:generate-content`,
`app:brand:wb-enrich`, `app:import:brands`, `app:import:brand-media`, `app:fetch:lamoda-brands`,
`app:subscription:expire`, `app:seed:test-products`. Стоит добавить хотя бы 2–3 строки «зачем/как часто».

**Несогласованность нейминга:**
- `app:fetch:lamoda-brands` (глагол:объект) против общего паттерна `app:brand:*`. Логичнее `app:import:lamoda-brands` рядом с другими импортами.
- `app:migrate-images-to-subdirs` без namespace-группы (одиночка). Для one-off ок, но стоит вынести в `app:maint:*` или пометить разовость в описании.

**Противоречие по Telegram-инфре (требует проверки):**
`report:daily` утверждает «TG заблокирован с .43 и прода», а `report:pipeline` имеет cron-пример с пути `/home/zyablik/wearbase` (похоже на .43) и шлёт в TG. Уточнить, откуда реально ходит `report:pipeline` — иначе его cron на .43 молча не доставляет.

**One-off без защиты от повторного запуска:**
`fix-slugs` и `migrate-images` идемпотентны (ок). `import:brands`/`import:brand-media`/`fetch:lamoda-brands` — проверить дедуп перед повторным прогоном.

**Не в cron, но критично для наполнения базы:**
RAG-стадии (`discover`/`fetch`/`embed`/`generate-content`/`faq`/`keywords`) живут под `rag:daemon` или ручными батчами. Если демон не supervised (systemd/pm2) — наполнение встаёт молча. Стоит зафиксировать запуск демона как сервис (см. devops).
