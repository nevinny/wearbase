# Agent readiness — техническое SEO под AI-агентов

Подготовка сайта к AI-агентам и LLM-краулерам по чек-листу Cloudflare
[isitagentready.com](https://isitagentready.com/wearbase.ru). От 2026-06-18.

## Что сделано (scope 1: Discoverability + Content + Bots)

| Артефакт | Где | Что закрывает |
|---|---|---|
| `robots.txt` — группы AI-ботов + Content Signals | `public_html/robots.txt` | Bot Access Control: явные директивы для AI-UA, Content Signals |
| `/llms.txt` — markdown-индекс контента | `src/Controller/LlmsTxtController.php` | Content: llms.txt |
| HTTP `Link`-заголовок на HTML-ответах | `src/EventListener/AgentReadyListener.php` (+ арг в `config/services.yaml`) | Discoverability: Link header → llms.txt |
| `sitemap.xml` (был раньше) | `src/Controller/SitemapController.php` | Discoverability: sitemap |
| `/.well-known/api-catalog` (RFC 9727) + OpenAPI публичного currency API | `src/Controller/ApiCatalogController.php`, `public_html/.well-known/openapi-currency.json` | API/Auth/MCP: API Catalog (1/7) |
| Markdown content negotiation (`Accept: text/markdown`) | `src/EventListener/MarkdownNegotiationListener.php`, `src/Service/HtmlToMarkdownConverter.php` | Content: Markdown Negotiation (1/1 → категория 100) |

### Markdown content negotiation

При запросе с `Accept: text/markdown` листенер (priority 10, до AgentReadyListener) конвертирует
HTML-ответ 200 в markdown: `Content-Type: text/markdown; charset=utf-8`, `Vary: Accept`,
`X-Markdown-Tokens`/`X-Original-Tokens` (оценка токенов). Браузеры без этого заголовка получают
HTML как обычно. Конвертер — самодельный на DOMDocument (`HtmlToMarkdownConverter`), БЕЗ внешних
зависимостей: на проде vendor/ не деплоится (rsync исключает), composer-пакет потребовал бы
отдельного `composer install` на сервере при каждом изменении lock. Берём `<main>`/`<article>`
(или body), вырезаем nav/script/footer/form, выдаём frontmatter (title+source) + markdown тела.
Если нужна более точная конвертация (таблицы, вложенные списки) — заменить на
`league/html-to-markdown` (composer на проде есть).

### API Catalog

`/.well-known/api-catalog` отдаёт `application/linkset+json` (RFC 9264/9727): одна запись
для публичного **currency API** (`/currency/api`, без авторизации) со связями service-desc
(OpenAPI 3.1 в `/.well-known/openapi-currency.json`), service-doc (llms.txt) и status
(liveness `/currency/api/currencies`). Внутренний content agent-API (`/api/v1/*`)
аутентифицируется и в публичный каталог намеренно не включён.

### robots.txt

- Группа `User-agent: *` и отдельная группа AI-ботов (GPTBot, OAI-SearchBot, ChatGPT-User,
  ClaudeBot, Claude-User/SearchBot, PerplexityBot, Perplexity-User, Google-Extended,
  Applebot-Extended, Amazonbot, meta-externalagent, CCBot).
- **Content Signals** (https://contentsignals.org): `search=yes, ai-input=yes, ai-train=no` —
  разрешаем показ в поиске и использование в ответах AI-ассистентов, но **запрещаем обучение
  моделей** на контенте. Если решим разрешить обучение ради ещё большей видимости → `ai-train=yes`.
- ⚠️ Несколько строк `User-agent:` перед одним блоком правил = одна группа (спека robots.txt).
  Бот матчит только САМУЮ специфичную группу, поэтому Disallow-правила продублированы в обеих
  группах. Попутно починен старый баг: `Disallow: /admin/` был привязан к `Googlebot-Extended`,
  а не к `*`.

### /llms.txt

Динамический, по образцу `SitemapController` (канонический хост из `app.site_base_url`,
ru-локаль). В отличие от sitemap (исчерпывающий список) — это краткая карта ценного контента:
разделы → весь блог → топ-60 брендов с описанием ≥400 символов → ссылка на sitemap для полного
списка. Content-Type: `text/markdown`.

### Link header

`Link: <https://wearbase.ru/llms.txt>; rel="alternate"; type="text/markdown"` на всех
`text/html`-ответах главного запроса (не на sitemap/картинках/JSON).

## Что НЕ сделано (отложенные scope)

- **Scope 3 — Protocol Discovery / agentic commerce**: MCP-сервер, OAuth/OIDC discovery,
  agent-card/skills, WebMCP. Крупный отдельный проект. (`api-catalog` уже сделан — см. выше.)
  ⚠️ OAuth/OIDC/Protected-Resource/auth.md НЕ выкладываем «для галочки» — это метаданные
  аутентификации к защищённым API, которых для агентов нет; фейковые записи вредны.
  ⚠️ Проверка Cloudflare «Link header» в примере показывает `rel="api-catalog"`; наш Link
  указывает на llms.txt (`rel="alternate"`). Если их чекер требует именно api-catalog — это
  часть scope 3.

## Деплой

robots.txt и контроллер уезжают обычным rsync (см. [production.md](production.md)).
Смоук после деплоя:

```bash
for u in /robots.txt /llms.txt /sitemap.xml; do \
  curl -s -o /dev/null -w "$u %{http_code}\n" https://wearbase.ru$u; done
curl -sI https://wearbase.ru/ru/ | grep -i '^link:'
```
