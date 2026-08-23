import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: '13-wardrobe-onboarding-mobile.spec.ts',
  globalSetup: './tests/e2e/wardrobe-onboarding-global-setup.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8011',
    ...devices['iPhone 13'],
    browserName: 'chromium',
    locale: 'ru-RU',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php -S 127.0.0.1:8011 tests/e2e/fixtures/wardrobe-onboarding-router.php',
    url: 'http://127.0.0.1:8011/login',
    reuseExistingServer: false,
    timeout: 30_000,
  },
});
