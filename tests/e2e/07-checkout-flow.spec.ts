import { test, expect } from '@playwright/test';

test.setTimeout(120000);

const TEST_EMAIL = `e2e-checkout-${Date.now()}@wearbase.ru`;
const TEST_PASSWORD = 'e2e-checkout-pass';
const PRODUCT_UUID = '7729b898-579b-11f1-b207-b6cfea2a0fcf';

test.describe('Checkout Flow', () => {

  test('полный флоу: регистрация → добавление в корзину → оформление заказа', async ({ page }) => {
    // ── 1. Регистрация ──
    await page.goto('/register');
    await expect(page.locator('h1')).toContainText('Создать аккаунт');

    await page.fill('input[name="registration_form[firstName]"]', 'E2E');
    await page.fill('input[name="registration_form[email]"]', TEST_EMAIL);
    await page.fill('input[name="registration_form[plainPassword][first]"]', TEST_PASSWORD);
    await page.fill('input[name="registration_form[plainPassword][second]"]', TEST_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/account/, { timeout: 15000 });

    // ── 2. Страница товара → добавить в корзину (AJAX) ──
    await page.goto(`/product/${PRODUCT_UUID}`);
    await expect(page.locator('h1')).toContainText('Классическая футболка');

    const addRespPromise = page.waitForResponse(r =>
      r.url().includes('/cart/add/') && r.request().method() === 'POST'
    );
    await page.click('button:has-text("В корзину")');
    const addResp = await addRespPromise;
    const addData = await addResp.json();
    expect(addData.count).toBeGreaterThan(0);

    // ── 3. Корзина ──
    await page.goto('/cart', { timeout: 30000 });
    await expect(page.locator('text=Классическая футболка')).toBeVisible();

    // ── 4. Оформление заказа ──
    await page.click('a:has-text("Оформить заказ")');
    await page.waitForURL('/checkout', { timeout: 15000 });

    // ── 5. Заполняем адрес доставки ──
    await page.fill('input[name="full_name"]', 'Иван Петров');
    await page.fill('input[name="phone"]', '+7 999 888 77 66');
    await page.fill('input[name="city"]', 'Москва');
    await page.fill('input[name="street"]', 'ул. Тверская');
    await page.fill('input[name="building"]', '10');
    await page.fill('input[name="apartment"]', '15');
    await page.fill('input[name="zip"]', '123456');

    // ── 6. Оплата при получении ──
    await page.click('input[name="payment_method"][value="upon_receipt"]');

    // ── 7. Подтверждение заказа ──
      const resp = await page.request.post('/checkout/confirm', {
        form: {
          full_name: 'Иван Петров',
          phone: '+7 999 888 77 66',
          email: TEST_EMAIL,
          city: 'Москва',
          street: 'ул. Тверская',
          building: '10',
          apartment: '15',
          zip: '123456',
          country: 'RU',
          delivery_method: 'cdek',
          payment_method: 'upon_receipt',
          note: '',
        },
        maxRedirects: 0,
      });

    expect(resp.status()).toBe(302);
    const location = resp.headers()['location'] || '';
    expect(location).toContain('/checkout/success');

    // ── 8. Успех ──
    await page.goto(location);
    await expect(page.locator('h1')).toContainText('Заказ оформлен');
    await expect(page.locator('text=WB-')).toBeVisible();
  });
});
