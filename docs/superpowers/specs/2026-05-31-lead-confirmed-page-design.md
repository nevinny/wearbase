# Lead Confirmed Page — Design Spec

## Problem
После отправки формы на лендинге `without-marketplaces` пользователь редиректится обратно на ту же страницу с flash-сообщением. Это не соответствует ожиданиям продавцов, уходящих с маркетплейсов (Ozon/WB), где принят паттерн отдельной страницы подтверждения.

## Solution
Отдельная страница подтверждения заявки `/ru/join/confirmed` в минимальном стиле (как Ozon):

1. Зелёная галочка (✅)
2. «Заявка принята»
3. Текст: «Мы написали вам на почту — письмо с первыми шагами уже в пути. Менеджер свяжется в течение 24 часов.»
4. Кнопка «Смотреть каталог» → `/ru/brands`
5. Ссылка «Вернуться на главную» → `/ru`

## Technical Details

### Route
- **URL**: `/{_locale}/join/confirmed`
- **Name**: `landing_lead_confirmed`
- **Methods**: GET
- **Locales**: en, ru (default ru)
- **Auth**: none
- **Robots**: `noindex, follow` (спасибочная страница)

### Template
- File: `templates/tailwind/landing/lead-confirmed.html.twig`
- Extends: `tailwind/landing/base.html.twig`
- Blocks: `title`, `meta_robots`, `body`

### Controller Change (`LandingController.php`)
- `leadCapture()`: после `flush()` редирект не на `referer`, а на `landing_lead_confirmed`
- Новая страница не требует отдельного метода — можно сразу в `noMarketplace()`, но чище добавить отдельный метод `leadConfirmed()` → возвращает только рендер шаблона (без данных)

### SEO
- `noindex, follow` — не нужно индексировать страницу спасибо
- canonical: self

## Files Changed
1. `src/Controller/LandingController.php` — добавить метод `leadConfirmed()`, изменить редирект в `leadCapture()`
2. `templates/tailwind/landing/lead-confirmed.html.twig` — новый шаблон
