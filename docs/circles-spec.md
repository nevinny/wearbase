# Спецификация: «Кружки подруг» (circles)

**Статус:** утверждён PO 2026-08-25 · Источник: делегат CirclesSpec · Грундинг верифицирован по репо (§0; код шеринга — ветка `feat/share-outfit-landing`, коммиты `1fa6920…ec4603c`, до мержа).

## 0. Что уже построено (грундинг)

| Факт | Где |
|---|---|
| Семейная авторизация живёт **в сервисе, не в Voter'ах**; `canManage(actor, target)` = сам себя всегда, чужой — только parent над `FAMILY_ROLE_CHILD` той же семьи; `resolveMember()` резолвит `?member=<id>` | `src/Service/FamilyService.php:19–27, 64–97` |
| Роли пользователя: `FAMILY_ROLE_PARENT / CHILD / ADULT`; managed-дети = живой claim-токен или синтетический домен (`isManaged()`); `becomeFamilyAdult()` для подросших | `src/Entity/User.php:26–31, 401–425, 545–550` |
| Паттерн opaque-токенов: 64 hex, UNIQUE, поиск по индексу; инвайты 7 дней, одноразовые, revoke/renew с пессимистичной блокировкой | `src/Entity/FamilyInvite.php:26, 44–46`; `FamilyService::acceptInvite():157–193` |
| Share лука: канал-на-ссылку; статусы `active/pending_parent/revoked`; TTL стартует с аппрува; отзыв → нейтральный 410 + `no-store` | `WardrobeOutfitShare`, `WardrobeOutfitShareController` (feat/share-outfit-landing) |
| Гостевой `/l/{token}` вне firewall'а; медиа только через shareToken; бренды скрыты, имена никогда не рендерятся гостям | `LookShareController` |
| Referral-граф: **не денормализовать пары inviter→invitee**, кружки читают рёбра напрямую | `docs/referral-program-spec.md:71–77` |
| Consent-модель: одна строка на subject, timestamp-гранты, `policyVersion` | `src/Entity/WardrobeConsent.php` |

Наследуемые политики: никаких UTM, минимизация данных, детские фото — двойной opt-in родителя, noindex персональных страниц.

## 1. Модель кружка

### 1.1 Сущности

```
WardrobeCircle          — сам кружок
WardrobeCircleMember    — членство (user ↔ circle, роль, статус)
WardrobeCircleInvite    — инвайт по токену (паттерн FamilyInvite 1:1)
```

Отдельная таблица членства: пользователь состоит **в нескольких кружках** («школа» и «двор» — разные круги доверия). Семья остаётся единственной и живёт на User.

### 1.2 Кап членства

**12 участников на кружок** (жёсткий кап на вставке, константа `WardrobeCircle::MEMBER_CAP`). Дополнительно **≤5 активных кружков на пользователя** (антиспам от фермы приглашений).

### 1.3 Роли

| Роль | Права |
|---|---|
| `owner` | инвайты, отзыв инвайтов, кик, назначение модераторов, расформирование |
| `moderator` | кик, отзыв инвайтов (в MVP никому не выдаётся) |
| `member` | шеринг своих луков в кружок, выход, просмотр ленты |

Роль — строка на членстве. Владелец не может выйти, пока не передал владение.

### 1.4 Инвайт

```php
$this->token = bin2hex(random_bytes(32));            // 64 hex, UNIQUE
$this->expiresAt = new \DateTimeImmutable('+7 days');
```

- URL: `/account/circles/join/{token}` — **под firewall'ом**: акцепт только залогиненным (принципиальное отличие от публичного `/l/{token}`).
- Акцепт: транзакция c `LockMode::PESSIMISTIC_WRITE`; проверки: invite usable, <5 активных кружков у акцептера, <12 членов, не managed-ребёнок.
- Многоразовый до экспирации в пределах свободного капа; повторное «Пригласить» = новый токен (канал-на-ссылку).
- Истечение/отзыв → нейтральный 410 + `no-store`.

### 1.5 Потоки

Join: ссылка → «Маша приглашает вас в кружок "…"» → POST+CSRF → редирект в ленту. Leave: мгновенная потеря доступа. Kick: идентичен leave. Expire: 410.

## 2. Лента кружка

**Только луки, явно расшаренные в этот кружок** — автоподмешивание всех share автора превратило бы «показать одной подруге» в «показать всему кружку» задним числом.

Кружок = ещё один канал: на `wardrobe_outfit_share` добавляется nullable-грант:

```sql
ALTER TABLE wardrobe_outfit_share
    ADD COLUMN circle_id INT NULL,
    ADD CONSTRAINT fk_share_circle FOREIGN KEY (circle_id)
        REFERENCES wardrobe_circle (id) ON DELETE CASCADE,
    ADD INDEX idx_share_circle (circle_id);
```

