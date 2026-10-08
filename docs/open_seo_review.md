# OpenSEO (every-app/open-seo) — разбор

**Дата:** 2026-10-08 · **Репо:** https://github.com/every-app/open-seo · MIT · ~22.8k★ · создан 2026-02, активно пушится (v0.1.12).

## Вывод

Ставить как SEO-инструмент для WEARBASE **не нужно**: это UI + MCP поверх **DataForSEO**, а DataForSEO — Google-центричный (Labs, Google Ads volume, Google SERP/Maps). Yandex в коде нет ни строчки, а наш основной рынок — Яндекс (Wordstat + Yandex Search API уже дают ключи/SERP почти бесплатно). Ценность — в **идеях для заимствования**: формат agent-skills для SEO-аудита и блок AI Visibility (GEO).

## Что это

- Стек: TanStack Start (React) + Drizzle (SQLite/D1 и Postgres) + Cloudflare Workers/Workflows; self-host через Docker или Cloudflare (free plan).
- Модель денег: BYO-ключ DataForSEO, платишь по факту; хостинг openseo.so — $10/мес + 28% наценки на запросы к DataForSEO.
- Модули: keyword research, rank tracking, competitor insights, backlinks, site audit (свой краулер), AI Visibility, GSC/GA4-интеграции, отчёты с шарингом.
- **MCP-сервер** (~40 tools: `research_keywords`, `get_serp_results`, `get_ranked_keywords`, `run_site_audit`, `get_backlinks_*`, `get_search_console_performance`, AI-visibility, local SEO) + плагин для Claude Code/Cursor/Codex.
- **Agent skills** (`.agents/skills/`): `seo-audit`, `seo-report`, `keyword-research`, `keyword-clustering`, `competitor-analysis`, `competitive-landscape`, `link-prospecting`, `local-seo`, `ai-visibility-audit`, `ai-prompt-research`, `seo-coach`, `seo-project-setup`.

## Что полезно нам

1. **Скилл `seo-audit` — методология, а не код.** Сильные правила: «research broadly, recommend selectively» (1–3 рекомендации в отчёте); каждое утверждение о позиции подкреплено живой проверкой SERP (запрос, страна, дата, URL; «не найден в топ-20» ≠ «упавший запрос»); шорт-лист `opportunities.md` из ≥3 типов возможностей (недоработанная страница / спрос без страницы / защитить лидера); чтение ≥2 страниц на каждое семейство страниц. Прямо ложится на диагностику «Конвейер» за 15к ([[seo-services-pricing]]) — можно сделать свой скилл на Wordstat + Yandex Search API + GSC/Вебмастер.
2. **AI Visibility** — трекинг упоминаний бренда в ответах ChatGPT/Claude/Gemini/Perplexity (через DataForSEO `ai_optimization/*`, ~копейки за ответ). Это готовая схема для модуля GEO (+20к/мес в прайсе): набор промптов → периодический прогон → матчинг упоминаний/ссылок → тренд. Можно повторить сами через OpenRouter, без DataForSEO.
3. **Список проверок краулера** (27 issue-типов: redirect-chain/loop, canonical-conflict, duplicate title/description, thin-content, same-text, deep-page, no-outgoing-links, javascript-rendering-suspected, heading-order-skip и т.д.) — сверить с нашим `scripts/technical_audit.py`; у нас нет multipage-проверок (дубли title/текста между страницами, глубина, сироты).
4. Паттерн проектного контекста для агента (`get_project_context` / `update_project_context` + research log с TTL 30 дней) — переиспользование исследований между сессиями.

## Когда всё же поднять

- Для не-RU рынков (см. [regional_markets.md](regional_markets.md)) — там DataForSEO и так значился как источник; OpenSEO даёт готовый UI/MCP над ним за стоимость API.
- Для клиентов «Конвейера» с Google-трафиком — self-host в Docker за вечер.

## Ограничения

- Нет Yandex (ни SERP, ни Wordstat, ни Вебмастера).
- Данные трафика/ключей — оценки DataForSEO, не замеры.
- Ранняя версия (0.1.x), стек далёк от нашего (TS/Cloudflare vs Symfony) — код брать нечего, только идеи.
