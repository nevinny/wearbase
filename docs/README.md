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
| [competitors_seo_audit.md](competitors_seo_audit.md) | SEO-аудит 6 конкурентов + план захвата трафика по одежде (4 слоя intent, приоритет 1-6). От 2026-06-17 |
| [marketing_reality_check.md](marketing_reality_check.md) | Калибровка допущений стратегии об реальность (БД + рынок + конкуренты): willingness-to-pay, free-каталоги, honest moat. От 2026-06-17 |
| [big_player_roadmap.md](big_player_roadmap.md) | Быть и вести себя как большой игрок: стратегия (быть/вести себя) → роадмап фазами → измеримые действия с KPI. От 2026-06-19 |
| [monetization_economics.md](monetization_economics.md) | Юнит-экономика: себестоимость ≈0, маржа, сколько брендов до MRR, конверсия как связывающее ограничение; услуга 5000₽ vs подписка. От 2026-06-22 |
| [sales_offer.md](sales_offer.md) | Оффер «Размещение под ключ» 5000₽ + шаблон холодного письма + плейбук продажи + критерии списка кандидатов (`_docs/cold-sales-candidates.csv`). От 2026-06-22 |
| [seo_adoption_plan.md](seo_adoption_plan.md) | Что берём из пакета `_seo` (CLOSEDLOOP-SEO-FULL + SEO 4.9): GSC, индексация, link-graph, closed-loop |
| [seo_rules.md](seo_rules.md) | **Канон SEO-правил (v3.0.0)** — единый: WEARBASE-константы + нормативные MUST/SHOULD/NICE + by-design принципы. Свёл в себя бывшие `seo_rules_2.3.0` и `seo_rules_SEO_GUIDE` |
| [seo_tools.md](seo_tools.md) | Справочник SEO-инструментов (коммерческие + open-source, бюджеты) |
| [agent_readiness.md](agent_readiness.md) | Техническое SEO под AI-агентов (isitagentready.com): robots.txt+Content Signals, llms.txt, Link header, Markdown negotiation, API Catalog. От 2026-06-18 |

## RAG-конвейер, LLM, контент

| Документ | О чём |
|---|---|
| [rag_pipeline.md](rag_pipeline.md) | **Полный reference** RAG-конвейера: discover→fetch→embed→generate, статус-машина, gate качества |
| [rag_pipeline_refactoring.md](rag_pipeline_refactoring.md) | План рефакторинга слоя данных конвейера (SOLID/KISS/DRY): дефекты, нарушения, направления |
| [ad-description-flow.md](ad-description-flow.md) | Флоу генерации рекламного описания бренда |
| [model-ab-bench.md](model-ab-bench.md) | Бенчмарк LLM-моделей генерации (вердикт: gemma4:26b) |

## Продукт и фичи

| Документ | О чём |
|---|---|
| [international.md](international.md) | Международные рынки: 9 локалей, валюты, SEO непереведённых локалей (noindex) |
| [payments.md](payments.md) | Платежи, провайдеры, юр-слой (оферты/юрлица/возврат предоплаты) |
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

## Транскрипты и разборы

| Документ | О чём |
|---|---|
| [transcripts/branding-boring-products.md](transcripts/branding-boring-products.md) | Транскрипт видео «как из скучных товаров делают культы» |
| [transcripts/branding-boring-products-theses.md](transcripts/branding-boring-products-theses.md) | Конспект: 8 принципов плейбука + кейсы → база для `marketing_strategy.md` |

## Архив (`archive/`)

Устаревшие/консолидированные документы. Сохранены для истории и отката, **не актуальны**.

| Документ | Чем заменён |
|---|---|
| [archive/seo_rules_2.3.0.md](archive/seo_rules_2.3.0.md) | → [seo_rules.md](seo_rules.md) Часть 1 (нормативные правила) |
| [archive/seo_rules_SEO_GUIDE.md](archive/seo_rules_SEO_GUIDE.md) | → [seo_rules.md](seo_rules.md) Часть 0 (WEARBASE-константы) |
