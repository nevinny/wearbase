import { Page } from '@playwright/test';

/**
 * Вспомогательные функции авторизации для E2E тестов.
 */

export const TEST_USER = {
  email: process.env.TEST_USER_EMAIL ?? 'test@wearbase.ru',
  password: process.env.TEST_USER_PASSWORD ?? 'test123456',
};

export const TEST_BRAND_USER = {
  email: process.env.TEST_BRAND_EMAIL ?? 'brand@wearbase.ru',
  password: process.env.TEST_BRAND_PASSWORD ?? 'brand123456',
};

/**
 * Авторизоваться как обычный пользователь.
 */
export async function loginAsUser(page: Page): Promise<void> {
  await page.goto('/login');
  await page.fill('[name="email"]', TEST_USER.email);
  await page.fill('[name="password"]', TEST_USER.password);
  await page.click('[type="submit"]');
  await page.waitForURL(/account|dashboard/);
}

/**
 * Авторизоваться как представитель бренда.
 */
export async function loginAsBrand(page: Page): Promise<void> {
  await page.goto('/login');
  await page.fill('[name="email"]', TEST_BRAND_USER.email);
  await page.fill('[name="password"]', TEST_BRAND_USER.password);
  await page.click('[type="submit"]');
  await page.waitForURL(/brand_dashboard|account/);
}

/**
 * Выйти из аккаунта.
 */
export async function logout(page: Page): Promise<void> {
  await page.goto('/logout');
}
