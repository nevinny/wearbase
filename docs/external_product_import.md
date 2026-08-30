# Provider-agnostic импорт покупок

Запрос на покупку хранит только provider-neutral URL и цены. Provider-specific payload, cookies и
идентификаторы маркетплейса в `PurchaseRequest`/`PurchaseRequestItem` не попадают.

## Контракт

- `ExternalProductUrl` принимает только HTTPS без credentials, custom port, IP/localhost,
  control characters и неоднозначных backslash; host нормализуется, fragment удаляется.
- `ExternalProductSnapshot` имеет фиксированную схему: provider code, normalized source URL, optional
  external ID/title/price и ISO currency.
- `ExternalProductProviderInterface` отвечает только за direct product URL.
- `SharedCartProductProviderInterface` — отдельная optional capability, чтобы обычный provider не
  был обязан поддерживать корзины.
- `ExternalProductProviderRegistry` выбирает специализированный provider первым, а
  `ManualProductProvider` — обязательный fallback для любого неизвестного HTTPS URL.

Manual provider не имеет HTTP-клиента и никогда не fetch'ит неизвестный URL. Пользователь может
явно вставить до десяти отдельных ссылок; они нормализуются и дедуплицируются до записи существующих
domain entities. Добавление второго provider проверяется контрактным unit-тестом и не требует
миграции purchase tables.

## Shared cart feature flag

`PURCHASE_SHARED_CART_ENABLED=0` по умолчанию. Даже при включении registry допускает shared cart
только для provider, который явно реализует `SharedCartProductProviderInterface`; manual/unknown URL
возвращает безопасный fallback «добавьте отдельные ссылки». Raw HTML, cookies и browser session
не используются.

Перед включением в production для конкретного магазина остаются обязательными:

1. provider adapter с allowlist точных HTTPS hosts и bounded response;
2. обезличенные contract fixtures прямой ссылки и shared-cart payload/redirect;
3. timeout/size limits, SSRF-тесты и подтверждение работы без пользовательской авторизации;
4. отдельный review условий использования provider. Wildberries-specific adapter в этот PR не входит.
