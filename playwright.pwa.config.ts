import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: '15-wardrobe-pwa.spec.ts',
  globalSetup: './tests/e2e/wardrobe-onboarding-global-setup.ts',
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8012',
    ...devices['iPhone 13'],
    browserName: 'chromium',
    locale: 'ru-RU',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php -S 127.0.0.1:8012 tests/e2e/fixtures/wardrobe-onboarding-router.php',
    url: 'http://127.0.0.1:8012/login',
    reuseExistingServer: false,
    timeout: 30_000,
  },
});
