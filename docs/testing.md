# Тест-харнес (PHPUnit)

Архитектура функционального/интеграционного тест-харнеса WEARBASE. Починен 2026-07-07
(до этого: 13 errors / 30 failures из-за трёх причин — см. «История» внизу).

Запуск:

```bash
/opt/homebrew/bin/php -d memory_limit=512M bin/phpunit
/opt/homebrew/bin/php -d memory_limit=512M bin/phpunit tests/Controller/BrandLkControllerTest.php
```

## Тест-БД: одноразовый SQLite, схема из сущностей

- **`var/test.db`** (SQLite) — задан в `.env.test` (`DATABASE_URL="sqlite:///%kernel.project_dir%/var/test.db"`).
- MySQL `*_test` **не используется**: `dbname_suffix: '_test…'` в `config/packages/doctrine.yaml when@test` — мёртвый конфиг, перекрыт sqlite-URL (оставлен закомментированным на случай возврата к MySQL).
- `tests/bootstrap.php` при `APP_ENV=test` на каждый прогон:
  1. бутит kernel, берёт `doctrine.orm.entity_manager`;
  2. только для SQLite-платформы — **удаляет старый файл** и `SchemaTool::createSchema()` из метаданных всех сущностей → схема всегда соответствует текущим entity, миграции гонять не нужно, «протухший» var/test.db невозможен;
  3. **мирроит сырые не-entity таблицы** (создаются миграциями, SchemaTool их не видит): сейчас это `brand_related`;
  4. **сидит минимум справочников**: базовая валюта RUB (`is_base`) + язык `ru`. Без них Twig-global `app_currency` = null и страницы с ценами (cart/checkout) падают в 500.

`.env.local` в test-окружении Symfony **не загружается** — всё нужное дублируется в `.env.test`
(в т.ч. dummy `TURNSTILE_KEY`/`TURNSTILE_SECRET`, иначе `/register` = 500).

## Аутентификация: реальные персистентные пользователи

`loginUser()` кладёт пользователя в токен; на следующем запросе `EntityUserProvider::refreshUser()`
перечитывает его из БД по идентификатору. Поэтому тестовый пользователь **должен существовать в БД**
(неперсистентная заглушка без id → «cannot refresh a user without identifier» → HTTP 500).

- **`tests/Controller/UserFactory.php`** — find-or-create + `persist`/`flush` пользователей с хешированным паролем (`UserPasswordHasherInterface`). Тот же провайдер, что в проде — никаких in-memory-подмен. Emails в namespace `harness-*`, чтобы не конфликтовать с email'ами, захардкоженными в KernelTestCase-тестах (напр. `owner@test.local`).
  - `customer()` / `brandManager()` / `brandOwner()` — по роли;
  - `brandOwnerWithBrand()` — владелец + Brand + связь `BrandUser` (owner), нужно для `/brand` LK (контроллер резолвит активный бренд по `findOneBy(['user'=>$user])`) и checkout.
- **`tests/Controller/AuthenticatedWebTestCase.php`** — база с хелперами `loginAsCustomer()`, `loginAsBrandOwner()`, `loginAsBrandOwnerWithBrand()`. Порядок: сначала `static::createClient()`, потом login-хелпер (он берёт `static::getContainer()` — контейнер того же kernel с доступом к приватным сервисам).
- **`tests/Controller/DatabaseDependentWebTestCase.php`** — база уровнем ниже: `skipIfNoDatabase()` (мягкий скип, если БД недоступна). Гостевые/security-тесты (редиректы) БД не требуют.

## Как писать функциональный тест ревенью-пути

```php
class MyFlowTest extends AuthenticatedWebTestCase
{
    public function testBrandOwnerSeesDashboard(): void
    {
        $this->skipIfNoDatabase();
        $client = static::createClient();
        [$user, $brand] = $this->loginAsBrandOwnerWithBrand($client);
        $client->request('GET', '/brand/dashboard');
        $this->assertResponseIsSuccessful();
    }
}
```

- Свои доменные фикстуры (Brand/Product/Order) создавать через `static::getContainer()->get('doctrine.orm.entity_manager')`.
- KernelTestCase-тесты (`tests/Repository/*`, `tests/Service/*Integration*`) оборачивают всё в `beginTransaction()`/`rollback()` — изоляция без мусора. Функциональные тесты через `KernelBrowser` так изолировать нельзя (kernel перезагружается на запрос) → там фикстуры удаляй в `tearDown` физически (системная операция, не user-action — soft-delete-правило не нарушается).

## Известное независимое ограничение (не харнес)

`BrandRelatedGraphTest::testReplaceDeadEdgesRemovesEdgeToNonActiveTarget` **красный**:
`src/Service/BrandLinkGraphService.php` (~строка 298) пишет `created_at = NOW()` сырым SQL,
а в SQLite функции `NOW()` нет. Это MySQL-диалект в прод-коде, не относится к RC1/RC2/RC3.
Починка (отдельной задачей, требует правки src/): заменить `NOW()` → `CURRENT_TIMESTAMP`
(портируется и в MySQL, и в SQLite). Читающий путь графа (`findRelatedHard`) NOW() не использует,
поэтому страница бренда и остальные тесты графа зелёные.

## TODO
- `phpunit.dist.xml`: `failOnDeprecation` временно `false` — прод-форма регистрации (`RegistrationFormType`) задаёт `Assert\IsTrue` массивом опций (deprecated в symfony/validator 7.3). Вернуть `true` после перевода на именованные аргументы (правка src/).
- Тесты ревенью-путей (формы импорта/переводов/оферты/счёта, вебхук-путь заказа) — следующий шаг поверх починенного харнеса.

## История (что было сломано)
- **RC1**: `UserFactory` отдавал неперсистентные `User` без id → refreshUser 500 на всех auth-тестах.
- **RC2**: `var/test.db` протух (схема от старой даты, нет `brand.origin_status` и т.п.) → errors в KernelTestCase-тестах.
- **RC3**: не было `TURNSTILE_KEY` (/register 500) и базового справочника Currency/Language (/cart 500).
