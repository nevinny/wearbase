# Индекс документации WEARBASE

Каталог всех документов в `docs/`. Точка входа — отсюда. Технический контекст проекта и
конвенции кода — в корневом [`../CLAUDE.md`](../CLAUDE.md).

> Чёрные/рабочие заметки и ТЗ сторонних проектов лежат в `_docs/` (вне этого индекса);
> вендорный SEO-пакет — в `_seo/` (read-only сторонний тулкит, не править).

## Маркетинг, позиционирование, SEO

| Документ | О чём |
|---|---|
| [marketing_strategy.md](marketing_strategy.md) | **Стержень бренд-стратегии**: враг (маркетплейс-«арендодатель»), движение «Прямой бренд», мессединг-пиллары, единый роадмап. От 2026-06-16 |
| [marketing_seo.md](marketing_seo.md) | SEO/GEO канал: enemy-кластер «уйти с маркетплейса», on-page рефрейм, GEO/FAQ. Ограничение индексации ~5.3% |
| [marketing_email.md](marketing_email.md) | Email/воронка: двухстадийный ФЗ-38-гейт cold/warm, активационная серия free→paid, newsletter→движение |
| [marketing_instagram.md](marketing_instagram.md) | Instagram-контент-план в авто-режиме (drip): рубрики, недельная сетка, конвейер автопубликации, ⚠️ Meta/РФ-рамка |
| [competitors.md](competitors.md) | Разбор vitrine.market, лид-ген, moat «0% комиссии» |
| [global_analogs.md](global_analogs.md) | На какую зарубежную модель похож WEARBASE (NJAL/Lyst/W&B) + региональные аналоги (Европа/Латам/Азия) + **разбор кейса Musinsa под перенос**; какие фичи перенять по слоям; ключевая дыра — click-трекинг. От 2026-06-23 |
| [competitors_seo_audit.md](competitors_seo_audit.md) | SEO-аудит 6 конкурентов + план захвата трафика по одежде (4 слоя intent, приоритет 1-6). От 2026-06-17 |
| [competitors_global.md](competitors_global.md) | Реестр конкурентов и аналогов по регионам (РФ/Европа/СевАм/Латам/Азия): URL, тип модели, релевантность. От 2026-06-23 |
| [competitors_ru.md](competitors_ru.md) | **Российский ландшафт целиком**: нишевые маркетплейсы (комиссия 30–67%), директории-аналоги (localbrands.pro), концепт-сторы, мейнстрим, рейтинги (РБК Fashion 500). Стратегические зазоры. От 2026-06-23 |
| [marketing_reality_check.md](marketing_reality_check.md) | Калибровка допущений стратегии об реальность (БД + рынок + конкуренты): willingness-to-pay, free-каталоги, honest moat. От 2026-06-17 |
| [big_player_roadmap.md](big_player_roadmap.md) | Быть и вести себя как большой игрок: стратегия (быть/вести себя) → роадмап фазами → измеримые действия с KPI. От 2026-06-19 |
| [monetization_economics.md](monetization_economics.md) | Юнит-экономика: себестоимость ≈0, маржа, сколько брендов до MRR, конверсия как связывающее ограничение; услуга 5000₽ vs подписка. От 2026-06-22 |
| [sales_offer.md](sales_offer.md) | Оффер «Размещение под ключ» 5000₽ + шаблон холодного письма + плейбук продажи + критерии списка кандидатов (`_docs/cold-sales-candidates.csv`). От 2026-06-22 |
| [proof_pack.md](proof_pack.md) | **Proof-pack пути B**: проверенный результат в цифрах (Яндекс показы ×19 425→8135/нед, страниц 339→776 за 7 нед, себестоимость ≈0; GSC 111 кликов/28д, индекс 432/1056) + позиционирование услуги «результат за срок для ICP» (по Райту) + нейминг + кому продавать (fit-тесты) + слабые места пруфа. От 2026-07-19 |
| [seo_adoption_plan.md](seo_adoption_plan.md) | Что берём из пакета `_seo` (CLOSEDLOOP-SEO-FULL + SEO 4.9): GSC, индексация, link-graph, closed-loop |
| [seo_rules.md](seo_rules.md) | **Канон SEO-правил (v3.0.0)** — единый: WEARBASE-константы + нормативные MUST/SHOULD/NICE + by-design принципы. Свёл в себя бывшие `seo_rules_2.3.0` и `seo_rules_SEO_GUIDE` |
| [seo_tools.md](seo_tools.md) | Справочник SEO-инструментов (коммерческие + open-source, бюджеты) |
| [ahrefs.md](ahrefs.md) | Ahrefs: доступ к проекту, как читать аудит (Error vs Notice), инвентарь проблем + приоритеты, другие инструменты. От 2026-06-22 |
| [agent_readiness.md](agent_readiness.md) | Техническое SEO под AI-агентов (isitagentready.com): robots.txt+Content Signals, llms.txt, Link header, Markdown negotiation, API Catalog. От 2026-06-18 |
| [ai_search_impact.md](ai_search_impact.md) | Влияние майского AI-сдвига Google (AI Mode + Core Update) на WEARBASE: моат grounded-RAG, топ-риски (boilerplate-FAQ/листиклы/индексация), где видео переоценивает риск для одежды; **реализован click-трекинг `/go/{id}`**. От 2026-06-27 |
| [seo_yandex_google_research.md](seo_yandex_google_research.md) | Исследование Яндекс+Google по данным Вебмастера/GSC: спрос ~100% навигационный (по имени бренда), CTR ~0.8% (мы на поз. 9–13), Яндекс индексирует 478 брендов против 81 в Google; быстрые победы + приоритеты. От 2026-07-02 |

