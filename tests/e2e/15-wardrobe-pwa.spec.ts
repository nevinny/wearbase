import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

type Fixture = {
  password: string;
  intro: { email: string };
};

const fixture = (): Fixture => JSON.parse(readFileSync(
  path.resolve(__dirname, '../../var/e2e-wardrobe-onboarding-fixture.json'),
  'utf8',
));

async function login(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(fixture().intro.email);
  await page.getByLabel('Пароль').fill(fixture().password);
  await page.getByRole('button', { name: /Войти/ }).click();
  await expect(page).not.toHaveURL(/\/login/);
}

test('manifest, service-worker scope and camera input are install-ready', async ({ page, request }) => {
  const manifestResponse = await request.get('/manifest.webmanifest');
  expect(manifestResponse.status()).toBe(200);
  expect(manifestResponse.headers()['content-type']).toBe('application/manifest+json; charset=utf-8');
  expect(await manifestResponse.json()).toMatchObject({
    start_url: '/account/wardrobe-app',
    scope: '/account/',
    display: 'standalone',
  });

  await login(page);
  await page.goto('/account/wardrobe-app');
  const scope = await page.evaluate(async () => (await navigator.serviceWorker.ready).scope);
  expect(scope).toBe('http://127.0.0.1:8012/account/');

  await page.goto('/account/wardrobe/wear');
  await expect(page.locator('#wear-photo')).toHaveAttribute('accept', 'image/jpeg,image/png,image/webp');
  await expect(page.locator('#wear-photo')).toHaveAttribute('capture', 'environment');

  const cachedPaths = await page.evaluate(async () => {
    const entries = [] as string[];
    for (const name of await caches.keys()) {
      for (const response of await (await caches.open(name)).keys()) {
        entries.push(new URL(response.url).pathname);
      }
    }
    return entries;
  });
  expect(cachedPaths.some((url) => url.startsWith('/account/'))).toBe(false);
  expect(cachedPaths.some((url) => url.startsWith('/api/'))).toBe(false);
});

test('offline navigation shows a data-free shell instead of cached family content', async ({ page, context }) => {
  await login(page);
  await page.goto('/account/wardrobe-app');
  await page.evaluate(async () => navigator.serviceWorker.ready);
  await page.reload();

  await context.setOffline(true);
  const response = await page.goto('/account/wardrobe-app');
  expect(response?.status()).toBe(503);
  await expect(page.getByRole('heading', { name: 'Нет подключения' })).toBeVisible();
  await expect(page.getByText('Состав семьи')).toHaveCount(0);
  await context.setOffline(false);
});
