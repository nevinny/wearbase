# E2E Tests: Current State

## Summary
- **Total tests**: 27 (26 original + 1 new checkout-flow)
- **Passing**: 19 (17 original + brand-claim + checkout-flow)
- **Pre-existing failures**: 8 (unchanged — locale switches, checkout shipping API)

## New Tests

| Test | File | Status | Notes |
|------|------|--------|-------|
| Brand Claim Flow | `06-brand-claim.spec.ts` | ✅ Passing | Registration → brand page → claim form → success |
| Checkout Flow | `07-checkout-flow.spec.ts` | ✅ Passing | Registration → add to cart → checkout → order success |

## Changes Made

### 1. Migration fix: `migrations/Version20260524_yookassa.php`
- Removed backticks around `order` in `CALL wb_add_payment_col()` to fix SQL syntax error
- Column `gateway_payment_id` now exists on `order` table

### 2. Brand claim UI: `templates/tailwind/brand/showv2.html.twig`
- Added "Вы владелец этого бренда?" section with claim button
- Shows for authenticated users without brand manager role, if no pending claim exists

### 3. Security: `config/packages/security.yaml`
- Added `^/brand-claim → ROLE_USER` access rule (was blocked by `^/brand → ROLE_BRAND_MANAGER`)

### 4. PaymentService autowire: `config/services.yaml`
- Added `App\Service\PaymentService` config with `$shopId` and `$secretKey` env vars

### 5. YooKassa credentials: `.env.local`
- Added `YOOKASSA_SHOP_ID` and `YOOKASSA_SECRET_KEY` for dev environment

## Known Issues (pre-existing)
- Locale switch tests fail in tailwind template (strict mode, cookie reading)
- Checkout shipping API tests fail with JSON parse (page.request.get not authenticated)
- Homepage tests fail with strict mode violation on `text=WEARBASE`
