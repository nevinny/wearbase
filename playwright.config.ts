import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E конфигурация для WEARBASE.
 *
 * Запуск:
 *   npm install
 *   npx playwright install chromium
 *   npx playwright test
 *   npx playwright test --headed     # с браузером
 *   npx playwright test --ui         # интерактивный UI
 *
 * Переменные окружения:
 *   BASE_URL   — базовый URL (по умолчанию http://wearbase.dev.local)
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,       // sequential — один сервер
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
  ],

  use: {
    baseURL: process.env.BASE_URL ?? 'http://wearbase.dev.local',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    locale: 'ru-RU',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  // Локальный dev-сервер не запускаем — он уже запущен
  // webServer: { ... }
});
