# Метрики активации гардероба

## Аудит

До этого среза в проекте были технические метрики AI usage, social post metrics и доменные
события семьи/покупок. Они не образуют продуктовую воронку гардероба и не переиспользуются:
activation-события имеют другой lifecycle, retention и privacy-контракт.

## Минимальная воронка

На один `profileSubject` один раз записываются:

1. `onboarding_started` — начата пакетная загрузка;
2. `first_item_added` — успешно сохранена первая или последующая вещь, если milestone ещё не был записан;
3. `first_outfit_created` — впервые сохранён предложенный образ;
4. `repeat_wear_recorded` — одна вещь встретилась минимум в двух подтверждённых событиях `worn`.

Уникальный индекс `(profile_subject_id, event_type)` делает запись идемпотентной при retry и гонках.
События начинают собираться после выкладки миграции; исторического backfill нет, поэтому время
milestone не подменяется временем деплоя.

Для эксплуатационного отчёта дополнительно записываются повторяемые события:

- `batch_recognition_started|completed` — один раз на пачку;
- `draft_accepted` — один раз на черновик, с `source=ai|manual_correction`, булевыми
  `correction`/`autofillAccepted` и грубым `durationBucket`.

UUID пачки и ID черновика не попадают в metadata: только их SHA-256 используется как технический
`dedup_key`. Команда `php bin/console app:wardrobe:activation-report --days=30` отдаёт first-party
JSON с дневными когортами, conversion/time-to-first-item/outfit/repeat, batch completion и долями
исправлений/принятого autofill. Нулевой знаменатель всегда даёт rate `0`, исторического backfill нет.

## Privacy-контракт

JSON metadata имеет закрытый контракт:

- `actorKind`: только `self|family_manager`;
- `entryPoint`: только `batch|manual|purchase|stylist|wear_review|outfit`.
- `source`: только `ai|manual_correction`;
- `durationBucket`: только `under_1m|1_5m|5_15m|over_15m`;
- `correction`, `autofillAccepted`: boolean.

Запрещены email, имя, возраст, ссылки магазинов, идентификаторы/названия/параметры вещей, фото,
prompts и свободный текст. `profile_subject_id` — внутренний FK для построения воронки, удаляется
каскадно вместе с профилем и не экспортируется клиенту.

## Эксплуатация

Миграция должна быть применена до выкладки кода. Если telemetry временно недоступна, доменная
операция не откатывается: ошибка логируется без пользовательских данных. Для отчётов считать
конверсию по последовательным milestone и отделять `actorKind=family_manager` от самостоятельной активации.
