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

const photo = {name: 'offline.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')};

test('offline upload resumes on reconnect exactly once', async ({ page, context }) => {
  await login(page);
  await page.goto('/account/wardrobe');
  await page.evaluate(() => (window as any).WardrobeIngestQueue.clear());
  let uploads = 0;
  await page.route('**/account/wardrobe/ingest/upload**', async route => {
    uploads++;
    await route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({ok: true})});
  });
  await page.getByRole('button', {name: 'Загрузить пачкой'}).click();
  await page.locator('#ingest-panel-photo-consent').check();
  await context.setOffline(true);
  await page.locator('#ingest-panel-file-input').setInputFiles(photo);
  await expect(page.getByText(/фото сохранены только на этом устройстве/)).toBeVisible();

  await context.setOffline(false);
  await expect.poll(() => uploads).toBe(1);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await page.waitForTimeout(200);
  expect(uploads).toBe(1);
  expect(await page.evaluate(() => (window as any).WardrobeIngestQueue.list())).toHaveLength(0);
});

test('auth expiry keeps queued blob and asks for login', async ({ page }) => {
  await login(page);
  await page.goto('/account/wardrobe');
  await page.evaluate(() => (window as any).WardrobeIngestQueue.clear());
  await page.route('**/account/wardrobe/ingest/upload**', route => route.fulfill({status: 401, body: 'login required'}));
  await page.getByRole('button', {name: 'Загрузить пачкой'}).click();
  await page.locator('#ingest-panel-photo-consent').check();
  await page.locator('#ingest-panel-file-input').setInputFiles(photo);

  await expect(page.getByText(/Сессия закончилась/)).toBeVisible();
  await expect(page.getByRole('link', {name: 'Войти'})).toHaveAttribute('href', '/login');
  expect(await page.evaluate(() => (window as any).WardrobeIngestQueue.list())).toHaveLength(1);
});

test('explicit logout clears pending uploads', async ({ page, context }) => {
  await login(page);
  await page.goto('/account/wardrobe');
  await page.evaluate(() => (window as any).WardrobeIngestQueue.clear());
  await page.route('**/account/wardrobe/ingest/upload**', route => route.abort('internetdisconnected'));
  await context.setOffline(true);
  await page.getByRole('button', {name: 'Загрузить пачкой'}).click();
  await page.locator('#ingest-panel-photo-consent').check();
  await page.locator('#ingest-panel-file-input').setInputFiles(photo);
  expect(await page.evaluate(() => (window as any).WardrobeIngestQueue.list())).toHaveLength(1);
  await context.setOffline(false);
  await page.route('**/logout', route => route.fulfill({status: 200, contentType: 'text/html', body: '<h1>logged out</h1>'}));
  await page.goto('/account');

  await page.locator('a[href$="/logout"]').first().click();
  await expect(page.getByRole('heading', {name: 'logged out'})).toBeVisible();
  await page.goto('/account/wardrobe');
  expect(await page.evaluate(() => (window as any).WardrobeIngestQueue.list())).toHaveLength(0);
});
