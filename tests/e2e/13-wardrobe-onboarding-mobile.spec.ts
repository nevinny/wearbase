import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

type Account = { email: string; id: number };
type Fixture = {
  password: string;
  intro: Account;
  skip: Account;
  parent: Account;
  child: Account;
  foreign: Account;
  batchParent: Account;
  batchChild: Account;
  batch: { id: string; draftId: number };
};

const fixture = (): Fixture => JSON.parse(readFileSync(
  path.resolve(__dirname, '../../var/e2e-wardrobe-onboarding-fixture.json'),
  'utf8',
));

async function login(page: Page, account: Account): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(account.email);
  await page.getByLabel('Пароль').fill(fixture().password);
  await page.getByRole('button', { name: /Войти/ }).click();
  await expect(page).not.toHaveURL(/\/login/);
}

test('mobile intro offers batch, single item and deferred setup', async ({ page }) => {
  await login(page, fixture().intro);
  await page.goto('/account/wardrobe-app');

  await expect(page.getByRole('heading', { name: 'Добавьте первые 5 вещей' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Загрузить пачкой' })).toHaveAttribute('href', '/account/wardrobe#wardrobe-ingest-panel');
  await expect(page.getByRole('link', { name: 'Добавить одну' })).toHaveAttribute('href', '/account/wardrobe/new');
  await expect(page.getByRole('button', { name: 'Настроить позже' })).toBeVisible();
});

test('skip survives reload and resume restores intro', async ({ page }) => {
  await login(page, fixture().skip);
  await page.goto('/account/wardrobe-app');
  await page.getByRole('button', { name: 'Настроить позже' }).click();

  await expect(page.getByRole('heading', { name: 'Настроить гардероб' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('button', { name: 'Продолжить настройку' })).toBeVisible();
  await page.getByRole('button', { name: 'Продолжить настройку' }).click();
  await expect(page.getByRole('heading', { name: 'Добавьте первые 5 вещей' })).toBeVisible();
});

test('parent selects managed child and links preserve member context', async ({ page }) => {
  const data = fixture();
  await login(page, data.parent);
  await page.goto('/account/wardrobe-app');
  await page.getByRole('link', { name: /Лиза/ }).click();

  await expect(page).toHaveURL(new RegExp(`member=${data.child.id}`));
  await expect(page.getByRole('heading', { name: 'Добавьте первые 5 вещей' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Загрузить пачкой' }))
    .toHaveAttribute('href', `/account/wardrobe?member=${data.child.id}#wardrobe-ingest-panel`);
  await expect(page.getByRole('link', { name: 'Добавить одну' }))
    .toHaveAttribute('href', `/account/wardrobe/new?member=${data.child.id}`);
});

test('child cannot forge parent or foreign onboarding scope', async ({ page }) => {
  const data = fixture();
  await login(page, data.child);

  const parentResponse = await page.goto(`/account/wardrobe-app?member=${data.parent.id}`);
  expect(parentResponse?.status()).toBe(403);
  await expect(page.getByText('Добавьте первые 5 вещей')).toHaveCount(0);

  const foreignResponse = await page.goto(`/account/wardrobe-app?member=${data.foreign.id}`);
  expect(foreignResponse?.status()).toBe(403);
  await expect(page.getByText('Добавьте первые 5 вещей')).toHaveCount(0);
});

test('seeded child batch resumes review and repeated accept is idempotent', async ({ page }) => {
  const data = fixture();
  await login(page, data.batchParent);
  await page.goto(`/account/wardrobe-app?member=${data.batchChild.id}`);

  await expect(page.getByRole('heading', { name: 'Проверьте вещи' })).toBeVisible();
  await page.getByRole('link', { name: 'Открыть загрузку' }).click();
  await expect(page).toHaveURL(new RegExp(`/account/wardrobe/ingest/${data.batch.id}\\?member=${data.batchChild.id}`));

  const card = page.locator(`[data-draft-id="${data.batch.draftId}"]`);
  await expect(card.locator('input[data-field="name"]')).toHaveValue('Белая рубашка');
  const result = await card.evaluate(async (element) => {
    const root = document.querySelector<HTMLElement>('#wardrobe-ingest-root')!;
    const url = (element as HTMLElement).dataset.acceptUrl!;
    const options = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf! },
      body: JSON.stringify({}),
    };
    const first = await fetch(url, options).then(response => response.json());
    const second = await fetch(url, options).then(response => response.json());
    return { first, second };
  });

  expect(result.first).toMatchObject({ ok: true, idempotent: false });
  expect(result.second).toMatchObject({ ok: true, idempotent: true, itemId: result.first.itemId });
  await page.goto(`/account/wardrobe?member=${data.batchChild.id}`);
  await expect(page.getByText('Белая рубашка', { exact: true })).toHaveCount(1);
});
