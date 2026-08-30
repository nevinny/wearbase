# Персональная память гардероба

## Граница первого production-среза

MySQL — единственный source of truth. `WardrobeMemoryFact` создаётся только при действующем
personalization consent и только из двух подтверждённых источников:

- `WardrobeWearEvent(status=confirmed,type=worn)`;
- завершённый `FittingFeedback` (`outcome != pending`).

Qdrant, embeddings, shared learning и fine-tuning в этот срез не входят.

Каждый факт строго принадлежит одному `profileSubject`. Отдельно сохраняются `actor` и
`signalSource=self|parent_observed`; действие родителя не становится самостоятельным сигналом
ребёнка. Уникальный ключ `(profile_subject, source_type, source_id)` делает повторный sync
идемпотентным. Ручная правка имеет приоритет и не затирается повторным collector run.

## Privacy и consent

В автоматически сформированный факт не копируются фото, URL магазина, комментарии, occasion,
email или имя. Для примерки используются только outcome/size/sizing, для носки — bounded category,
color и структурированный comfort/repeat. Контекст ограничен 20 активными фактами.

Без personalization consent новые facts не создаются и существующие не попадают в AI context.
Grant запускает идемпотентный backfill подтверждённых MySQL-событий. Revoke немедленно закрывает
использование, но сохраняет факты для просмотра, экспорта и решения пользователя.

Пользователь может изменить, экспортировать или удалить один/все facts на
`/account/wardrobe/memory`. Удаление сохраняет только audit tombstone: текст заменяется на
`[deleted]`, запись получает `deleted_at`/`deleted_by_user` и никогда не воскресает при backfill.
Доступ проверяется через `FamilyService`; после перехода детского профиля во взрослый бывший
родитель не получает доступ автоматически.

## Deployment

1. Применить `Version20260830_wardrobe_memory_facts`.
2. Включать personalization только после отображения текущего consent-текста.
3. Проверить edit/export/delete для self и parent→child; cross-family должен возвращать 403.
