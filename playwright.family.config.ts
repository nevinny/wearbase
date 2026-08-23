import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const databaseUrl = 'sqlite:///'+path.resolve(__dirname, 'var/e2e-family.db');

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: ['11-family-purchase-flow.spec.ts', '12-family-wardrobe-members.spec.ts'],
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 30_000,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report-family', open: 'never' }]],
  globalSetup: './tests/e2e/family-global-setup.ts',
  use: {
    baseURL: 'http://127.0.0.1:18765',
    locale: 'ru-RU',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'mobile-chromium', use: { ...devices['Pixel 7'] } }],
  webServer: {
    command: 'php -S 127.0.0.1:18765 -t public_html tests/e2e/fixtures/server-router.php',
    env: { ...process.env, APP_ENV: 'test', DATABASE_URL: databaseUrl },
    url: 'http://127.0.0.1:18765/ru/wardrobe',
    reuseExistingServer: false,
    timeout: 120_000,
  },
});
