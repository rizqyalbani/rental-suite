import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.ts',
  timeout: 90_000,
  expect: {
    timeout: 10_000,
  },
  /** One worker avoids flaky shared-data races when all tests use the same seeded account. */
  workers: 1,
  use: {
    baseURL: 'http://127.0.0.1:8085',
    headless: true,
    trace: 'on-first-retry',
    storageState: 'tests/e2e/.auth/user.json',
  },
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' } },
    { name: 'chrome', use: { browserName: 'chromium', channel: 'chrome' } },
    { name: 'firefox', use: { browserName: 'firefox' } },
    { name: 'webkit', use: { browserName: 'webkit' } },
  ],
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8085',
    url: 'http://127.0.0.1:8085',
    reuseExistingServer: true,
    timeout: 120_000,
  },
});
