import { expect, test, type Browser, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

type Actor = { email: string; id: number };
type Fixture = {
  password: string;
  parent: Actor;
  child: Actor;
  spouse: Actor;
  foreign: Actor;
  spouseInvitePath: string;
  items: Record<'care'|'internal'|'external'|'wearTop'|'wearBottom', number>;
  purchase: { id: number; itemId: number };
};
const fixture = (): Fixture => JSON.parse(readFileSync(path.resolve(__dirname, '../../var/e2e-wardrobe-lifecycle-fixture.json'), 'utf8'));

async function login(page: Page, actor: Actor): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(actor.email);
  await page.getByLabel('Пароль').fill(fixture().password);
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/account/);
}
async function loggedPage(browser: Browser, actor: Actor): Promise<Page> {
  const context = await browser.newContext();
  await context.addInitScript(() => localStorage.setItem('cookieConsent', '1'));
  const page = await context.newPage();
  await login(page, actor);
  return page;
}

test.describe.serial('Полный семейный lifecycle гардероба', () => {
  test('1. вещь создаётся, редактируется и удаляется', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().parent);
    await page.goto('/account/wardrobe/new');
    await page.locator('input[name="wardrobe_item_form[name]"]').fill('E2E новая юбка');
    await page.locator('input[name="wardrobe_item_form[price]"]').fill('1999');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL('/account/wardrobe');
    await page.getByText('E2E новая юбка', { exact: true }).click();
    await expect(page).toHaveURL(/\/account\/wardrobe\/\d+/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('E2E новая юбка');
    await page.getByRole('link', { name: 'Редактировать' }).click();
    await page.locator('input[name="wardrobe_item_form[name]"]').fill('E2E юбка обновлена');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('E2E юбка обновлена');
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Удалить' }).click();
    await expect(page).toHaveURL('/account/wardrobe');
    await expect(page.getByText('E2E юбка обновлена')).toHaveCount(0);
  });

  test('2. одобренная покупка проходит заказ, получение, примерку и попадает в гардероб', async ({ browser }) => {
    const data = fixture();
    const page = await loggedPage(browser, data.parent);
    await page.goto(`/account/purchases/${data.purchase.id}`);
    await page.getByRole('button', { name: 'Отметить «Заказано»' }).click();
    await expect(page.getByRole('button', { name: 'Получено, можно примерить' })).toBeVisible();
    await page.getByRole('button', { name: 'Получено, можно примерить' }).click();
    await expect(page.getByText('Как прошла примерка?')).toBeVisible();
    await page.locator('select[name="outcome"]').selectOption('bought');
    await page.locator('input[name="triedSize"]').fill('158');
    await page.locator('select[name="sizing"]').selectOption('true_to_size');
    await page.locator('textarea[name="comment"]').fill('Село хорошо, материал приятный');
    await page.getByRole('button', { name: 'Сохранить примерку' }).click();
    await expect(page.getByRole('button', { name: 'Добавить в гардероб' })).toBeVisible();
    await page.getByRole('button', { name: 'Добавить в гардероб' }).click();
    await expect(page).toHaveURL(new RegExp(`/account/wardrobe/\\d+\\?member=${data.child.id}`));
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Покупка из магазина');
    await expect(page.getByRole('link', { name: 'Ссылка на товар ↗' })).toHaveAttribute('href', 'https://shop.example.test/e2e-dress');
  });

  test('3. химчистка и подшив сохраняются в истории и возвращают вещь', async ({ browser }) => {
    const data = fixture();
    const page = await loggedPage(browser, data.parent);
    await page.goto(`/account/wardrobe/${data.items.care}`);
    await page.locator('select[name="type"]').selectOption('dry_cleaning');
    await page.locator('input[name="provider"]').first().fill('E2E химчистка');
    await page.locator('input[name="cost"]').fill('1200');
    await page.getByRole('button', { name: 'Отправить' }).click();
    await expect(page.getByText('E2E химчистка')).toBeVisible();
    await page.getByRole('button', { name: 'Готово, вернуть в гардероб' }).click();
    await page.locator('select[name="type"]').selectOption('repair_hem');
    await page.locator('input[name="provider"]').first().fill('E2E ателье');
    await page.getByRole('textbox', { name: 'Что нужно сделать' }).fill('Подшить рукава');
    await page.getByRole('button', { name: 'Отправить' }).click();
    await expect(page.getByText('Подшив', { exact: true })).toBeVisible();
    await expect(page.getByText('Подшить рукава')).toBeVisible();
  });

  test('4. вещь передаётся ребёнку внутри семьи', async ({ browser }) => {
    const data = fixture();
    const page = await loggedPage(browser, data.parent);
    await page.goto(`/account/wardrobe/${data.items.internal}`);
    await page.locator('select[name="to_user"]').selectOption(String(data.child.id));
    await page.getByRole('textbox', { name: 'например: перешло от старшей' }).fill('Стала подходить Мире');
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Передать', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`member=${data.child.id}`));
    await expect(page.getByText('Анна → Мира')).toBeVisible();
  });

  test('5. ручное подтверждение образа считает носки и собирает feedback', async ({ browser }) => {
    const page = await loggedPage(browser, fixture().parent);
    await page.goto('/account/wardrobe-app');
    await page.getByRole('link', { name: '📸 Что на мне сегодня' }).click();
    await page.getByRole('button', { name: 'Без фото — выбрать вещи вручную' }).click();
    await page.getByText('E2E белая футболка', { exact: true }).click();
    await page.getByText('E2E синие джинсы', { exact: true }).click();
    await page.getByRole('button', { name: 'Подтвердить образ' }).click();
    await expect(page.getByText('1 000,00 ₽ / носку')).toBeVisible();
    await page.getByText('Как носился образ?').click();
    await page.getByText('Удобно', { exact: true }).click();
    await page.getByText('Хочу повторить', { exact: true }).click();
    await page.locator('input[name="comment"]').fill('Хорошо сочетается и удобно');
    await page.getByRole('button', { name: 'Сохранить впечатление' }).click();
    await expect(page.getByText('Стилист запомнит эту обратную связь')).toBeVisible();
  });

  test('6. вещь передаётся наружу и исчезает из активного гардероба', async ({ browser }) => {
    const data = fixture();
    const page = await loggedPage(browser, data.parent);
    await page.goto(`/account/wardrobe/${data.items.external}`);
    const form = page.getByRole('heading', { name: 'Передать вне семьи' }).locator('..');
    await form.locator('input[name="provider"]').fill('Благотворительный фонд');
    page.once('dialog', dialog => dialog.accept());
    await form.getByRole('button', { name: 'Передать наружу' }).click();
    await expect(page).toHaveURL('/account/wardrobe');
    await expect(page.getByText('E2E куртка наружу')).toHaveCount(0);
  });

  test('7. супруг принимает приглашение и появляется в составе семьи', async ({ browser }) => {
    const data = fixture();
    const page = await loggedPage(browser, data.spouse);
    await page.goto(data.spouseInvitePath);
    await page.getByRole('button', { name: 'Принять приглашение' }).click();
    await expect(page).toHaveURL('/account/family');
    await expect(page.getByText('Илья')).toBeVisible();
    await expect(page.getByText('Родитель').first()).toBeVisible();
  });

  test('8. после 18 лет профиль становится самостоятельным и родитель теряет доступ к гардеробу', async ({ browser }) => {
    const data = fixture();
    const page = await loggedPage(browser, data.parent);
    await page.goto('/account/family');
    await page.getByRole('button', { name: 'Сделать профиль самостоятельным' }).click();
    await expect(page.getByText('Взрослый')).toBeVisible();
    const denied = await page.goto(`/account/wardrobe?member=${data.child.id}`);
    expect(denied?.status()).toBe(403);
  });

  test('9. владелец передаёт права супругу и выходит, сохраняя свой гардероб', async ({ browser }) => {
    const data = fixture();
    const page = await loggedPage(browser, data.parent);
    await page.goto('/account/family');
    await page.getByRole('button', { name: 'Передать права владельца' }).click();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Выйти из семьи' }).click();
    await expect(page.getByText('Вы вышли из семьи')).toBeVisible();
    await page.goto('/account/wardrobe');
    await expect(page.getByText('E2E пальто')).toBeVisible();
  });
});
