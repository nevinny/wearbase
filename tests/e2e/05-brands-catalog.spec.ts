import { test, expect } from '@playwright/test';

/**
 * E2E: Каталог брендов (Phase 5 — мультиязычные витрины).
 */
test.describe('Brands Catalog', () => {

  test('/ru/brands загружается', async ({ page }) => {
    await page.goto('/ru/brands');
    await expect(page).toHaveURL(/\/ru\/brands/);
    await expect(page).toHaveTitle(/бренд|brand/i);
  });

  test('/en/brands загружается на английском пути', async ({ page }) => {
    const response = await page.request.get('/en/brands');
    expect(response.status()).toBe(200);
  });

  test('страница бренда с поддерживаемой локалью', async ({ page }) => {
    // Сначала получим список брендов через /ru/brands
    await page.goto('/ru/brands');

    // Ищем первую ссылку на бренд
    const brandLinks = page.locator('a[href*="/brands/"]');
    const count = await brandLinks.count();

    if (count > 0) {
      const href = await brandLinks.first().getAttribute('href');
      if (href) {
        // Проверяем страницу бренда
        await page.goto(href);
        await expect(page).toHaveTitle(/.+/); // не пустой заголовок
      }
    }
  });

  test('переключение языка сохраняет категорию навигации', async ({ page }) => {
    // Устанавливаем cookie locale=en
    await page.context().addCookies([{
      name: 'locale',
      value: 'en',
      domain: 'wearbase.dev.local',
      path: '/',
    }]);
    await page.goto('/en/brands');

    // Навигация должна быть с locale=en
    const homeLink = page.locator('nav a').first();
    const href = await homeLink.getAttribute('href');
    expect(href).toContain('/en/');
  });

  test('API /currency/api/exchange-rates доступен', async ({ page }) => {
    const response = await page.request.get('/currency/api/exchange-rates');
    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data).toHaveProperty('base');
    expect(data).toHaveProperty('rates');
  });

});
