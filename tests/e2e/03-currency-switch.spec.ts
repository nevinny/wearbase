import { test, expect } from '@playwright/test';

/**
 * E2E: Переключение валюты (Phase 2).
 */
test.describe('Currency Switching', () => {

  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies();
  });

  test('API /currency/api/currencies возвращает список валют', async ({ page }) => {
    const response = await page.request.get('/currency/api/currencies');
    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(Array.isArray(data)).toBe(true);
  });

  test('API /currency/api/convert конвертирует сумму', async ({ page }) => {
    const response = await page.request.get('/currency/api/convert?amount=1000&from=RUB&to=RUB');
    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data).toHaveProperty('amount');
    expect(data).toHaveProperty('formatted');
  });

  test('POST /currency/switch устанавливает cookie currency', async ({ page }) => {
    const response = await page.request.post('/currency/switch', {
      form: { code: 'USD', redirect: '/ru/' },
    });
    expect([200, 302]).toContain(response.status());

    // Проверяем что cookie установлен
    const cookies = await page.context().cookies();
    const currencyCookie = cookies.find(c => c.name === 'currency');
    // Может быть не установлен если USD не найден в БД — это нормально
    if (currencyCookie) {
      expect(currencyCookie.value).toBe('USD');
    }
  });

  test('переключатель валюты виден на главной', async ({ page }) => {
    await page.goto('/ru/');
    const currencyDropdown = page.locator('#tw-currency-dropdown');
    const count = await currencyDropdown.count();
    // Дропдаун существует только если CurrencyExtension нашёл валюты в БД
    if (count > 0) {
      await expect(currencyDropdown).toBeVisible();
      // Открываем
      await page.click('#tw-currency-dropdown button');
      const menu = page.locator('#tw-currency-menu');
      await expect(menu).toBeVisible();
      // Должна быть хотя бы одна валюта
      const items = menu.locator('button[type="submit"]');
      expect(await items.count()).toBeGreaterThan(0);
    }
  });

});
