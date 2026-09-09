# WEARBASE — Playwright E2E тесты

## Установка

```bash
cd /path/to/wearbase
npm install
npx playwright install chromium
```

## Запуск

```bash
# Все тесты (headless)
npx playwright test

# С видимым браузером
npx playwright test --headed

# Конкретный файл
npx playwright test tests/e2e/02-locale-switch.spec.ts

# Изолированный child ↔ parent flow (поднимает test-сервер и SQLite fixture)
npm run test:family

# Интерактивный UI
npx playwright test --ui

# Отчёт последнего запуска
npx playwright show-report
```

## Переменные окружения

| Переменная            | Значение по умолчанию        | Описание                    |
|-----------------------|------------------------------|-----------------------------|
| `BASE_URL`            | `http://wearbase.dev.local`  | Базовый URL сайта           |
| `TEST_USER_EMAIL`     | `test@wearbase.ru`           | Email тест-пользователя     |
| `TEST_USER_PASSWORD`  | `test123456`                 | Пароль тест-пользователя    |
| `TEST_BRAND_EMAIL`    | `brand@wearbase.ru`          | Email бренд-пользователя    |
| `TEST_BRAND_PASSWORD` | `brand123456`                | Пароль бренд-пользователя   |

## Файлы тестов

| Файл                          | Что тестирует                                |
|-------------------------------|----------------------------------------------|
| `01-homepage.spec.ts`         | Главная страница, шапка с переключателями    |
| `02-locale-switch.spec.ts`    | Переключение языка, cookie locale            |
| `03-currency-switch.spec.ts`  | Переключение валюты, API конвертации         |
| `04-checkout-shipping.spec.ts`| API доставки по странам, форма чекаута       |
| `05-brands-catalog.spec.ts`   | Каталог брендов, мультиязычные маршруты      |
| `11-family-purchase-flow.spec.ts` | 8 сценариев: инвайт, анкета, запросы, уведомления, решения, бюджет и IDOR |

## Структура

```
tests/e2e/
├── helpers/
│   └── auth.ts          # Функции авторизации
├── 01-homepage.spec.ts
├── 02-locale-switch.spec.ts
├── 03-currency-switch.spec.ts
├── 04-checkout-shipping.spec.ts
├── 05-brands-catalog.spec.ts
└── README.md
```

## CI/CD

В GitHub Actions добавить:

```yaml
- name: Install Playwright
  run: |
    npm ci
    npx playwright install --with-deps chromium

- name: Run E2E tests
  run: npx playwright test
  env:
    BASE_URL: http://localhost:8000
```
