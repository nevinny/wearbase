import { test, expect } from '@playwright/test';

const PRODUCT_UUID = '7729b898-579b-11f1-b207-b6cfea2a0fcf';

test.describe('Cart Icon Visibility', () => {

  test('иконка корзины видна на странице товара', async ({ page }) => {
    await page.goto(`/product/${PRODUCT_UUID}`);

    // Cart icon link should be present
    const icon = page.locator('#cart-icon-link');
    await expect(icon).toBeVisible();

    // Badge exists in DOM (hidden until cart has items)
    const badge = page.locator('#header-cart-badge');
    await expect(badge).toBeAttached();
  });

  test('иконка корзины видна на главной', async ({ page }) => {
    await page.goto('/ru/');
    const icon = page.locator('#cart-icon-link');
    await expect(icon).toBeVisible();
  });

  test('add-to-cart обновляет счётчик корзины', async ({ page }) => {
    // Логин готовым пользователем: регистрация в e2e невозможна из-за Turnstile-капчи
    await page.goto('/login');
    await page.fill('input[name="_username"]', 'cart-test@example.com');
    await page.fill('input[name="_password"]', 'Test12345!');
    await Promise.all([page.waitForNavigation(), page.click('form button[type="submit"]')]);
    expect(page.url()).not.toContain('/login');

    // Go to product page
    await page.goto(`/product/${PRODUCT_UUID}`);

    // Cart badge should be hidden initially
    const badge = page.locator('#header-cart-badge');
    await expect(badge).not.toBeVisible();

    // Add to cart
    const respPromise = page.waitForResponse(r =>
      r.url().includes('/cart/add/') && r.request().method() === 'POST'
    );
    await page.click('button:has-text("В корзину")');
    await respPromise;

    // Badge should now be visible with count
    await expect(badge).toBeVisible();
    const count = await badge.textContent();
    expect(parseInt(count || '0')).toBeGreaterThan(0);
  });
});
