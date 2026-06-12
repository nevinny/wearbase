import { test, expect } from '@playwright/test';

test.setTimeout(120000);

const PRODUCT_UUID = '7729b898-579b-11f1-b207-b6cfea2a0fcf';

test.describe('Shipping Method Switch', () => {

  async function setupUser(page: any, email: string) {
    await page.goto('/register');
    await page.fill('input[name="registration_form[firstName]"]', 'E2E');
    await page.fill('input[name="registration_form[email]"]', email);
    await page.fill('input[name="registration_form[plainPassword][first]"]', 'test-pass');
    await page.fill('input[name="registration_form[plainPassword][second]"]', 'test-pass');
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/account/, { timeout: 15000 });
  }

  async function addToCart(page: any) {
    await page.goto(`/product/${PRODUCT_UUID}`);
    const resp = page.waitForResponse(r => r.url().includes('/cart/add/') && r.request().method() === 'POST');
    await page.click('button:has-text("В корзину")');
    await resp;
  }

  async function fillAddress(page: any) {
    const newAddrSection = page.locator('#new-address-fields');
    const cls = await newAddrSection.getAttribute('class');
    if (cls?.includes('d-none')) {
      await page.click('input[name="address_id"][value=""]');
    }
    await page.fill('input[name="full_name"]', 'Иван Петров');
    await page.fill('input[name="phone"]', '+7 999 888 77 66');
    await page.fill('input[name="city"]', 'Москва');
    await page.fill('input[name="street"]', 'ул. Тверская');
    await page.fill('input[name="building"]', '10');
    await page.fill('input[name="apartment"]', '15');
    await page.fill('input[name="zip"]', '123456');
  }

  test('переключение доставки меняет цену доставки в правой колонке', async ({ page }) => {
    const email = `e2e-ship-price-${Date.now()}@wearbase.ru`;
    await setupUser(page, email);
    await addToCart(page);
    await page.goto('/checkout');
    await page.waitForURL('/checkout');
    await fillAddress(page);

    const shippingLabel = page.locator('#shipping-price');
    const radios = page.locator('.shipping-radio');
    const count = await radios.count();

    const prices: string[] = [];
    for (let i = 0; i < count; i++) {
      await radios.nth(i).click();
      await page.waitForTimeout(200);
      const text = (await shippingLabel.textContent()) || '';
      prices.push(text);
      console.log(`Option ${i}: displayed="${text}"`);
    }

    // At least some options should differ in price
    const unique = new Set(prices);
    expect(unique.size).toBeGreaterThan(1);
  });

  test('переключение доставки обновляет скрытое поле shipping_rule_id', async ({ page }) => {
    const email = `e2e-ship-ruleid-${Date.now()}@wearbase.ru`;
    await setupUser(page, email);
    await addToCart(page);
    await page.goto('/checkout');
    await page.waitForURL('/checkout');
    await fillAddress(page);

    const radios = page.locator('.shipping-radio');
    const count = await radios.count();

    for (let i = 0; i < count; i++) {
      const expectedRuleId = await radios.nth(i).getAttribute('data-rule-id');
      await radios.nth(i).click();
      await page.waitForTimeout(100);
      const ruleId = await page.locator('#shipping-rule-id').getAttribute('value');
      expect(ruleId).toBe(expectedRuleId);
    }
  });
});
