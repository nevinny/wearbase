import { expect, test, type Browser, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

type Actor = { email: string; id: number };
type Fixture = {
  password: string;
  parent: Actor;
  child: Actor;
  wardrobeChild: Actor;
  invitedChild: Actor;
  spouse: Actor;
};

const fixture = (): Fixture => JSON.parse(readFileSync(
  path.resolve(__dirname, '../../var/e2e-family-fixture.json'),
  'utf8',
));

async function login(page: Page, actor: Actor): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(actor.email);
  await page.getByLabel('Пароль').fill(fixture().password);
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/account/);
}

async function loggedPage(browser: Browser, actor: Actor): Promise<Page> {
  const context = await browser.newContext({ serviceWorkers: 'block' });
  await context.addInitScript(() => localStorage.setItem('cookieConsent', '1'));
  const page = await context.newPage();
  await login(page, actor);
  return page;
}

async function createInvite(page: Page, role: 'child' | 'parent'): Promise<string> {
  await page.goto('/account/family');
  const existingUrls = new Set(await page.locator('input[id^="invite-url-"]').evaluateAll(
    (inputs) => inputs.map((input) => (input as HTMLInputElement).value),
  ));
  const form = page.locator('form').filter({ has: page.getByRole('button', { name: 'Пригласить в семью' }) });
  await form.locator('select[name="role"]').selectOption(role);
  await form.getByRole('button', { name: 'Пригласить в семью' }).click();
  await expect(page.getByText('Приглашение создано')).toBeVisible();

  const urls = await page.locator('input[id^="invite-url-"]').evaluateAll(
    (inputs) => inputs.map((input) => (input as HTMLInputElement).value),
  );
  const url = urls.find((candidate) => !existingUrls.has(candidate));
  if (url === undefined) {
    throw new Error(`Новая invite-ссылка для роли ${role} не найдена`);
  }
  return new URL(url).pathname;
}

test.describe.serial('Семейный гардероб: вещи, приглашения и уведомления', () => {
  let itemPath: string;
  let childInvitePath: string;

  test('1. ребёнок добавляет новую вещь в личный гардероб', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().wardrobeChild);
    await page.goto('/account/wardrobe-app');
    await page.getByRole('link', { name: /Добавить вещь/ }).click();
    await expect(page).toHaveURL('/account/wardrobe/new');

    await page.locator('#wardrobe_item_form_name').fill('Синяя джинсовая куртка');
    await page.locator('#wardrobe_item_form_size').fill('158');
    await page.locator('#wardrobe_item_form_price').fill('3499');
    await page.locator('#wardrobe_item_form_colorName').fill('Синий');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL('/account/wardrobe');
    await expect(page.getByText('Вещь добавлена')).toBeVisible();
    await page.getByText('Синяя джинсовая куртка').click();
    await expect(page).toHaveURL(/\/account\/wardrobe\/\d+(\?member=\d+)?$/);
    itemPath = new URL(page.url()).pathname;
    await expect(page.getByText('Размер 158')).toBeVisible();
  });

  test('2. ребёнок редактирует созданную вещь', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().wardrobeChild);
    await page.goto(itemPath);
    await page.getByRole('link', { name: 'Редактировать' }).click();

    await page.locator('#wardrobe_item_form_name').fill('Джинсовая куртка oversize');
    await page.locator('#wardrobe_item_form_size').fill('164');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(itemPath);
    await expect(page.getByText('Изменения сохранены')).toBeVisible();
    await expect(page.getByRole('heading', { name: /Джинсовая куртка oversize/ })).toBeVisible();
    await expect(page.getByText('Размер 164')).toBeVisible();
  });

  test('3. удаление вещи требует подтверждения и убирает её из гардероба', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().wardrobeChild);
    await page.goto(itemPath);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Удалить' }).click();

    await expect(page).toHaveURL('/account/wardrobe');
    await expect(page.getByText('Вещь удалена')).toBeVisible();
    await expect(page.getByText('Джинсовая куртка oversize')).toHaveCount(0);
    expect((await page.goto(itemPath))?.status()).toBe(404);
  });

  test('4. родитель приглашает ребёнка, ребёнок принимает приглашение', async ({ browser }) => {
    const parentPage = await loggedPage(browser, fixture().parent);
    childInvitePath = await createInvite(parentPage, 'child');

    const childPage = await loggedPage(browser, fixture().invitedChild);
    await childPage.goto(childInvitePath);
    await expect(childPage.getByText('Роль в семье:')).toContainText('Ребёнок');
    await childPage.getByRole('button', { name: 'Принять приглашение' }).click();
    await expect(childPage).toHaveURL('/account/family/profile');

    await parentPage.goto('/account/family');
    await expect(parentPage.getByText('Маша').first()).toBeVisible();
  });

  test('5. использованное приглашение нельзя принять повторно', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().parent);
    await page.goto(childInvitePath);
    await expect(page.getByRole('heading', { name: 'Приглашение уже использовано' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Принять приглашение' })).toHaveCount(0);
  });

  test('6. родитель приглашает супруга как второго родителя', async ({ browser }) => {
    const parentPage = await loggedPage(browser, fixture().parent);
    const spouseInvitePath = await createInvite(parentPage, 'parent');

    const spousePage = await loggedPage(browser, fixture().spouse);
    await spousePage.goto(spouseInvitePath);
    await expect(spousePage.getByText('Роль в семье:')).toContainText('Родитель');
    await spousePage.getByRole('button', { name: 'Принять приглашение' }).click();
    await expect(spousePage).toHaveURL('/account/family');
    await expect(spousePage.getByText('Елена').first()).toBeVisible();
    await expect(spousePage.getByText('Алексей').first()).toBeVisible();

    await parentPage.goto('/account/family');
    await expect(parentPage.getByText('Алексей').first()).toBeVisible();
  });

  test('7. пользователь отмечает одно уведомление и затем все уведомления прочитанными', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().parent);
    await page.goto('/account/notifications');

    const seeded = page.locator('div.border-indigo-500').filter({
      has: page.getByText('E2E: проверьте семейный гардероб', { exact: true }),
    });
    await expect(seeded).toHaveCount(1);
    await seeded.getByTitle('Отметить прочитанным').click();
    await expect(page).toHaveURL('/account/notifications');

    const markAll = page.getByRole('button', { name: 'Прочитать все' });
    if (await markAll.count()) {
      await markAll.click();
      await expect(page).toHaveURL('/account/notifications');
    }
    await expect(page.locator('div.border-indigo-500')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Прочитать все' })).toHaveCount(0);
  });
});
