import { test, expect } from '@playwright/test';

const TEST_EMAIL = `e2e-claim-${Date.now()}@wearbase.ru`;
const TEST_PASSWORD = 'e2e-claim-pass';
const BRAND_SLUG = 'lovemonelli';

test.describe('Brand Claim Flow', () => {

  test('полный флоу: регистрация → страница бренда → заявка на владение', async ({ page }) => {
    // ── 1. Аноним на странице бренда ──
    await page.goto(`/ru/brands/${BRAND_SLUG}`);
    await expect(page).toHaveURL(/\/ru\/brands\/lovemonelli/);

    await expect(page.locator('text=Вы владелец этого бренда?')).toBeVisible();
    const loginLink = page.locator('a').filter({ hasText: 'Войти и подтвердить владение' });
    await expect(loginLink).toBeVisible();
    await expect(loginLink).toHaveAttribute('href', '/login');

    // ── 2. Регистрация ──
    await page.goto('/register');
    await expect(page.locator('h1')).toContainText('Создать аккаунт');

    await page.fill('input[name="registration_form[firstName]"]', 'E2E');
    await page.fill('input[name="registration_form[email]"]', TEST_EMAIL);
    await page.fill('input[name="registration_form[plainPassword][first]"]', TEST_PASSWORD);
    await page.fill('input[name="registration_form[plainPassword][second]"]', TEST_PASSWORD);
    await page.click('button[type="submit"]');

    // После регистрации пользователь автоматически залогинен
    await page.waitForURL(/\/account/, { timeout: 15000 });

    // ── 3. Страница бренда — кнопка заявки ──
    await page.goto(`/ru/brands/${BRAND_SLUG}`);
    await expect(page).toHaveURL(/\/ru\/brands\/lovemonelli/);

    await expect(page.locator('text=Вы владелец этого бренда?')).toBeVisible();
    const claimLink = page.locator('a').filter({ hasText: 'Подтвердить владение брендом' });
    await expect(claimLink).toBeVisible();

    // ── 4. Форма заявки ──
    await claimLink.click();
    await page.waitForURL(/\/brand-claim\/\d+/);

    await expect(page.locator('h1')).toContainText('Я владелец бренда');
    await expect(page.locator('textarea[name="comment"]')).toBeVisible();

    await page.fill('textarea[name="comment"]', 'Я владелец этого бренда. Сайт: lovemonelli.ru');

    // Submit and wait for response
    const responsePromise = page.waitForResponse(resp =>
        resp.url().includes('/brand-claim/') && resp.request().method() === 'POST'
    );
    await page.click('button[type="submit"]');
    const response = await responsePromise;
    expect(response.status()).toBe(200);

    const html = await response.text();
    expect(html).toContain('Заявка отправлена');
    expect(html).toContain('Love Monelli');
  });
});
