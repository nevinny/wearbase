import { test, expect } from '@playwright/test';

/**
 * E2E: Переключение языка (Phase 3).
 * Проверяет, что после POST /locale/switch устанавливается
 * cookie `locale` и пользователь видит интерфейс на нужном языке.
 */
test.describe('Locale Switching', () => {

  test.beforeEach(async ({ page }) => {
    // Стартуем с чистым состоянием (нет cookie locale)
    await page.context().clearCookies();
    await page.goto('/ru/');
  });

  test('переключение на английский язык', async ({ page }) => {
    // Открываем дропдаун языка
    await page.click('#tw-locale-dropdown button');
    await page.waitForSelector('#tw-locale-menu:not(.hidden)');

    // Кликаем форму с locale=en
    await page.click('#tw-locale-menu form input[value="en"] ~ button, #tw-locale-menu button:has-text("EN")');

    // Ждём редиректа
    await page.waitForLoadState('networkidle');

    // Проверяем cookie
    const cookies = await page.context().cookies();
    const localeCookie = cookies.find(c => c.name === 'locale');
    expect(localeCookie?.value).toBe('en');
  });

  test('кнопка языка показывает текущий язык', async ({ page }) => {
    // Устанавливаем cookie напрямую
    await page.context().addCookies([{
      name: 'locale',
      value: 'en',
      domain: 'wearbase.dev.local',
      path: '/',
    }]);
    await page.goto('/en/');

    const localeBtn = page.locator('#tw-locale-dropdown button').first();
    await expect(localeBtn).toContainText('EN');
  });

  test('LocaleListener читает cookie и устанавливает локаль', async ({ page }) => {
    // Устанавливаем cookie locale=de
    await page.context().addCookies([{
      name: 'locale',
      value: 'de',
      domain: 'wearbase.dev.local',
      path: '/',
    }]);
    await page.goto('/ru/');

    // Кнопка должна показывать DE (из cookie)
    const localeBtn = page.locator('#tw-locale-dropdown button').first();
    await expect(localeBtn).toContainText('DE');
  });

  test('POST /locale/switch устанавливает cookie и редиректит на /tr/', async ({ page }) => {
    const response = await page.request.post('/locale/switch', {
      form: { locale: 'tr' },
      headers: { 'Referer': 'http://wearbase.dev.local/ru/' },
    });
    // Должен вернуть 302 на /tr/
    expect(response.status()).toBe(302);
    const location = response.headers()['location'];
    expect(location).toContain('/tr/');
  });

  test('переключение со страницы бренда сохраняет путь с новой локалью', async ({ page }) => {
    const response = await page.request.post('/locale/switch', {
      form: { locale: 'en' },
      headers: { 'Referer': 'http://wearbase.dev.local/ru/brands' },
    });
    expect(response.status()).toBe(302);
    const location = response.headers()['location'];
    // Путь должен быть /en/brands, а не /ru/brands
    expect(location).toContain('/en/brands');
    expect(location).not.toContain('/ru/');
  });

  test('hreflang ссылки присутствуют на главной', async ({ page }) => {
    await page.goto('/ru/');
    const ruHreflang = page.locator('link[hreflang="ru"]');
    const enHreflang = page.locator('link[hreflang="en"]');
    await expect(ruHreflang).toHaveCount(1);
    await expect(enHreflang).toHaveCount(1);
  });

});
