# Спецификация: «Внутренние рейтинги кружка» (circle-ratings)

**Статус:** принят PO 2026-08-25 по рекомендациям аналитика (ветируемо) · Источник: делегат RatingsSpec · Грундинг: `docs/circles-spec.md` §4 (шов рейтингов), ветка `feat/share-outfit-landing`, `src/Entity/WardrobeOutfit.php`, `docs/referral-program-spec.md` §5.

## 0. Грундинг

| Факт | Где |
|---|---|
| Шов = пара `(share_id, member_id)`; инварианты: не трогать self-feedback владельца, unique-ключ, агрегаты ON READ | `docs/circles-spec.md` §4 |
| `WardrobeOutfit.reaction` — self-feedback владельца (`like/dislike/worn`), питает обучение стилиста; чужие реакции туда не попадают никогда | `src/Entity/WardrobeOutfit.php:16–19, 47–48, 78–85` |
| Кружковый share = строка `wardrobe_outfit_share` с `circle_id NOT NULL`; статусы/TTL/`isViewable()` переиспользуются | `WardrobeOutfitShare` (feat/share-outfit-landing) |
| Членство: `uniq_circle_member`, статусы `active/pending_parent/left/kicked`, строки переживают выход | `docs/circles-spec.md` §1 |
| Идемпотентность через unique + catch `UniqueConstraintViolationException` как успех | `WardrobeController.php:280, 705`; образец `uniq_referral_once` |
| Rate limit паттерн: named-лимитеры в `rate_limiter.yaml` (`brand_vote` 20/час анонимам; `wardrobe_ai` 30/день), `no_limit` в тестах | `config/packages/rate_limiter.yaml` |

## 1. Что ранжируем

**Только кружковые шеры** (`circle_id IS NOT NULL AND status='active'`). Гостевые ссылки и прочие луки не ранжируются — рейтинг живёт только внутри социального контура кружка.

**Positive-only**: одна реакция «огонь». Дизлайка нет: лук в кружке — просьба об одобрении, отрицательная оценка разрушает мотив шеринга. `REACTION_DISLIKE` остаётся исключительно self-feedback владельца (инвариант №1). Колонка `kind VARCHAR(16) DEFAULT 'fire'` закладывается сразу — расширение набора позитива («сердечко», «хочу так») без миграции.

## 2. Видимость агрегатов

| Кто | Что видит |
|---|---|
| Автор | Сумма огней по каждому своему кружковому share в ЛК; имена реагировавших — нет |
| Участник | Сумма на карточке в ленте; распределение по именам — нет |
| За пределами кружка | Ничего (firewall → noindex, OG-карточек нет, гостевой канал счётчик не видит) |

Сокрытие имён: минимизация данных, снятие социального давления «почему не поставил», никаких микроранкингов внутри круга доверия.

## 3. Механика без демотивации

1. **Лента не пересортировывается** (`ORDER BY granted_at DESC`) — рейтинга-сортировки в MVP нет.
2. **Нет последнего места**: агрегаты per-share, не per-author; топ-авторов не выводим.
3. **Персональные бейджи вместо лидерборда**: пороги **5 / 25 / 100** суммарных огней, видит только автор.
4. Повторный клик = idempotent no-op; «забрать огонь назад» не строим.

## 4. Антиабьюз (`CircleReactionService::react()`, не Voter'ы)

1. Нельзя себе: `share.outfit.user === actor` или `share.createdBy === actor` → нейтральный отказ.
2. Только active-членам этого кружка: предикат читает живое членство на каждый запрос; вышедший теряет кнопку, прошлые реакции остаются честным фактом.
3. Rate limit `circle_reaction`: sliding **60/день per-user**, `no_limit` в тестах.
4. Idempotent: повторный POST → 200 + текущее состояние; гонка гасится unique-catch.
5. POST+CSRF per-action, маршрут за firewall'ом `^/account`.

## 5. Сущность и компоненты

```sql
CREATE TABLE wardrobe_share_reaction (
    id INT AUTO_INCREMENT PRIMARY KEY,
    share_id INT NOT NULL,
    member_id INT NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'fire',
    created_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_share_member_reaction (share_id, member_id),
    INDEX idx_reaction_feed (share_id),
    CONSTRAINT fk_reaction_share FOREIGN KEY (share_id) REFERENCES wardrobe_outfit_share (id) ON DELETE CASCADE,
    CONSTRAINT fk_reaction_member FOREIGN KEY (member_id) REFERENCES wardrobe_circle_member (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`member_id` → членство (не пользователь напрямую): rejoin не даёт второй голос за тот же share. Денормализованных счётчиков нет; агрегат ленты — один GROUP BY запрос.

| Компонент | Путь |
|---|---|
| Сервис | `src/Service/Circle/CircleReactionService.php` — проверки §4 + insert-or-ignore |
| Роуты | `POST /account/circles/{circleId}/shares/{shareId}/react` + CSRF |
| UI ленты | кнопка «🔥 N» на карточке, оптимистичный счётчик |
| UI автора | блок «Огни на твоих луках»: per-share суммы + бейджи в ЛК |
| Limiter | `circle_reaction` sliding 60/day; `when@test: no_limit` |
| Тесты | запрет себе, не-active член, idempotent, гонка unique, 404 чужого кружка, каскад при revoke |

## 6. Несовершеннолетние

Отдельная политика не нужна — наследуется parent-confirm: share детского лука уже прошёл двойной opt-in до появления в ленте; реакция — метаданные уже одобренной публикации; аудитория та же; отзыв родительского флага скрывает карточки со счётчиками каскадно; managed-ребёнок не актор (действует родитель, который себе реагировать не может); подросток реагирует после аппрува `pending_parent → active`. Имена не показываем — для детских луков особенно правильно.

## Открытые вопросы → Решения PO (по рекомендациям, ветируемо)

1. MVP — только «огонь»; колонка `kind` оставляет задел под набор позитива.
2. Имена реагировавших — нет в MVP; пересмотр после наблюдений retention.
3. Пороги бейджей 5 / 25 / 100, видимость только автору; калибровка после месяца данных.
4. Уведомление о первой реакции — тихое, списком в ЛК; пуш позже через ExternalNotificationOutbox.
5. У истёкшего/отозванного share кнопки исчезают, сумма остаётся видимой автору.
6. Реакции после kick/leave остаются как факт; точечная чистка при жалобе.
7. Топ-блок «лучшие луки недели» — не строить в MVP (антидемотивация).
