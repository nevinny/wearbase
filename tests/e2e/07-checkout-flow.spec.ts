import { test, expect } from '@playwright/test';

test.setTimeout(120000);

/**
 * Полный флоу оформления заказа реальным UI (с CSRF-токеном формы).
 * Использует заранее верифицированного пользователя — оформление требует
 * подтверждённый email (CheckoutController::confirm → isEmailVerified).
 * Пользователь: cart-test@example.com / Test12345! (создаётся в dev-БД).
 */
const EMAIL = 'cart-test@example.com';
const PASSWORD = 'Test12345!';
const PRODUCT_UUID = '7729b898-579b-11f1-b207-b6cfea2a0fcf';

test.describe('Checkout Flow', () => {

  test('полный флоу: логин → корзина → оформление с оплатой при получении', async ({ page }) => {
    // ── 1. Логин верифицированным пользователем ──
    await page.goto('/login');
    await page.fill('input[name="_username"]', EMAIL);
    await page.fill('input[name="_password"]', PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
    expect(page.url()).not.toContain('/login');

    // ── 2. Страница товара → добавить в корзину (AJAX) ──
    await page.goto(`/product/${PRODUCT_UUID}`);
    const addRespPromise = page.waitForResponse(r =>
      r.url().includes('/cart/add/') && r.request().method() === 'POST'
    );
    await page.click('#add-to-cart-form button[type="submit"]');
    const addData = await (await addRespPromise).json();
    expect(addData.count).toBeGreaterThan(0);

    // ── 3. Корзина ──
    await page.goto('/cart');
    await expect(page.locator('text=В корзине пусто')).toHaveCount(0);

    // ── 4. Оформление ──
    await page.click('a:has-text("Оформить заказ")');
    await page.waitForURL(/\/checkout/, { timeout: 15000 });

    // ── 5. Адрес доставки (новый адрес) ──
    const newAddr = page.locator('input[name="address_id"]#addr_new');
    if (await newAddr.count()) {
      await newAddr.check();
    }
    await page.fill('input[name="full_name"]', 'Иван Петров');
    await page.fill('input[name="phone"]', '+7 999 888 77 66');
    await page.fill('input[name="city"]', 'Москва');
    await page.fill('input[name="street"]', 'ул. Тверская');
    await page.fill('input[name="building"]', '10');
    await page.fill('input[name="apartment"]', '15');
    await page.fill('input[name="zip"]', '123456');

    // ── 6. Оплата при получении (без редиректа в ЮKassa) ──
    await page.check('input[name="payment_method"][value="upon_receipt"]');

    // ── 7. Подтверждение реальной формой (несёт CSRF-токен) ──
    await Promise.all([
      page.waitForURL(/\/checkout\/success/, { timeout: 20000 }),
      page.click('button[type="submit"]:has-text("Подтвердить заказ")'),
    ]);

    // ── 8. Успех: показан номер заказа (8 hex-символов, без префикса) ──
    await expect(page.locator('h1')).toContainText('Заказ оформлен');
    await expect(page.locator('body')).toContainText(/[0-9A-F]{8}/);

    // ── 9. Корзина очищена ──
    await page.goto('/cart');
    await expect(page.locator('text=В корзине пусто')).toHaveCount(1);
  });
});