## RAG-конвейер, LLM, контент

| Документ | О чём |
|---|---|
| [rag_pipeline.md](rag_pipeline.md) | **Полный reference** RAG-конвейера: discover→fetch→embed→generate, статус-машина, gate качества |
| [rag_pipeline_refactoring.md](rag_pipeline_refactoring.md) | План рефакторинга слоя данных конвейера (SOLID/KISS/DRY): дефекты, нарушения, направления |
| [ad-description-flow.md](ad-description-flow.md) | Флоу генерации рекламного описания бренда |
| [seo_boost.md](seo_boost.md) | **`app:seo:listicle`** + **`app:seo:ranking`**: GEO-листиклы «ТОП-N в нише/городе» (целевой бренд №1) + рейтинги спроса (бренд→город, матрица стиль×город). Grounded, Article+ItemList+FAQPage, quality-gate. Ниша = `BrandStyle`. От 2026-06-24 |
| [seo_publishing_platforms.md](seo_publishing_platforms.md) | Полный список площадок для публикации листиклов (20 из КП + Дзен): категории, эффект SEO/GEO, реалистичность автоматизации (API/браузер/ручками), приоритет запуска. От 2026-06-24 |
| [dzen_seo_methodology.md](dzen_seo_methodology.md) | Реверс методологии конкурента (транскрипт видео + разбор реальной Dzen-статьи + КП): анатомия статьи (TOC, ссылки+UTM, CTA, FAQ, Schema, 1300–2000 слов), gap-анализ под `app:seo:listicle`, приоритеты внедрения. От 2026-06-24 |
| [model-ab-bench.md](model-ab-bench.md) | Бенчмарк LLM-моделей генерации (вердикт: gemma4:26b) |
| [drmax_seo_2026_digest.md](drmax_seo_2026_digest.md) | Дайджест канала DrMax SEO (янв–июл 2026): GEO/AI Overviews, GSC-regex, GIST, entity-poisoning, семантический коллапс i18n, линкбилдинг + применение в WEARBASE. Дубль в RAG `topic_chunks` (role=seo). От 2026-07-18 |
| [seo_sitewide_backlog.md](seo_sitewide_backlog.md) | Приоритезированный SEO/GEO-бэклог (страница бренда + site-wide) по grounded-аудиту: тонкие гео-лендинги, GSC-query/Bing Citation Share (измерение), линковка блог↔каталог, robots crawl-бюджет, дубль Organization, свежесть. Только предложения. От 2026-07-19 |
| [aio_remediation.md](aio_remediation.md) | **Closed-loop под AI Overviews**: GSC-query свип (`gsc_query_stats`) → радар AIO-утечки (в дайджест) → `app:seo:aio-remediate` гибрид (thin→генерация/measured, rich→grounded gap-FAQ) → замер/откат `evaluate-experiments`. Классификатор `AioQueryClassifier`, крон, кросс-хост-грабли. От 2026-07-19 |

