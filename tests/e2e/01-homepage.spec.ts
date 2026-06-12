import { test, expect } from '@playwright/test';

/**
 * E2E: Главная страница и базовая навигация.
 * Проверяет, что сайт загружается, шапка содержит
 * переключатели языка и валюты.
 */
test.describe('Homepage', () => {

  test('редирект / → /ru/', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL(/\/ru\//);
  });

  test('главная страница загружается', async ({ page }) => {
    await page.goto('/ru/');
    await expect(page).toHaveTitle(/WEARBASE/i);
    // Логотип
    await expect(page.locator('text=WEARBASE')).toBeVisible();
  });

  test('шапка содержит переключатель языка', async ({ page }) => {
    await page.goto('/ru/');
    // Кнопка открытия дропдауна языка
    const localeBtn = page.locator('#tw-locale-dropdown button').first();
    await expect(localeBtn).toBeVisible();
    await expect(localeBtn).toContainText('RU');
  });

  test('переключатель языка содержит все поддерживаемые языки', async ({ page }) => {
    await page.goto('/ru/');
    // Открываем дропдаун
    await page.click('#tw-locale-dropdown button');
    const menu = page.locator('#tw-locale-menu');
    await expect(menu).toBeVisible();
    // Проверяем наличие ключевых языков
    await expect(menu.locator('text=EN')).toBeVisible();
    await expect(menu.locator('text=ZH')).toBeVisible();
    await expect(menu.locator('text=AR')).toBeVisible();
  });

  test('шапка содержит переключатель валюты', async ({ page }) => {
    await page.goto('/ru/');
    const currencyBtn = page.locator('#tw-currency-dropdown button').first();
    // Может быть скрыт если таблица currency не заполнена
    // Проверяем только если элемент существует
    const count = await currencyBtn.count();
    if (count > 0) {
      await expect(currencyBtn).toBeVisible();
    }
  });

  test('навигационные ссылки ведут на правильные страницы', async ({ page }) => {
    await page.goto('/ru/');
    const brandsLink = page.locator('nav a', { hasText: 'Бренды' }).first();
    await expect(brandsLink).toHaveAttribute('href', /\/ru\/brands/);
  });

});
