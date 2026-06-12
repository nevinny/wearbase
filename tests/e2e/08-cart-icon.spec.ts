import { test, expect } from '@playwright/test';

const PRODUCT_UUID = '7729b898-579b-11f1-b207-b6cfea2a0fcf';

test.describe('Cart Icon Visibility', () => {

  test('иконка корзины видна на странице товара', async ({ page }) => {
    await page.goto(`/product/${PRODUCT_UUID}`);

    // Cart icon link should be present
    const icon = page.locator('#cart-icon-link');
    await expect(icon).toBeVisible();

    // Badge should exist
    const badge = page.locator('#header-cart-badge');
    await expect(badge).toBeVisible();
  });

  test('иконка корзины видна на главной', async ({ page }) => {
    await page.goto('/ru/');
    const icon = page.locator('#cart-icon-link');
    await expect(icon).toBeVisible();
  });

  test('add-to-cart обновляет счётчик корзины', async ({ page }) => {
    // Login first
    const email = `e2e-carticon-${Date.now()}@wearbase.ru`;
    const pass = 'e2e-carticon-pass';

    await page.goto('/register');
    await page.fill('input[name="registration_form[firstName]"]', 'E2E');
    await page.fill('input[name="registration_form[email]"]', email);
    await page.fill('input[name="registration_form[plainPassword][first]"]', pass);
    await page.fill('input[name="registration_form[plainPassword][second]"]', pass);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/account/, { timeout: 15000 });

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
