# WEARBASE SEO Implementation Plan

**Обновлено:** 2026-05-14
**Статус:** В РАБОТЕ

---

## Цель
Применить SEO Guide 2026 к wearbase.ru — масштабируемый контент для брендов через LLM.

---

## Ограничения
- OpenRouter API (Claude Haiku) — заменил локальный Ollama
- Бренды зависят от локали (RU/EN)
- Без content manager — всё через LLM
- Начать с RU, skip homepage и переводы

---

## Что сделано

### 1. Инфраструктура LLM
- [x] OpenRouter интеграция (LlmService.php)
- [x] API key в .env.local
- [x] Модель: anthropic/claude-3.5-haiku

### 2. Генерация контента
- [x] GenerateBrandContentCommand — description + meta
- [x] Meta из существующего description (вариант B)
- [x] Meta fields в Brand entity (metaTitle, metaDescription, metaKeywords)
- [x] Миграция для meta полей
- [x] Опция --id для конкретного бренда
- [x] Опция --meta-only для брендов с описанием
- [x] Retry logic (до 3 попыток) для meta генерации

### 3. Валидация контента
- [x] ContentValidator.php — 21 AI-фраза
- [x] Проверка: description (170+ слов), meta (title ≤60, desc ≤155 символов), без URL
- [x] CheckBrandContentCommand — массовая проверка
- [x] Экспорт в JSON

### 4. SEO структура
- [x] Hub страница /ru/ — Organization schema, BreadcrumbList
- [x] Каталог /ru/brands/ — meta, schema, breadcrumbs
- [x] Страница бренда — og:title/description, BreadcrumbList
- [x] SitemapController — все URL
- [x] robots.txt — sitemap, disallow admin
- [x] hreflang в base.html.twig
- [x] OG image /og-image.svg

### 5. Репозиторий
- [x] findSimilarBrands() — по городу и стилям
- [x] findFeaturedBrands() — с описанием 100+ символов

### 6. Шаблоны
- [x] hub.html.twig — популярные бренды, города, стили
- [x] brand/index.html.twig — SEO meta
- [x] brand/showv2.html.twig — similar brands, og:image (исправлен доступ к BrandLink)
- [x] base.html.twig — hreflang, og:image, canonical

### 7. Документация
- [x] docs/changelog.md — история изменений
- [x] docs/seo_rules.md — правила и принципы SEO
- [x] docs/seo_tools.md — список инструментов

---

## Аудит 2026-05-14

### ✅ Проверено и работает
| Проверка | Статус |
|----------|--------|
| robots.txt | ✓ |
| sitemap.xml | ✓ |
| Schema.org (Organization, BreadcrumbList, WebSite) | ✓ |
| Canonical | ✓ нет mismatch |
| H1 | ✓ по одному на страницу |
| Brand Schema | ✓ |

### ⚠️ Проблемы (приоритеты)

#### КРИТИЧНО — Исправить в первую очередь
- [ ] **Hreflang** — 345 страниц без self-referencing hreflang
  - Каждая страница бренда должна содержать `<link rel="alternate" hreflang="x-default" href="https://wearbase.ru/ru/brands/{slug}">`
  - Проверить bidirectional: `/ru/brands/{slug}` → `/ru/`, но `/ru/` не возвращает обратно
  - Файл: `templates/tailwind/base.html.twig`

- [ ] **HTTP→HTTPS** — отсутствует HSTS header, TTFB 911ms (цель <600ms)
  - Настроить на уровне сервера (nginx/cloudflare)

#### СРЕДНЕ — Второй приоритет
- [ ] **Контент брендов** — 8/10 с <170 словами
  - Запустить перегенерацию для брендов с короткими описаниями
  - 22 бренда без описания

- [ ] **Meta description** — 1 бренд без meta_description
  - Команда: `php bin/console app:brand:generate-content --meta-only 500`

---

## Что нужно сделать

### Фаза 1: Исправления аудита
- [ ] Исправить hreflang в base.html.twig (self-reference + bidirectional)
- [ ] Настроить HSTS + оптимизировать TTFB
- [ ] Перегенерировать description для брендов <170 слов
- [ ] Сгенерировать meta для всех брендов с описанием

### Фаза 2: Парсинг данных (СОГЛАСНО SEO Guide)
- [ ] Создать парсер VK API для сбора данных о брендах
- [ ] Парсить официальные сайты брендов
- [ ] Хранить собранные данные в отдельной таблице

### Фаза 3: RAG pipeline (СОГЛАСНО SEO Guide)
- [ ] RAG lookup перед генерацией
- [ ] Multi-source context (VK + website + каталог)
- [ ] Self-heal с retry (max 3, temperature decay)

### Фаза 4: Self-heal
- [ ] Интегрировать checklist.py логику
- [ ] Автоматический retry при fail валидации
- [ ] Reject после 3 fails

### Фаза 5: Anti-ban меры
- [ ] Лимиты: max 5 статей/день (из SEO Guide)
- [ ] Content velocity pacing
- [ ] Author rotation (если будут авторы)

### Фаза 6: Мониторинг
- [ ] Интеграция с GSC API
- [ ] Отслеживание indexed/not-indexed
- [ ] Alert при проблемах

---

## Команды

```bash
# Генерация meta для всех брендов с описанием
php bin/console app:brand:generate-content --meta-only 500

# Генерация description для брендов без
php bin/console app:brand:generate-content 100

# Конкретный бренд
php bin/console app:brand:generate-content --id=148

# Проверка контента
php bin/console app:brand:check-content --limit=100

# Экспорт проблем
php bin/console app:brand:check-content --limit=500 --export=/tmp/issues.json
```

---

## Ключевые решения

### Локализация
- Hybrid (C): часть брендов только RU, часть переводить позже
- Locale-specific slugs: /ru/brands/xxx vs /en/brands/xxx

### Контент
- Generic генерация → Unique per brand (через RAG)
- Focus RU first, expand later

### AI phrases (бан)
```
инновационный, уникальный, передовой, лидирующий, новаторский,
выделяется, отличается, несравненный, беспрецедентный
```

---

## Документы
- SEO Guide: `/Volumes/SAMSUNG-origin/Users/zyablik/Downloads/SEO_GUIDE_2026-04-17/`
- wearbase: `/Volumes/SAMSUNG-origin/Users/zyablik/work/wearbase/`

---

## Ссылки
- LLM Service: `src/Service/LlmService.php`
- Content Validator: `src/Service/ContentValidator.php`
- Generate Command: `src/Command/GenerateBrandContentCommand.php`
- Check Command: `src/Command/CheckBrandContentCommand.php`
- Hub Template: `templates/tailwind/hub.html.twig`
- Base Template: `templates/tailwind/base.html.twig`