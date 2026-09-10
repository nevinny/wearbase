# Карта: приглашение человека в семью (разведка, ничего не менялось)

## 1. Полный путь инвайта взрослого/родителя

СОЗДАНИЕ (родитель, залогинен)
- ЛК: templates/account/family/index.html.twig:127-138 — форма (email опционален + select роли)
  + :9-13 / :25-29 — кнопка «+ Пригласить ребёнка» (bare invite, role=child, без email)
- POST /account/family/invite → src/Controller/Account/FamilyController.php:123-158
  CSRF 'family_invite'; ребёнку запрещено (:129-132); роль валидируется (:141-145);
  email валидируется только форматом (:147-151)
- FamilyService::createInvite src/Service/FamilyService.php:133-150 → ensureFamilyAsParent (:309-329)
  лениво создаёт Family, автор = owner + family_role=parent
- Flash :155 «Приглашение создано — отправьте ссылку члену семьи» → redirect account_family_index

ПОКАЗ ССЫЛКИ
- FamilyController::index:55-59 берёт FamilyInviteRepository::findPendingForFamily
- index.html.twig:150-157 — readonly input + кнопка «Скопировать» (JS wbCopy :186-203)
- Доставка адресату — ПОЛНОСТЬЮ вручную (мессенджер/устно). Письма нет.

ПОЛУЧАТЕЛЬ
- GET /family/invite/{token} → src/Controller/FamilyClaimController.php:95-157
  config/packages/security.yaml:88 — ^/family = PUBLIC_ACCESS
- Аноним видит templates/family/invite_accept.html.twig:22-26 (имя пригласившего + роль)
  и кнопки Войти/Зарегистрироваться :39-46
- FamilyClaimController.php:144-146 кладёт _security.main.target_path = URI инвайта
- src/Security/LoginSuccessHandler.php:35-51 чтит target_path
- src/Controller/Auth/RegisterController.php:38-39,136-140 логинит через
  security.authenticator.form_login.main → тот же LoginSuccessHandler
  ⇒ ВОЗВРАТ НА СТРАНИЦУ ИНВАЙТА ПОСЛЕ ЛОГИНА/РЕГИСТРАЦИИ АВТОМАТИЧЕСКИЙ.
  Текст в шаблоне :40 «затем откройте эту ссылку снова» — устарел/врёт.
- Залогинен → форма с кнопкой «Принять приглашение» (:32-38), CSRF 'family_invite_accept'
- POST → FamilyService::acceptInvite:157-193 (pessimistic lock, одноразовость,
  «уже в семье», сверка intendedEmail)
- Успех: child → account_family_profile (:135), parent → account_family_index (:141)

ТОЧКИ ОТВАЛА
1. Нет письма — ссылку надо скопировать и донести вручную (0 автоматики).
2. Возврат после логина держится на СЕССИИ. Открыл на телефоне, зарегистрировался
   на ноуте → target_path потерян, ссылку надо открывать заново. Токен в /register не пробрасывается.
3. После авто-возврата нужен ЕЩЁ один клик «Принять приглашение».
4. Любая DomainException (чужой email / уже в семье) → flash + redirect на
   account_family_index (:137-141), а НЕ обратно на инвайт. Человек без семьи
   попадает на пустой экран «Добавьте членов семьи» с красной плашкой.
5. Протухшая ссылка (см. §3) — 410 и тупик.

## 2. intendedEmail
- Задаётся: FamilyController.php:147-152 (поле email в форме, необязательное),
  нормализуется lower+trim в FamilyInvite.php:89-94.
- Проверяется СТРОГО при акцепте: FamilyService.php:175-179, точное сравнение
  mb_strtolower(email юзера) == intendedEmail, иначе DomainException.
- Получателю НИКОГДА не показывается: invite_accept.html.twig рисует только
  inviterName + роль. Предупреждения «эта ссылка для vasya@…» нет.
- Регистрацию не преднастраивает и не ограничивает.
⇒ Ловушка ЕСТЬ конструктивно (узнаёшь о несовпадении только после клика, и то
  на чужой странице), но на проде не выстрелила: intendedEmail не заполнен ни разу (0/6).

