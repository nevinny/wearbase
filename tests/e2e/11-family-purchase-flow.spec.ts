import { expect, test, type Browser, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

type Actor = { email: string; id: number };
type Fixture = {
  password: string;
  parent: Actor;
  child: Actor;
  foreign: Actor;
  invitePath: string;
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

async function createRequest(page: Page, url: string, price: string, comment: string): Promise<string> {
  await page.goto('/account/purchases/new');
  await page.getByLabel('Ссылка на товар или подборку').fill(url);
  await page.getByLabel('Ожидаемая цена').fill(price);
  await page.getByLabel('Комментарий').fill(comment);
  await page.getByRole('button', { name: 'Отправить родителю' }).click();
  await expect(page).toHaveURL(/\/account\/purchases\/\d+$/);
  return new URL(page.url()).pathname;
}

test.describe.serial('Ребёнок ↔ родитель: покупка и бюджет', () => {
  test('1. ребёнок принимает инвайт и заполняет профиль', async ({ browser }) => {
    const data = fixture();
    const page = await loggedPage(browser, data.child);

    await page.goto(data.invitePath);
    await expect(page.getByText('Роль в семье:')).toContainText('Ребёнок');
    await page.getByRole('button', { name: 'Принять приглашение' }).click();
    await expect(page).toHaveURL('/account/family/profile');

    await page.getByLabel('Дата рождения').fill('2012-04-16');
    await page.getByLabel('Пол').selectOption('girl');
    await page.getByRole('button', { name: 'Продолжить' }).click();
    await page.getByRole('button', { name: 'Продолжить' }).click();
    await page.getByLabel('Рост, см').fill('158');
    await page.getByLabel('Размер одежды').fill('158');
    await page.getByLabel('Размер обуви').fill('38');
    await page.getByLabel('Предпочтения').fill('Любит синий цвет и свободную посадку');
    await page.getByRole('button', { name: 'Сохранить анкету' }).click();

    await expect(page).toHaveURL('/account/wardrobe-app');
    await expect(page.getByText('Анкета сохранена')).toBeVisible();
  });

  test('2. ребёнок отправляет HTTPS-ссылку, цену и комментарий', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().child);
    const requestPath = await createRequest(
      page,
      'https://www.wildberries.ru/catalog/123456/detail.aspx',
      '2499',
      'Хочу носить с синими джинсами',
    );

    await expect(page.getByText('2 499 ₽')).toBeVisible();
    await expect(page.getByText('Хочу носить с синими джинсами')).toBeVisible();
    await expect(page.getByText('Запрос отправлен родителю')).toBeVisible();
    await page.evaluate((value) => localStorage.setItem('e2eApproveRequest', value), requestPath);
  });

  test('3. родитель получает уведомление и открывает запрос из inbox', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().parent);
    await page.goto('/account/notifications');

    const notification = page.getByText('Алиса просит согласовать покупку').first();
    await expect(notification).toBeVisible();
    await notification.locator('xpath=ancestor::div[contains(@class,"flex-1")]').getByRole('link', { name: 'Открыть →' }).click();
    await expect(page).toHaveURL(/\/account\/purchases\/\d+$/);
    await expect(page.getByText('2 499 ₽')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Ваше решение' })).toBeVisible();
  });

  test('4. родитель одобряет, ребёнок получает решение и комментарий', async ({ browser }) => {
    const parentPage = await loggedPage(browser, fixture().parent);
    await parentPage.goto('/account/purchases');
    await parentPage.getByText('https://www.wildberries.ru/catalog/123456/detail.aspx').click();
    await parentPage.getByLabel('Комментарий (необязательно)').fill('Да, закажем сегодня');
    await parentPage.getByRole('button', { name: 'Одобрить' }).click();
    await expect(parentPage.getByText('Одобрено')).toBeVisible();

    const childPage = await loggedPage(browser, fixture().child);
    await childPage.goto('/account/notifications');
    await expect(childPage.getByText('Покупка одобрена').first()).toBeVisible();
    await childPage.getByText('Покупка одобрена').first().locator('xpath=ancestor::div[contains(@class,"flex-1")]').getByRole('link', { name: 'Открыть →' }).click();
    await expect(childPage.getByText('Да, закажем сегодня')).toBeVisible();
  });

  test('5. отказ без причины невозможен, причина видна ребёнку', async ({ browser }) => {
    const childPage = await loggedPage(browser, fixture().child);
    const requestPath = await createRequest(childPage, 'https://shop.example.test/jacket/77', '1800', 'Куртка на осень');

    const parentPage = await loggedPage(browser, fixture().parent);
    await parentPage.goto(requestPath);
    await parentPage.getByRole('button', { name: 'Отклонить' }).click();
    await expect(parentPage).toHaveURL(requestPath);
    await expect(parentPage.getByRole('heading', { name: 'Ваше решение' })).toBeVisible();

    await parentPage.getByLabel('Почему не сейчас').fill('Сначала примерим похожую куртку дома');
    await parentPage.getByRole('button', { name: 'Отклонить' }).click();
    await expect(parentPage.getByText('Отклонено')).toBeVisible();

    await childPage.goto(requestPath);
    await expect(childPage.getByText('Сначала примерим похожую куртку дома')).toBeVisible();
  });

  test('6. родитель задаёт месячный бюджет, остаток виден в списке', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().parent);
    await page.goto('/account/purchases/budget/manage');
    await page.getByLabel('Лимит на месяц').fill('3000');
    await page.getByRole('button', { name: 'Сохранить бюджет' }).click();

    await expect(page).toHaveURL('/account/purchases');
    await expect(page.getByText('3 000 ₽').first()).toBeVisible();
    await expect(page.getByText('из 3 000 ₽ в этом месяце')).toBeVisible();
  });

  test('7. перерасход требует явного подтверждения и меняет остаток', async ({ browser }) => {
    const childPage = await loggedPage(browser, fixture().child);
    const requestPath = await createRequest(childPage, 'https://shop.example.test/shoes/88', '4500', 'Кроссовки для физкультуры');

    const parentPage = await loggedPage(browser, fixture().parent);
    await parentPage.goto(requestPath);
    await expect(parentPage.getByText('Запрос превышает остаток')).toBeVisible();
    await parentPage.getByRole('button', { name: 'Одобрить' }).click();
    await expect(parentPage.getByText('Покупка превышает остаток бюджета. Подтвердите перерасход.')).toBeVisible();
    await expect(parentPage.getByRole('heading', { name: 'Ваше решение' })).toBeVisible();

    await parentPage.getByLabel('Разрешить перерасход бюджета').check();
    await parentPage.getByRole('button', { name: 'Одобрить' }).click();
    await expect(parentPage.getByText('Одобрено')).toBeVisible();

    await parentPage.goto('/account/purchases');
    await expect(parentPage.getByText('-3 999 ₽')).toBeVisible();
  });

  test('8. ребёнок не видит бюджет/решения, посторонний не читает запрос (IDOR)', async ({ browser }) => {
    const childPage = await loggedPage(browser, fixture().child);
    await childPage.goto('/account/purchases');
    await expect(childPage.getByRole('link', { name: 'Бюджет' })).toHaveCount(0);
    const ownRequest = await createRequest(childPage, 'https://shop.example.test/private/99', '100', 'Личный запрос');
    await expect(childPage.getByRole('heading', { name: 'Ваше решение' })).toHaveCount(0);

    const foreignPage = await loggedPage(browser, fixture().foreign);
    const response = await foreignPage.goto(ownRequest);
    expect(response?.status()).toBe(403);
    await foreignPage.goto('/account/purchases/budget/manage');
    await expect(foreignPage.locator('body')).not.toContainText('Установить лимит');
  });
});