Инвариант: строка либо гостевая (`token` выдан, `circle_id IS NULL`), либо кружковая (`circle_id` заполнен, token генерируется, но никогда не выдаётся). Вся механика переиспользуется as is: статусы, TTL, approve/revoke, parent-confirm, массовый revoke родительского флага. Новый грант-тип НЕ вводится.

Лента: `WHERE circle_id = :c AND status='active' AND (expires_at IS NULL OR expires_at > now) ORDER BY granted_at DESC`.

Карточка в ленте: title, explanation, фото обложек, категория/цвет, **firstName автора** (без имени социальность не работает), дата. Никогда: email/синтетический домен, бренды, размеры/цены/история носок, счётчики гардероба. За firewall'ом → noindex автоматически.

## 3. Приватность

### 3.1 Несовершеннолетние

| Кто | Состоит? | Условие |
|---|---|---|
| Managed-ребёнок | **Нет** | жёсткий запрет на join; луками ребёнка в кружке распоряжается родитель |
| Подросток с собственным входом | Да | членство со статусом `pending_parent`; без аппрува лента недоступна |
| Взрослый | Да | без условий |

Наследование согласия не автоматическое: каждый кружковый share детского лука проходит ту же двойную opt-in цепочку; отзыв родительского флага = массовый revoke кружковых share тоже.

### 3.2 Участники vs гости

Гость — анонимный одноразовый зритель одного артефакта без имён; участник — идентифицированный постоянный зритель потока (+firstName). Обе поверхности из одного DTO, различие только в `authorFirstName`.

### 3.3 Выход = отзыв доступа

Немедленный: предикат ленты проверяет живое членство на каждый запрос. Оговорки UX: скриншоты не предотвращаются; OG-карточек у кружковых share нет (страницы за firewall'ом) — проблема «превью в переписке» отсутствует; ранее расшаренные луки вышедшего остаются (согласие давалось кружку, а не составу), точечный revoke у автора.

## 4. Шов рейтингов

Точка расширения — пара `(share_id, member_id)`. Инварианты:
1. Реакция участника НЕ пишется в `WardrobeOutfit.reaction` (там self-feedback владельца, питает обучение стилиста).
2. Идемпотентность будущей таблицы оценок — unique `(share_id, member_id)` по образцу `uniq_referral_once`.
3. Агрегаты считаются запросом, не денормализуются на share.

## 5. Эскизы

```php
// src/Entity/WardrobeCircle.php
class WardrobeCircle {
    public const MEMBER_CAP = 12;
    #[ORM\Column(length: 80)] private string $title;
    #[ORM\ManyToOne] private User $owner;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $dissolvedAt = null;
}

// src/Entity/WardrobeCircleMember.php
#[ORM\UniqueConstraint(name: 'uniq_circle_member', columns: ['circle_id', 'user_id'])]
class WardrobeCircleMember {
    public const ROLE_OWNER = 'owner';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_MEMBER = 'member';
    // status: active | pending_parent | left | kicked
}
```

Миграция: три CREATE TABLE + ALTER TABLE `wardrobe_outfit_share ADD circle_id`. Без денормализаций.

`src/Service/Circle/CircleService.php` — вся авторизация кружков в сервисе (не Voter'ы): create/dissolve, createInvite/revokeInvite/renewInvite, acceptInvite (транзакция, локи, капы, запрет isManaged), leave/kick, canViewFeed/shareToCircle/revokeCircleShare, assertParentApprovesMembership через `FamilyService::canManage`.

| Компонент | Путь |
|---|---|
| CRUD + лента | `src/Controller/Account/CircleController.php`, `/account/circles` (за существующим правилом firewall'а) |
| Инвайт-акцепт | `/account/circles/join/{token}`, requirements `[0-9a-f]{64}` + rate limit токен+IP |
| Share в кружок | расширение `Account/WardrobeOutfitShareController.php`, CSRF per-action |
| Шаблоны | `templates/account/wardrobe/circles.html.twig` + блок в `outfits.html.twig` |
| Тесты | `tests/Controller/CircleControllerTest.php`: капы, 410, parent-confirm, отзыв при leave |

## Открытые вопросы → Решения PO (2026-08-25)

Все приняты по рекомендациям аналитика — продолжают принятые решения по шерингу:

1. Кап кружка **12** (константа), ≤5 кружков на пользователя.
2. Чужие луки **остаются** в ленте после выхода участника; точечный revoke у автора.
3. Инвайт **многоразовый** до экспирации в пределах свободного капа.
4. Роль moderator вводится, **в MVP не выдаётся**.
5. Выход owner = **обязательный выбор преемника** перед выходом.
6. Подростки участвуют **с pending_parent** — зеркало решения №3 по шерингу (parent-confirm).
7. Уведомления в MVP **тихие** (списком в ЛК); пуши позже через ExternalNotificationOutbox.
