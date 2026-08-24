import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const databaseUrl = 'sqlite:///'+path.resolve(__dirname, 'var/e2e-wardrobe-lifecycle.db');
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: '14-family-wardrobe-lifecycle.spec.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 35_000,
  reporter: [['list']],
  globalSetup: './tests/e2e/wardrobe-lifecycle-global-setup.ts',
  use: { baseURL: 'http://127.0.0.1:18766', locale: 'ru-RU', trace: 'retain-on-failure', screenshot: 'only-on-failure', ...devices['iPhone 13'], browserName: 'chromium' },
  webServer: {
    command: 'php -S 127.0.0.1:18766 -t public_html tests/e2e/fixtures/server-router.php',
    env: { ...process.env, APP_ENV: 'test', DATABASE_URL: databaseUrl },
    url: 'http://127.0.0.1:18766/login',
    reuseExistingServer: false,
    timeout: 120_000,
  },
});