## 3. TTL и протухание
- 7 дней, захардкожено в конструкторе: src/Entity/FamilyInvite.php:62 (`+7 days`).
  Настройки/env нет. Столько же у claim-ссылки ребёнка (User.php:445) и у кружков
  (WardrobeCircleInvite.php:23 TTL = '+7 days').
- isExpired FamilyInvite.php:87; isUsable :106-109.
- Протухшая ссылка: FamilyClaimController.php:152-155 → HTTP 410 +
  invite_accept.html.twig:13-18 «Приглашение недоступно… Попросите отправить вам новое»
  + кнопка «Перейти в кабинет» на /account/family → для анонима это ROLE_USER →
  редирект на логин. Тупик.
- ПЕРЕВЫПУСК СУЩЕСТВУЕТ, НО НЕДОСТИЖИМ ДЛЯ ПРОТУХШИХ:
  FamilyService::renewInvite:204-219 (revoke старый + новый на 7 дней),
  роут FamilyController.php:183-204, кнопка index.html.twig:159-162.
  НО список строится FamilyInviteRepository::findPendingForFamily:27-39, где есть
  `andWhere('i.expiresAt > :now')` → протухший инвайт ИСЧЕЗАЕТ из ЛК вместе с
  кнопкой «Новая ссылка». Родитель видит «Активных приглашений нет».
  Единственный путь — создать новый инвайт с нуля.
  Прод подтверждает: 5 протухших, 0 revoked (renew оставил бы revoked-строки).
- Никаких напоминаний о протухании: grep по FamilyInvite не находит ни одной
  крон-команды/ремайндера (только контроллеры + сервис).

## 4. «Ребёнок дорос» — ДВЕ РАЗНЫЕ МЕХАНИКИ

(а) family_claim_token — managed-ребёнок получает свой вход. РАБОТАЕТ E2E.
- Создание: FamilyService::createChild:104-127 (синт. email
  child-{familyId}-{hex}@family.wearbase.local, User::MANAGED_EMAIL_DOMAIN
  User.php:31) → issueFamilyClaim User.php:439-449 (токен 64 hex, +7 дней).
  Вызывается из ChildProfileService::create:19-31 ← /account/family/add.
- Инициирует РОДИТЕЛЬ: ссылка в ЛК index.html.twig:69-89 (копипаста + «Действует до»),
  «Новая ссылка» :80-83 → FamilyController.php:206-221, «Отозвать» :84-87 → :223-239.
  Если ссылки нет — :90-94 «Создать ссылку для входа».
- Ребёнку НИЧЕГО не приходит: у managed-профиля адрес синтетический и недоставляемый
  по построению. Только копипаста.
- Приём: GET/POST /family/claim/{token} → FamilyClaimController.php:30-92,
  форма email+password (запрет на @family.wearbase.local :55-60, пароль ≥8 :62-69)
  → FamilyService::activateChildAccess:252-282 (lock, isFamilyClaimUsable,
  уникальность email, setClaimedAt). Автологина НЕТ — редирект на app_login
  с флешем «войдите с новым email и паролем» (:81-82).
- Протухло/отозвано → 410 + family/claim.html.twig:16-18.
- ПРОД: клиент 59 (krekina2012@icloud.com) claimed_at = 2026-08-10 19:32:40 → работает.

(б) confirmAdulthood — смена роли child → adult (18+). НЕ ПРОВЕРЕНО НА ПРОДЕ.
- src/Service/FamilyLifecycleService.php:19-43, кнопка index.html.twig:97-103,
  роут FamilyController.php:241-258. Гейт User::canBecomeFamilyAdult:407-413
  (роль child + НЕ managed + birthDate + 18 лет).
- Прод: оба ребёнка 2012/2013 г.р. → кнопка не появляется, adulthood_at пуст.

## 5. Прод (wearbase.ru, 2026-09-10)

