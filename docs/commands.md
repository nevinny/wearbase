# Справочник console-команд WEARBASE

Ревизия на 2026-06-24. Всего **42** команды (`src/Command/*.php`).

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
| `app:report:weekly` | `0 10 * * 1` (пн) | 🍎 Mac | дайджест видимости неделя-к-неделе (GSC+Яндекс+публикации) в TG |
| `app:gsc:sync` | `0 6 * * *` (ежедневно) | 🖥 .43 | синк Google Search Console |
| `app:seo:attach-distribution` | `40 5 * * *` (ежедневно) | 🍎 Mac | подстраховка: привязка копий статей под площадки (var/seo/**) — основной путь автопривязки — сразу при `app:seo:publish-blog`, см. [seo_publishing_strategy.md](seo_publishing_strategy.md) §6б |
| `app:google:index-ping` | `0 7 * * *` (ежедневно) | 🍎 Mac | пинг Google Indexing API (≤200/день) |
| `app:seo:aio-remediate` | `40 8 * * 1` (пн) | 🍎 Mac | closed-loop ремедиация AIO-утечки: thin→генерация / rich→gap-FAQ (`--apply --limit=10 --notify`) |
| `app:seo:evaluate-experiments` | `0 10 * * *` (ежедневно) | 🍎 Mac | замер ревизий контента → вердикт/откат (closed-loop) |
| `app:currency:update-rates` | `0 12 * * *` (ежедневно) | ☁️ prod | курсы валют ЦБ РФ |
| `app:subscription:expire` | ежедневно (рекоменд.) | ☁️ prod | истечение подписок |
| `app:brand:enrich-contacts` | `*/10 * * * *` (легаси) | 🖥 .43 | обогащение контактами |
| `app:rag:daemon` | непрерывно (под autoscale) | 🍎 Mac | оркестратор RAG-стадий (net + gpu, шарды) |
| `app:rag:autoscale` | `*/3 * * * *` | 🍎 Mac | супервизор baseline + burst по очередям + health-gate |
| `app:social:plan` / `generate` / `publish-tick` | `6:00` / `*/30` / `0 * * * *` | 🍎 Mac | контент-сетка → наполнение → дрип-публикация (TG/VK) |
| `app:social:ingest-clicks` | `30 7 * * *` (ежедневно) | 🍎 Mac | closed-loop: UTM-клики из nginx-логов прода (ssh+zgrep) → `social_post_metric.link_taps` |
| `app:outreach:warm-refresh` | `30 8 * * 1` (пн) | 🍎 Mac | SALES-LOOP: тёплые лиды (клики из GSC) → драфты письма-оффера 5000₽ + сводка в TG (человек-гейт, отправка вручную) |
| `app:seo:gap-report` | `0 8 * * 1` (пн) | 🍎 Mac | автопилот position-gap листа (`--notify`): yandex/gsc `position>10` + спрос → группы интента + сводка в TG |

### 🍎 Mac-крон: одна точка входа + расписание в БД

На Mac больше нет россыпи строк в `crontab`. Вместо неё — **один** тикающий раз в минуту
вызов `app:cron:run-scheduled` (на Mac — строкой `* * * * *` в crontab; launchd-вариант
лежит в `ops/com.wearbase.cron.plist`, но на этой машине не грузится — `$HOME` на внешнем
томе → EIO 5). Команда смотрит таблицу `scheduled_command` и запускает задачи, чьё
cron-время наступило (`CronExpression::isDue`, зона **Europe/Moscow**), пишет трекинг
(last_run/exit/duration/output/next_run) и держит глобальный `flock`, чтобы тики не
наложились. Управление — из админки **/admin → «Крон (расписание)»** (DELETE отключён,
выключение через `enabled`). Тяжёлые RAG-батчи сюда НЕ ставим — их по-прежнему гоняем `nohup`.

**Разделение по окружениям.** У каждой задачи есть поле `environment` (`dev` = Mac, `prod`
= regru, `llm` = .43). Диспетчер на каждой машине читает `CRON_ENV` (`.env`, переопределяется
в `.env.local`; пусто = `dev`) и берёт **только свои** строки. Так одна и та же таблица
обслуживает все три хоста: ставишь крон в общей админке, помечаешь окружением — он
исполнится там, где надо. `.43` сейчас выключен — его строки просто ждут, пока поднимется
его диспетчер.

```bash
php bin/console app:cron:run-scheduled --dry-run   # что due прямо сейчас
# установка агента (из своего Terminal, не из IDE/headless):
cp ops/com.wearbase.cron.plist ~/Library/LaunchAgents/ \
  && launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.wearbase.cron.plist
```

Сейчас засеяны три прежних Mac-задачи: `app:gsc:sync`, `app:report:pipeline`, `app:report:daily`.

---

## 1. RAG-конвейер (генерация контента брендов)

Поток: `discover → (crawl) → fetch → embed → keywords → generate-content → faq`.
Статус-машина в `brand_rag_pipeline`. Запускаются либо через демон-оркестратор, либо
фоновыми батчами с шардингом. Все — 🖥 **.43**.

| Команда | Зачем | Как часто |
|---|---|---|
| `app:rag:daemon` | Оркестратор: бесконечный цикл, каждая стадия — отдельным дочерним процессом. Базовые наборы: net `discover,crawl,fetch,logo,push` + gpu `embed,generate,enrich,faq,extract` (раздельны → GPU не голодает). `--shard/--total` — параллельные шарды (lock учитывает shard). | 🔁 непрерывно |
| `app:rag:autoscale` | **Супервизор + автоскейл**: держит 1 baseline-net + 1 baseline-gpu (gpu только при живом .119, иначе net-only — не жжём attempts), на заторах поднимает burst-воркеры по глубине очереди (cap по ядрам). health-gate: варнинг, если GPU-очередь копится при мёртвом сервере. Реконсайл = масштаб + респаун. | ⏰ `*/3 * * * *` (Mac) |
| `app:brand:discover` | Этап 0: SearXNG/DB-ссылки → URL-кандидаты в очередь `brand_source_url` (без скачивания). Cap'ы по типу источника. | 🔁 фон (через демон) |
| `app:brand:crawl` | Этап 0.5: для брендов с own_site разворачивает sitemap/ссылки в `own_page` → дренит обычный fetch. Прокси не нужен. | 🔁 фон |
| `app:brand:fetch` | Этап 1: дренит очередь URL → скачивает текст (trafilatura) → `brand_source_document` (кеш 30д, дедуп по content_hash). Финализирует pipeline в `scraped` (**0 корпуса → `dead`**). Мёртвые/недоступные URL → `skipped` (+`http_status` для триажа: 403/404/null). | 🔁 фон (через демон) |
| `app:brand:rediscover` | Сброс брендов с мёртвыми источниками обратно в discover: мусорные URL → `skipped` (soft, остаются для дедупа), pipeline → pre-discover. | 👆 по запросу |
| `app:brand:scrape` | **Легаси-монолит** discover+fetch в одном проходе. Fallback по `--id`. | 👆 по запросу |
| `app:brand:embed` | Этап 2: чанки → эмбеддинги (bge-m3) → Qdrant (`brand_chunks`). Статус → `embedded`. | 🔁 фон (через демон) |
| `app:brand:extract` | Извлечение атрибутов (стили/категории/размеры/гео) + **city и год основания** (→ `brand.city`/`foundingYear`) из корпуса. `--fields-only` — только city/год (backfill без churn атрибутов). | 🔁 фон |
| `app:brand:keywords` | Wordstat-ключевики → `brand_keyword` (заранее, для генерации). **Квота 100/час** — сам встаёт на паузу; НЕ шардить (квота общая). | 🔁 долгий процесс в окне |
| `app:brand:generate-content` | Генерация описания + SEO-meta (RAG-grounded если корпус прошёл gate, иначе legacy). `--grounded-only` → бренд в `deferred` вместо воды. Статус → `done`. | 🔁 фон (GPU-стадия) |
| `app:brand:faq` | SEO: FAQ из Wordstat-фраз, grounded-ответы 27b → `brand_faq` + FAQPage JSON-LD. Без ключевиков → `skipped`. | 🔁 фон |
| `app:brand:wb-enrich` | Ингест товаров с Wildberries в корпус + переэмбедд + регенерация grounded-описания. | 👆 по запросу |
| `app:brand:ask` | **Диагностика**: задать вопрос про бренд через RAG (Qdrant+LLM). Проверить, что в корпусе. | 👆 по запросу |
| `app:brand:pipeline:reset-phantoms` | **Ремонт**: сброс фантомных pipeline-статусов (прогресс заявлен, документов нет). Dry-run по умолчанию. | 👆 по запросу |
| `app:brand:niche-check` | **Ниша-гейт**: классифицирует бренд (мода+красота = `in`, аптека/техника/авто/продукты/гигиена рта/БАД = `off`) фаст-путём по маркерам + локальной LLM → `brand.niche_status`. `off` исключает бренд из всего конвейера (`PipelineQueueRepository`) и дрип-публикации. Недеструктивен; `--set=in\|off\|closed\|reopen\|delete` (с `--id`) — ручное действие/override. Active off-niche только помечает (ручное ревью). Большие батчи: `-d memory_limit=512M … --no-debug`. | ⏰ перед `publish-tick` / 👆 бэкафилл |

> **Порядок cron:** `app:brand:niche-check` должен идти **перед** `app:brand:publish-tick` — гейт пропускает непроверённые (`niche_status IS NULL`), чтобы не стопорить дрип; пометка `off` должна успеть до публикации. Бэкафилл всего каталога — разовым батчем: `php -d memory_limit=512M bin/console app:brand:niche-check 7000 --no-debug`.

---

## 2. Доставка и публикация (dev → prod)

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:brand:push` | Доставка готовых брендов (`isPublishReady`) на прод через `/api/v1/brands/upsert` (HMAC). Приземляются как `new`+`publish_pending`. `--id=N --publish` — приоритетная публикация ручного бренда сразу (минуя дрип, `/api/v1/brands/publish` + IndexNow; `published_at` входит в дневной таргет ramp'а). | 🔁 фон / 👆 ре-пуш с `--force` | 🖥 .43 |
| `app:brand:publish-tick` | Дрип-публикация: часовой тик с ramp-up (5→28/день), окно 9–23 МСК, случайный выбор. Имитирует ручной ввод (анти-SpamBrain). При публикации вплетает бренд в жёсткий граф перелинковки (fail-open). | ⏰ `0 * * * *` | ☁️ prod |
| `app:brand:build-link-graph` | Жёсткий граф «Похожих брендов» (`brand_related`, 5 исходящих + гарантия ≥2 входящих, нет сирот). Qdrant-эмбеддинги → стили → город → fill. Идемпотентна: существующие рёбра не трогает; `--rebuild` — снести и заново. См. `docs/seo_adoption_plan.md` п.2. | 👆 после массовых публикаций / смены статусов | 🖥 Mac (нужен Qdrant; без него — SQL fallback) |

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
| `app:outreach:warm-refresh` | **SALES-LOOP** (docs/sales_offer.md): тёплые лиды = active-бренды с реальными кликами/показами из поиска за 28 дней (`gsc_page_stats`, дедуп дублей page_url-вариантов), которым ещё не готовили этот драфт (`outreach_log`). Шаблонные (не LLM) письма-офферы 5000₽ с цифрами трафика + похожими брендами (стиль/город) → `var/outreach/warm-YYYY-MM-DD.md` + сводка в TG. **Человек-гейт: писем не шлёт**, отправка — ручное решение владельца. | ⏰ `30 8 * * 1` (пн) | 🍎 Mac |

---

## 5. Отчёты и мониторинг

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:report:pipeline` | Сводка RAG-конвейера в TG: парсинг/генерация/ключевики/готовность + темпы за час. | ⏰ `0 */3 * * *` | 🖥/🍎 (TG) |
| `app:report:daily` | Ежедневный дайджест: публикации прода (агент-API) + индексация GSC. **Только Mac** (TG заблокирован на .43 и проде). | ⏰ `17 9 * * *` | 🍎 Mac |
| `app:report:weekly` | Недельный дайджест видимости в поиске (неделя-к-неделе): GSC (индекс/показы/клики/позиция), Яндекс (в поиске + топ-500 фраз), публикации прода. Всё из `state_snapshot` (пишет `app:advisor:snapshot`) + `gsc_page_stats`; сравнивает последний снимок с ближайшим к −7д (fallback — самый старый, пока история <7д). **Только Mac.** | ⏰ `0 10 * * 1` (пн) | 🍎 Mac |
| `app:seo:gap-report` | Автопилот position-gap листа (docs/yandex_ai_visibility_monitoring.md) — раньше два ручных SQL по пятницам. `yandex_query_stats` (последний `date_to`) + `gsc_query_stats` (сумма по окну), `position>10 AND shows/impressions>0`, группировка по интенту тем же `AioQueryClassifier`, что `app:seo:aio-queries`: brand_entity / replace_comparison / geo_category / navigation (совпал title/slug опубликованного бренда) / other. Пишет снапшот `seo_gap_snapshot` (по `source`+`intent_group`, для тренда неделя-к-неделе), не мутирует бренды. `--notify` шлёт компактную сводку в TG тем же `AdminNotifier`, что `app:seo:aio-remediate` — **отдельный крон, не вклеено в report:daily/weekly** (изоляция: не трогаем существующие проверенные дайджест-команды). `--source=yandex\|gsc\|both`, `--min-shows`, `--limit`, `--stdout-only`, `--json`. | ⏰ `0 8 * * 1` (пн, до aio-remediate 8:40) | 🍎 Mac |
| `app:brand:stats` | Статистика по брендам (консоль). | 👆 по запросу | 👆 |
| `app:brand:check-content` | Проверка качества контента (`--type=description\|meta\|all`, `--export` в JSON). | 👆 по запросу | 🖥 |

---

## 6. SEO / Google Search Console

| Команда | Зачем | Как часто | Где |
|---|---|---|---|
| `app:gsc:sync` | Search Analytics → `gsc_page_stats` (page) **+ `gsc_query_stats` (query-уровень, второй pull `dimensions=[query,date]`)** + URL Inspection (cap 1500/день, приоритет свежим) → `gsc_index_status`. Fail-open. | ⏰ `0 8 * * *` | 🍎 Mac |
| `app:seo:aio-queries` | Regex-свип `gsc_query_stats` под AI Overviews (`AioQueryClassifier`): группирует запросы по формату (вопрос/how-to/сравнение/freshness/**brand_entity «чей бренд»**/…) + ожидаемый trigger rate. Только чтение. `--limit`. Полный reference — [aio_remediation.md](aio_remediation.md). | 👆 по запросу | 🍎 Mac |
| `app:seo:aio-remediate` | **Closed-loop ремедиация AIO-утечки** (гибрид): brand_entity-запросы с показами/0 кликов → published-бренд → **thin** (описание<400) генерит через `generate-content` (measured); **rich** — grounded gap-FAQ (не переписывая тело). `--dry-run` дефолт, `--apply`, `--limit`, `--min-impr`, `--notify`. См. [aio_remediation.md](aio_remediation.md). | ⏰ `40 8 * * 1` (пн) | 🍎 Mac |
| `app:seo:evaluate-experiments` | Closed-loop замер ревизий контента: `measureAfter≤now` → GSC+Яндекс «после» vs «до» → вердикт win/loss/neutral → **rollback при loss** (`BrandContentVersioner`). Первичный сигнал — Яндекс. | ⏰ `0 10 * * *` | 🍎 Mac |
| `app:gsc:auth` | **Разовый** OAuth (refresh_token) вместо запрещённого SA-ключа. | 1️⃣ при настройке | 🖥 |
| `app:google:index-ping` | Google Indexing API: пинг карточек активных брендов (приоритет свежим, cooldown 14 дней) → `google_index_ping`. Единственный Google-канал (anti-trifecta: Яндекс/Bing — IndexNow). `--limit` (default 180, потолок 200), `--dry-run`. Fail-open: креды `GSC_CREDENTIALS_PATH` только на Mac. | ⏰ `0 7 * * *` | 🍎 Mac |
| `app:seo:meta-repair` | Ремонт дефектной SEO-meta (пустая / title>60 / desc>155): собирает/тримит по границе слова (`SeoMetaService::fit`), приоритет по показам GSC. `--dry-run`, `--limit`, `--min-impressions`. Чинит только дефектные поля. | 👆 по запросу / периодически | 🍎 .43 (GSC+brand-слой); ☁️ prod для прямой починки live-meta |
| `app:seo:near-dup` | Аудит near-duplicate описаний (Jaccard по word-shingles, DROP≥0.85 / WARN≥0.60). Read-only отчёт, `--threshold`, `--export`. Дубли в генерации уже ловит `NearDuplicateDetector` в generate-content. | 👆 по запросу | 🖥/🍎 |
| `app:seo:listicle` | **SEO Boost / GEO**: статья-рейтинг «ТОП-N в нише» с целевым брендом №1 + реальные конкуренты той же ниши; grounded (описание+RAG), JSON-LD Article+ItemList+FAQPage, quality-gate перед сохранением (бренд не пропущен/отказ/штампы). По умолчанию пишет в `var/seo/`. `<brand_id> [niche]`, `--city` (гео-срез «ТОП {стиль} {город}»), `--top --platform`(vc/dtf/pikabu/press/blog/**dzen**)` --persona --variants --no-faq --force --out`. Свой аналог КП ContentMagic. Полный reference — [seo_boost.md](seo_boost.md). | 👆 по запросу | 🖥/🍎 (нужна локальная LLM) |
| `app:seo:ranking` | Рейтинги брендов по поисковому спросу (`brand_keyword.monthly_shows`, Wordstat): **бренд→город** + **матрица стиль×город→топ брендов**, CSV+MD в `var/seo/`. Срез в консоль: `--style --city --top`. `--min-kw` режет омоним-хвост. Без LLM. Питает выбор ячеек для листиклов. | 👆 по запросу | 🖥/🍎 |
| `app:seo:publish-blog` | `var/seo/blog/*.md` → `Article` (canonical, MD→HTML, дрип через `publishedAt`). После публикации сам привязывает копии под площадки (см. ниже), `--no-attach-distribution` отключает. `--per-day --start --force --no-judge`. | 👆 по запросу | ☁️ prod (пишет в боевую `article`) |
| `app:seo:republish-blog` | **Переиздание** существующей статьи (приём Т—Ж, вечнозелёный URL): тот же slug, свежие контент/title/`meta_title` (год), `updatedAt=now` (→ dateModified/lastmod/Дзен-фид). `publishedAt`/статус/автор не трогаются. НЕ через `publish-blog --force`. `<slug> [--file --dry-run]`. См. [seo_boost.md](seo_boost.md). | 👆 по запросу (ежегодно на статью) | ☁️ prod (пишет в боевую `article`) |
| `app:seo:attach-distribution` | Привязка готовых копий статьи под площадку (`var/seo/**/*.md`, суффикс `-{platform}(-pN)?.md`) к статьям блога → `article_distribution` (версионируемо, `is_current`). Авто-обнаружение по всему дереву `var/seo` (не по имени папки — копии раскиданы по разным батчам), без аргумента — все найденные площадки разом. Пропускает файлы, чей текст совпадает с блогом (нет персона-дифференциации). `[platform] --dir --dry-run`. | ⏰ `40 5 * * *` (подстраховка) + авто из `publish-blog` | 🍎 Mac |

---

> `GET /rss/dzen.xml` (не консольная команда, публичный роут) — RSS «Дзен для сайтов»:
> отдаёт текущую версию `article_distribution` (platform=dzen), гейт по индексации
> в Яндексе. См. [seo_publishing_strategy.md](seo_publishing_strategy.md) §6/§6а.

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
