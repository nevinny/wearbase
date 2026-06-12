import { test, expect } from '@playwright/test';

test.setTimeout(120000);

/**
 * Регрессия: гостевая корзина пропадала после логина.
 * Причина: корзина была привязана к PHP session ID, который Symfony меняет
 * при аутентификации (session fixation protection). Теперь гостевая корзина
 * живёт по токену в данных сессии и сливается с корзиной пользователя
 * на LoginSuccessEvent (CartMergeOnLoginListener).
 *
 * Требует пользователя cart-test@example.com / Test12345! (email verified).
 */
const PRODUCT_UUID = '7729b898-579b-11f1-b207-b6cfea2a0fcf';
const EMAIL = 'cart-test@example.com';
const PASSWORD = 'Test12345!';

test.describe('Cart merge on login', () => {
  test('гостевая корзина переживает авторизацию', async ({ page }) => {
    // ── 1. Гость кладёт товар в корзину ──
    await page.goto(`/product/${PRODUCT_UUID}`);
    const addResp = page.waitForResponse(r =>
      r.url().includes('/cart/add/') && r.request().method() === 'POST'
    );
    await page.click('#add-to-cart-form button[type="submit"]');
    const added = await (await addResp).json();
    expect(added.count).toBeGreaterThan(0);

    await page.goto('/cart');
    await expect(page.locator('text=В корзине пусто')).toHaveCount(0);
    const guestQty = await page.locator('.qty-val').count();
    expect(guestQty).toBeGreaterThan(0);

    // ── 2. Логин ──
    await page.goto('/login');
    await page.fill('input[name="_username"]', EMAIL);
    await page.fill('input[name="_password"]', PASSWORD);
    await Promise.all([
      page.waitForNavigation(),
      page.click('form button[type="submit"]'),
    ]);
    expect(page.url()).not.toContain('/login');

    // ── 3. Корзина не опустела ──
    await page.goto('/cart');
    await expect(page.locator('text=В корзине пусто')).toHaveCount(0);
    const userQty = await page.locator('.qty-val').count();
    expect(userQty).toBeGreaterThanOrEqual(guestQty);
  });
});