family_invite: всего 6 | принят 1 | отозван 0 | протух 5 | активных 0 | intended_email 0
первый 2026-07-13, последний 2026-08-24
| id | family | роль   | создан           | принят           | кем |
|  1 | 1      | parent | 07-13 13:59      | —                | —   |
|  2 | 1      | child  | 07-13 13:59      | —                | —   |
|  3 | 2      | parent | 07-28 21:23      | —                | —   |
|  4 | 1      | parent | 08-08 18:31      | —                | —   |
|  5 | 1      | parent | 08-08 18:32      | —                | —   |
|  6 | 1      | child  | 08-24 13:56      | 08-24 19:33      | 61  |

family: 2 строки. family 1 (owner 52, создана 07-13) — 0 участников.
family 2 (owner 56, создана 07-28) — 4 участника: 52 nevinny@yandex.ru parent,
56 alay@mail.ru parent, 59 krekina2012@icloud.com child (claimed 08-10),
61 krekina2013@icloud.com child.
Все 4 — известные семейные тест-аккаунты владельца (память prod-family-test-accounts).

family_membership_event: 0 строк, при этом таблица создана миграцией
Version20260824_family_membership_lifecycle, выполненной 2026-08-24 00:19:56 —
то есть ДО акцепта инвайта 6 (19:33). Т.е. и 52, и 61 покинули family 1 без
единого lifecycle-события. Скриптов слияния семей в репо нет (grep пуст).
Факт: у family 1 owner_id = 52, а сам 52 сидит в family 2 → owner-FK висит.

wardrobe_circle_invite: 0. wardrobe_circle: 0. wardrobe_circle_member: 0.

notification (in-app) по типам: purchase_decision_reminder 22, system 13,
purchase_request_new 3, payment_reminder 1. Ни одного семейно-членского.

Хронология «родитель сдался»: 08-08 18:31 и 18:32 — два parent-инвайта подряд с
разницей 30 сек (id 4,5), оба протухли; через 10 минут (18:42) вместо инвайта
заведён managed-ребёнок 59.

## 6. Уведомления
- FamilyService, FamilyController, FamilyClaimController, FamilyLifecycleService
  НЕ инжектят ни NotificationDispatcher, ни EmailNotifier. Ноль вызовов.
- Notification TYPE_* (src/Entity/Notification.php:16-32) не содержит НИ ОДНОГО
  семейно-членского типа (есть brand_invite, purchase_*, order_*, payment_reminder).
- templates/emails/family_notification.html.twig — обманка: это generic-шелл писем
  для покупательских запросов, используется только PurchaseRequestService.php:386-397.
- Единственные семейные уведомления вообще: FamilyPurchaseRemindersCommand.php:89,111 —
  напоминания по purchase requests, in-app only (dispatchInAppOnce).
- КОНТРАСТ: бренд-инвайты сделаны «как надо» — BrandTeamController.php:36,77 шлёт
  письмо (templates/emails/brand_invite.html.twig), а InviteAcceptController.php:79
  уведомляет пригласившего о принятии (Notification::TYPE_BRAND_INVITE).
  Т.е. паттерн «инвайт с письмом + уведомление» в кодовой базе ЕСТЬ, просто не в семье.

## 7. Кружки (параллельная механика, для контраста)
- WardrobeCircleInvite: токен 64, TTL '+7 days' (Entity:23), поля только
  created/expires/revoked — accepted_at НЕТ ⇒ ссылка МНОГОРАЗОВАЯ до отзыва/протухания
  (isUsable :74-82). У FamilyInvite — одноразовая.
- Приём: /account/circles/join/{token} (CircleController.php:349) — под ^/account,
  т.е. ROLE_USER. Аноним не видит даже превью (в отличие от семейного инвайта).
- Письма тоже нет.

## 8. Тесты
- tests/Controller/FamilyControllerTest.php покрывает: создание инвайта с
  intendedEmail (:97-132), акцепт (:167-199), повторный акцепт (:201-207),
  revoke (:230-249), renew (:251-270), протухание (:276-289), чужая семья (:291-302).
- E2E tests/e2e/14-family-wardrobe-lifecycle.spec.ts — invite/claim НЕ покрыты (grep пуст).
- CSRF-имена в шаблонах и контроллерах совпадают везде — рассинхрона нет.
