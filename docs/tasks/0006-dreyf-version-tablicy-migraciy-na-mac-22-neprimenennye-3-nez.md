---
id: 0006
title: Дрейф version-таблицы миграций на Mac: 22 непримененные + 3 незарегистрированные
status: новая
owner: не назначен
source: сессия 2026-09-07 (ключевики + гео-хабы)
created: 2026-09-07
updated: 2026-09-07
---

## Суть

`doctrine:migrations:up-to-date` на Mac (07.09.2026) отдаёт «Out-of-date! 22 migrations are available to execute» и «You have 3 previously executed migrations in the database that are not registered migrations». При этом схема фактически свежая: колонки `brand_keyword.blocked_at`/`blocked_reason`/`niche_status` на месте, миграция `Version20260907_city_hub_faq` применилась штатно. Похоже на расхождение `doctrine_migration_versions` с каталогом `migrations/`, а не на реально отставшую схему.

## Почему это важно

Пока таблица версий не сверена, нельзя доверять `migrate` вслепую — можно как пропустить реально недостающую миграцию, так и словить конфликт/повтор на уже применённой. Риск копится с каждой новой миграцией поверх дрейфа.

## Где смотреть

- таблица `doctrine_migration_versions` (Mac MySQL)
- каталог `migrations/`
- `Version20260907_city_hub_faq` — применилась штатно, ориентир на дату дрейфа

## Что сделать

- [ ] сверить `doctrine_migration_versions` с `ls migrations/`
- [ ] выяснить, какие 3 записи в таблице версий лишние (не соответствуют файлу)
- [ ] выяснить, какие из 22 «непримененных» файлов на самом деле уже отражены в схеме
- [ ] закрыть расхождение через `doctrine:migrations:version --add/--delete` там, где схема уже содержит изменения
- [ ] прогнать `doctrine:migrations:up-to-date` на проде отдельно — своя БД, своё состояние

## Заметки

Риск в том, что на фоне 22+3 несовпадений не видно реально пропущенной миграции, если она есть. Вслепую `migrate` не гонять.
