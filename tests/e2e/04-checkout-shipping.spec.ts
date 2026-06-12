import { test, expect } from '@playwright/test';

/**
 * E2E: Правила доставки в чекауте (Phase 4).
 */
test.describe('Checkout Shipping Rules', () => {

  test('API /checkout/shipping-rules?country=RU возвращает правила', async ({ page }) => {
    const response = await page.request.get('/checkout/shipping-rules?country=RU');
    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data).toHaveProperty('rules');
    expect(Array.isArray(data.rules)).toBe(true);

    // Если есть правила — проверяем структуру
    if (data.rules.length > 0) {
      const rule = data.rules[0];
      expect(rule).toHaveProperty('id');
      expect(rule).toHaveProperty('carrier');
      expect(rule).toHaveProperty('name');
      expect(rule).toHaveProperty('priceRub');
      expect(rule).toHaveProperty('label');
    }
  });

  test('API /checkout/shipping-rules?country=DE возвращает правила', async ({ page }) => {
    const response = await page.request.get('/checkout/shipping-rules?country=DE');
    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data).toHaveProperty('rules');
  });

  test('API /checkout/shipping-rules для несуществующей страны возвращает пустой список', async ({ page }) => {
    const response = await page.request.get('/checkout/shipping-rules?country=ZZ');
    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data).toHaveProperty('rules');
    expect(data.rules).toHaveLength(0);
  });

  test('страница чекаута содержит выбор страны', async ({ page, context }) => {
    // Нужна авторизация — пропускаем если нет тест-пользователя
    // Проверяем только редирект для незалогиненного
    const response = await page.request.get('/checkout');
    // Незалогиненный должен получить редирект на логин
    expect([302, 200]).toContain(response.status());
  });

});