## Продукт и фичи

| Документ | О чём |
|---|---|
| [brand_lifecycle.md](brand_lifecycle.md) | **Ниша-гейт + HTTP-семантика бренда**: классификатор `app:brand:niche-check` (мода+красота vs off-niche), гейт конвейера/публикации, 410 для deleted + tombstone для закрывшихся. От 2026-06-24 |
| [international.md](international.md) | Международные рынки: 9 локалей, валюты, SEO непереведённых локалей (noindex) |
| [payments.md](payments.md) | Платежи, провайдеры, юр-слой (оферты/юрлица/возврат предоплаты) |
| [testing.md](testing.md) | Тест-харнес PHPUnit: SQLite var/test.db, автосхема из сущностей, Authenticated*WebTestCase, как писать функциональные тесты |
| [brand-claim-verification.md](brand-claim-verification.md) | Методы подтверждения владения брендом |
| [virtual-tryon.md](virtual-tryon.md) | Виртуальная примерочная (VTON) — PoC и запуск в прод |
| [antifraud_plan.md](antifraud_plan.md) | Антифрод и верификация брендов — план на будущее |
| [superpowers/specs/2026-05-24-product-page-redesign.md](superpowers/specs/2026-05-24-product-page-redesign.md) | Спека редизайна карточки товара (по WB) |
| [superpowers/plans/2026-05-24-product-page-redesign.md](superpowers/plans/2026-05-24-product-page-redesign.md) | План реализации редизайна карточки товара |
| [superpowers/specs/2026-05-31-lead-confirmed-page-design.md](superpowers/specs/2026-05-31-lead-confirmed-page-design.md) | Спека дизайна страницы подтверждённого лида |

## Инфраструктура и эксплуатация

| Документ | О чём |
|---|---|
| [production.md](production.md) | Прод-окружение (reg.ru): env-карта, деплой, известные проблемы |
| [commands.md](commands.md) | Справочник всех console-команд (зачем/как часто/где + cron) |

## Трекеры и история

| Документ | О чём |
|---|---|
| [tasktracker.md](tasktracker.md) | Текущий спринт + бэклог (датированные секции; последняя — маркетинг-роадмап 2026-06-16) |
| [bugtracker.md](bugtracker.md) | Баг-трекер (ЛК, уведомления, тарифы/биллинг) |
| [bugtracker-status.md](bugtracker-status.md) | Статус исправлений по багам |
| [changelog.md](changelog.md) | История релизов |
| [session_friction_audit.md](session_friction_audit.md) | Аудит трения в сессиях Claude Code (19.06–04.07): 10 кластеров + план скиллов/автоматизаций/правок CLAUDE.md |

## Транскрипты и разборы

| Документ | О чём |
|---|---|
| [transcripts/branding-boring-products.md](transcripts/branding-boring-products.md) | Транскрипт видео «как из скучных товаров делают культы» |
| [transcripts/branding-boring-products-theses.md](transcripts/branding-boring-products-theses.md) | Конспект: 8 принципов плейбука + кейсы → база для `marketing_strategy.md` |
| [klyucharev_decisions_2026.md](klyucharev_decisions_2026.md) | Ключарёв (нейроэкономика) «думать долго vs делать быстро» → 5 управленческих ловушек фаундера + коррекции плана (заморозить фронты, WIP=1, необратимое решение — первая продажа). Дубль в RAG `topic_chunks` (role=case). От 2026-07-19 |

## Архив (`archive/`)

Устаревшие/консолидированные документы. Сохранены для истории и отката, **не актуальны**.

| Документ | Чем заменён |
|---|---|
| [archive/seo_rules_2.3.0.md](archive/seo_rules_2.3.0.md) | → [seo_rules.md](seo_rules.md) Часть 1 (нормативные правила) |
| [archive/seo_rules_SEO_GUIDE.md](archive/seo_rules_SEO_GUIDE.md) | → [seo_rules.md](seo_rules.md) Часть 0 (WEARBASE-константы) |
