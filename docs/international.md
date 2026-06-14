# WEARBASE: Международные рынки

> Документ описывает архитектуру и реализацию поддержки международных рынков в платформе WEARBASE.
> Версия: 2026-05-23

---

## Содержание

1. [Обзор](#обзор)
2. [Сущности и таблицы БД](#сущности-и-таблицы-бд)
   - [Language — Языки](#language--языки)
   - [Country — Страны](#country--страны)
   - [City — Города](#city--города)
   - [Currency — Валюты](#currency--валюты)
   - [ExchangeRate — Курсы валют](#exchangerate--курсы-валют)
3. [Сервис конвертации валют](#сервис-конвертации-валют)
4. [Консольные команды](#консольные-команды)
5. [Seed-данные](#seed-данные)
6. [Приоритетные рынки](#приоритетные-рынки)
7. [Администрирование](#администрирование)
8. [Roadmap](#roadmap)

---

## Обзор

WEARBASE поддерживает работу с несколькими языками, странами и валютами.

Архитектурный принцип:

- **Все цены хранятся в базовой валюте платформы — RUB.**
- Конвертация в другие валюты выполняется **на лету** сервисом `CurrencyConverter`.
- Курсы валют обновляются **ежедневно** консольной командой из ЦБ РФ.
- Языки используются для локализации контента (Symfony translation / Twig).
- Страны и города применяются в адресах доставки, геотаргетинге и налоговых правилах.

---

## Сущности и таблицы БД

### Language — Языки

**Файл:** `src/Entity/Language.php`  
**Таблица:** `language`

| Поле            | Тип          | Описание                                          |
|-----------------|--------------|---------------------------------------------------|
| `id`            | INT          | Первичный ключ                                    |
| `code`          | VARCHAR(5)   | ISO 639-1: `ru`, `en`, `zh`, `ar`, `tr`, `kk`    |
| `native_name`   | VARCHAR(80)  | Название на родном языке: `Русский`, `English`    |
| `name_ru`       | VARCHAR(80)  | Название на русском для интерфейса WEARBASE       |
| `text_direction`| VARCHAR(3)   | `ltr` (большинство) или `rtl` (арабский, иврит)  |
| `is_active`     | BOOL         | Отображается ли в публичных списках               |
| `is_default`    | BOOL         | Язык по умолчанию для нового контента (один)      |
| `sort_order`    | INT          | Порядок сортировки                                |

**Seed:** ru, en, zh, ar, tr, de, fr, kk, be.

**Получить активные языки:**
```php
$languages = $languageRepo->findActive();
$default   = $languageRepo->findDefault(); // Language{code: 'ru'}
```

---

### Country — Страны

**Файл:** `src/Entity/Country.php`  
**Таблица:** `country`

| Поле                  | Тип          | Описание                                             |
|-----------------------|--------------|------------------------------------------------------|
| `id`                  | INT          | Первичный ключ                                       |
| `code`                | VARCHAR(2)   | ISO 3166-1 alpha-2: `RU`, `US`, `AE`, `CN`          |
| `code3`               | VARCHAR(3)   | ISO 3166-1 alpha-3: `RUS`, `USA` (для интеграций)   |
| `name_ru`             | VARCHAR(120) | Название на русском                                  |
| `name_en`             | VARCHAR(120) | Название на английском                               |
| `phone_code`          | VARCHAR(10)  | Телефонный код: `+7`, `+971`, `+86`                  |
| `default_currency_id` | FK→Currency  | Валюта страны по умолчанию                           |
| `default_language_id` | FK→Language  | Основной язык страны                                 |
| `region`              | VARCHAR(30)  | `europe`, `asia`, `middle_east`, `americas`, `africa`|
| `flag_emoji`          | VARCHAR(10)  | 🇷🇺, 🇺🇸 — генерируется из `code`                     |
| `is_active`           | BOOL         | Доступна для выбора пользователями                   |
| `sort_order`          | INT          | Порядок сортировки                                   |

**Генерация emoji-флага:**
```php
$flag = Country::flagEmojiFromCode('RU'); // 🇷🇺
```

**Поиск страны:**
```php
$russia = $countryRepo->findByCode('RU');
$found  = $countryRepo->search('казах', limit: 5);
```

---

### City — Города

**Файл:** `src/Entity/City.php`  
**Таблица:** `city`

| Поле         | Тип           | Описание                                          |
|--------------|---------------|---------------------------------------------------|
| `id`         | INT           | Первичный ключ                                    |
| `country_id` | FK→Country    | Страна (обязательно)                              |
| `name_ru`    | VARCHAR(120)  | Название на русском                               |
| `name_en`    | VARCHAR(120)  | Название на английском                            |
| `region`     | VARCHAR(120)  | Область/регион внутри страны                      |
| `latitude`   | DECIMAL(10,7) | Широта (для карт и расчёта расстояния)            |
| `longitude`  | DECIMAL(10,7) | Долгота                                           |
| `population` | INT           | Население (для сортировки в автодополнении)       |
| `is_active`  | BOOL          | Отображается в публичных списках                  |

**Seed:** 30 городов России, 6 городов Казахстана, 5 городов ОАЭ.

**Автодополнение:**
```php
// Поиск по стране
$cities = $cityRepo->search('москв', country: $russia, limit: 10);

// Все города страны
$cities = $cityRepo->findByCountry($russia, limit: 50);
```

---

### Currency — Валюты

**Файл:** `src/Entity/Currency.php`  
**Таблица:** `currency`

| Поле                  | Тип         | Описание                                           |
|-----------------------|-------------|----------------------------------------------------|
| `id`                  | INT         | Первичный ключ                                     |
| `code`                | VARCHAR(3)  | ISO 4217: `RUB`, `USD`, `EUR`, `CNY`, `AED`        |
| `symbol`              | VARCHAR(10) | ₽, $, €, ¥, ₸, ₺                                 |
| `symbol_position`     | VARCHAR(6)  | `prefix` ($99) или `suffix` (99 ₽)                |
| `name_ru`             | VARCHAR(80) | Российский рубль                                   |
| `name_en`             | VARCHAR(80) | Russian Ruble                                      |
| `decimal_places`      | INT         | Знаков после запятой: 2 (большинство), 0 (JPY)     |
| `decimal_separator`   | VARCHAR(1)  | `.` или `,`                                        |
| `thousands_separator` | VARCHAR(1)  | пробел, `,` или `.`                                |
| `is_base`             | BOOL        | Базовая валюта платформы (только одна = RUB)       |
| `is_active`           | BOOL        | Доступна для выбора пользователями                 |

**Seed:** RUB (base), USD, EUR, CNY, AED, TRY, KZT, BYN, GBP, JPY, CHF.

**Форматирование:**
```php
$rub = $currencyRepo->findByCode('RUB');
echo $rub->format(1500.5); // "1 500.50 ₽"

$usd = $currencyRepo->findByCode('USD');
echo $usd->format(16.5);  // "$16.50"
```

---

### ExchangeRate — Курсы валют

**Файл:** `src/Entity/ExchangeRate.php`  
**Таблица:** `exchange_rate`

| Поле                   | Тип           | Описание                                          |
|------------------------|---------------|---------------------------------------------------|
| `id`                   | INT           | Первичный ключ                                    |
| `base_currency_id`     | FK→Currency   | Базовая валюта (обычно RUB)                       |
| `target_currency_id`   | FK→Currency   | Целевая валюта                                    |
| `rate`                 | DECIMAL(18,8) | 1 base = rate target (напр. 1 RUB = 0.011 USD)   |
| `rate_date`            | DATE          | Дата котировки                                    |
| `source`               | VARCHAR(30)   | `cbr`, `fixer`, `manual`, `seed`                  |
| `updated_at`           | DATETIME      | Время обновления записи                           |

**Уникальный ключ:** `(base_currency_id, target_currency_id, rate_date)` — один курс в день.

**Получить курс:**
```php
$latest = $rateRepo->findLatest($rubCurrency, $usdCurrency);
echo $latest->getRate(); // "0.01083000"
echo $latest->convert(1500.0); // 16.245
```

---

## Сервис конвертации валют

**Файл:** `src/Service/CurrencyConverter.php`

Загружает все актуальные курсы из БД **один раз** и кеширует в Symfony Cache на 1 час.

### Подключение

```php
use App\Service\CurrencyConverter;

class ProductController
{
    public function __construct(private readonly CurrencyConverter $converter) {}
}
```

### API

```php
// Конвертировать 1500 RUB в USD
$usd = $converter->convert(1500.0, 'RUB', 'USD');  // float|null

// Конвертировать и форматировать
$str = $converter->format(1500.0, 'RUB', 'USD');   // "$16.25"
$str = $converter->format(1500.0, 'RUB', 'RUB');   // "1 500.00 ₽"

// Конвертировать в все активные валюты
$all = $converter->convertToAll(1500.0, 'RUB');
// ['USD' => 16.25, 'EUR' => 15.05, 'CNY' => 117.15, …]

// Сбросить кеш после обновления курсов
$converter->clearCache();
```

### Логика поиска курса

1. Ищет пару `FROM→TO` напрямую.
2. Если не найдено — ищет обратную пару `TO→FROM` и считает `1 / rate`.
3. Если ни одна не найдена — возвращает `null` и пишет `warning` в лог.

---

## Консольные команды

### `app:currency:update-rates`

Обновляет курсы валют из ЦБ РФ (бесплатно, без API-ключа).

```bash
# Стандартный запуск
php bin/console app:currency:update-rates

# Только посмотреть, не писать в БД
php bin/console app:currency:update-rates --dry-run

# Указать источник (сейчас поддерживается только cbr)
php bin/console app:currency:update-rates --source=cbr

# Получить курс на конкретную дату
php bin/console app:currency:update-rates --date=01/05/2026
```

**Настройка cron (ежедневно в 12:00):**
```cron
0 12 * * * /usr/bin/php /var/www/wearbase/bin/console app:currency:update-rates >> /var/log/wearbase/exchange-rates.log 2>&1
```

**Что делает команда:**
1. Загружает XML-фид с `cbr.ru/scripts/XML_daily.asp`.
2. Парсит курсы валют (в XML указано: N единиц = M рублей).
3. Вычисляет обратный курс: 1 RUB = (N/M) единиц.
4. Делает upsert (вставка или обновление) в таблицу `exchange_rate`.
5. Сбрасывает кеш `CurrencyConverter`.

**Пример вывода:**
```
 ! [NOTE] Базовая валюта: RUB
 ! [NOTE] Запрос к ЦБ РФ: https://www.cbr.ru/scripts/XML_daily.asp
 ! [NOTE] Получено курсов: 34

 ------- ------- -------------- --------
  Из      В       Курс           Источник
 ------- ------- -------------- --------
  RUB     USD     0.01083000     cbr
  RUB     EUR     0.01003000     cbr
  RUB     CNY     0.07810000     cbr
  RUB     AED     0.03978000     cbr
  RUB     TRY     0.36100000     cbr
  RUB     KZT     5.23000000     cbr
 ------- ------- -------------- --------

 [OK] Сохранено: 10 курсов, пропущено: 0
```

---

## Seed-данные

Все начальные данные загружаются миграцией `Version20260523_international`:

| Таблица         | Записей | Описание                                              |
|-----------------|---------|-------------------------------------------------------|
| `language`      | 9       | ru, en, zh, ar, tr, de, fr, kk, be                   |
| `currency`      | 11      | RUB (base), USD, EUR, CNY, AED, TRY, KZT, BYN, GBP, JPY, CHF |
| `country`       | 15      | Россия и 14 приоритетных рынков                       |
| `city`          | ~41     | Топ-30 РФ + 6 КЗ + 5 ОАЭ                             |
| `exchange_rate` | 10      | Примерные курсы (обновятся командой)                  |

**Применить миграцию:**
```bash
php bin/console doctrine:migrations:migrate
```

---

## Приоритетные рынки

| Рынок         | Код | Валюта | Язык    | Причина                                       |
|---------------|-----|--------|---------|-----------------------------------------------|
| Россия        | RU  | RUB    | ru      | Основной рынок                                |
| Казахстан     | KZ  | KZT    | kk/ru   | Русскоязычная аудитория, рост e-commerce      |
| Беларусь      | BY  | BYN    | be/ru   | Общая культура, схожие предпочтения           |
| ОАЭ           | AE  | AED    | ar/en   | Русская диаспора, высокая покупательная сила  |
| Турция        | TR  | TRY    | tr      | Производство одежды, туристический трафик     |
| Китай         | CN  | CNY    | zh      | Производители и OEM-партнёры                  |
| Армения       | AM  | AMD    | ru      | Релокация, русскоязычная аудитория            |
| Грузия        | GE  | GEL    | ru/en   | Релокация, туристический трафик               |

---

## Администрирование

Управление справочниками через EasyAdmin (`/admin`):

| Контроллер                    | Сущность      | Статус     |
|-------------------------------|---------------|------------|
| `LanguageCrudController`      | Language      | ✅ готово  |
| `CountryCrudController`       | Country       | ✅ готово  |
| `CityCrudController`          | City          | ✅ готово  |
| `CurrencyCrudController`      | Currency      | ✅ готово  |
| `ExchangeRateCrudController`  | ExchangeRate  | ✅ готово  |
| `ShippingRuleCrudController`  | ShippingRule  | ✅ готово  |
| `TaxRuleCrudController`       | TaxRule       | ✅ готово  |
| `BrandMarketCrudController`   | BrandMarket   | ✅ готово  |

**Ручное добавление курса:**
```sql
INSERT INTO exchange_rate (base_currency_id, target_currency_id, rate, rate_date, source, updated_at)
SELECT b.id, t.id, 0.01083, CURDATE(), 'manual', NOW()
FROM currency b, currency t
WHERE b.code = 'RUB' AND t.code = 'USD';
```

---

## SEO непереведённых локалей (решение 2026-06-14)

**Контекст.** Таблицы `brand_translation` / `product_translation` **пусты** — все 9 локалей
(`/{en,zh,ar,tr,de,fr,es,ko}/…`) отдают один и тот же **русский** контент (fallback через
`brand_t()`/`product_t()`). Маршруты и переключатель языка работают, переводов контента нет.

**Проблема.** Непереведённые локали отдавались с `HTTP 200` + русский текст. Это классический
*scaled-content* (дубли одной страницы на 9 URL). Несмотря на `canonical → /ru/…` (это лишь
подсказка), Google индексировал часть `/en/`,`/fr/` дублей и жёг на остальных краул-бюджет —
вероятная причина **270 «Discovered – currently not indexed»** в GSC. Сверка с методичкой
`_seo/CLOSEDLOOP-SEO-FULL/docs/SEO_Guide_4.9.md` (§111, §222): отсутствующий перевод должен
давать 404 либо быть исключён из индекса, hreflang — только для реальных переводов (`isReal`).

**Решение: не-ru локали → `noindex, follow`** (выбран как обратимый и не ломающий UX
переключателя языков; альтернативы 404/410 и «делать реальные переводы» отклонены на этом этапе).
Реализация — одно место, `templates/tailwind/base.html.twig`, блок `hreflang`:

- `current_locale` вычисляется из первого сегмента пути;
- ru — индексируется как обычно; canonical и hreflang без изменений;
- **любая не-ru локаль** получает `<meta name="robots" content="noindex, follow">`
  (`follow` — чтобы Google продолжал обходить внутренние ссылки и находил ru-страницы);
- собственный `meta_robots` страницы эмитится **только на ru** (на не-ru приоритет у noindex,
  без конфликта директив);
- `sitemap.xml` и так **только ru** (`SitemapController`) — конфликта «submitted, но noindex» нет.

**Как откатить при появлении реальных переводов** (по локали): когда для локали есть
настоящий перевод (`brand_translation` заполнен, проверка `isReal`), для неё нужно:
1) вернуть self-canonical (вместо `→ /ru/…`) и добавить её в `hreflang`;
2) снять `noindex` (условие `current_locale != 'ru'` заменить на «нет реального перевода»);
3) добавить её URL в `sitemap.xml`.

---

## Roadmap

### Фаза 1 — Текущая (реализована)
- [x] Сущности: Language, Country, City, Currency, ExchangeRate
- [x] Репозитории с поиском и автодополнением
- [x] Сервис `CurrencyConverter` с кешированием
- [x] Консольная команда обновления курсов из ЦБ РФ
- [x] Seed-данные для 15 стран, 41 города, 11 валют
- [x] Миграция с автоматической привязкой валют и языков к странам

### Фаза 2 — Интерфейс ✅ завершена
- [x] Выбор валюты в шапке сайта (cookie/session) — `CurrencySession`, `CurrencyExtension`
- [x] Отображение цен в выбранной валюте в карточках товаров — Twig-фильтр `price_convert`
- [x] Admin CRUD для всех новых справочников — 5 CRUD-контроллеров в EasyAdmin
- [x] API-эндпоинт `/api/currencies` и `/api/exchange-rates` — `CurrencyController`

### Фаза 3 — Локализация контента ✅ завершена
- [x] Таблицы переводов для Brand и Product (`brand_translation`, `product_translation`)
- [x] Symfony Translations для интерфейса (`messages.ru.yaml`, `messages.en.yaml`)
- [x] Определение языка по Accept-Language заголовку — `LocaleListener` (priority 20)
- [x] Переключатель языка в шапке — `LocaleController POST /locale/switch` + cookie
- [x] Twig-функции `brand_t()` / `product_t()` с in-memory кешем и fallback — `TranslationExtension`
- [x] Мультиязычные маршруты `/{_locale}/brands/` (en|ru|zh|ar|tr|de|fr|es|ko)

### Фаза 4 — Логистика и налоги 🔶 частично
- [x] Правила доставки по странам — `ShippingRule` entity + repo + CRUD + seed-данные (RU/BY/KZ/DE/FR/GB/AE/TR)
- [x] Налоговые правила НДС по странам — `TaxRule` entity + repo + CRUD + seed-данные
- [x] Интеграция доставки в чекаут — `CheckoutController` с AJAX-эндпоинтом `/checkout/shipping-rules`
- [ ] Интеграция с DHL/FedEx API (live tracking, label generation)
- [ ] Валютный контроль: ограничения на транзакции из/в определённые страны

### Фаза 5 — Маркетплейс 🔶 частично
- [x] Витрины по странам — `BrandMarket` entity + repo + CRUD + migration
- [x] Связь бренд–страна с настройками доставки и статусом
- [ ] Публичные страницы витрин (фронтенд `/brands?country=DE`)
- [ ] Локальные платёжные методы (Kaspi для KZ, Payme для UZ, BENEFIT для BH)
- [ ] IP-геотаргетинг для автоопределения страны пользователя
- [ ] Геотаргетинг для рекламных кампаний
