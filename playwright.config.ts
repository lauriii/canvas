import { defineConfig, devices } from '@playwright/test';

import 'dotenv-defaults/config';

/**
 * See https://playwright.dev/docs/test-configuration.
 */
export default defineConfig({
  testDir: './tests/src/Playwright',
  /* Don't run tests within files in parallel */
  fullyParallel: false,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Maximum failures */
  maxFailures: 999999,
  /* Parallel test workers, leave undefined for automatic */
  workers: '50%',
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: [
    ['list'],
    ['junit', { outputFile: 'test-results/playwright.xml' }],
    ['html', { host: '0.0.0.0', open: 'never' }],
  ],
  /* https://playwright.dev/docs/test-timeouts */
  timeout: process.env.CI ? 120_000 : 30_000,
  expect: { timeout: 10_000 },
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    baseURL: process.env.DRUPAL_TEST_BASE_URL,
    /* https://playwright.dev/docs/api/class-testoptions#test-options-ignore-https-errors */
    ignoreHTTPSErrors: true,
    /* For https://playwright.dev/docs/locators#locate-by-test-id */
    testIdAttribute: 'data-testid',
    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: 'on-first-retry',
    /* Take screenshot automatically on test failure */
    screenshot: {
      mode: 'only-on-failure',
      fullPage: true,
    },
    video: 'retain-on-failure',
  },

  /* Configure projects for major browsers */
  projects: [
    {
      name: 'setup',
      testMatch: /_global\.setup\.ts/,
    },

    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        deviceScaleFactor: 1,
        /* Making the browser window/viewport much bigger avoids weird issues like the UI covering up part of the editor frame etc. */
        viewport: { width: 1920, height: 1080 },
      },
      dependencies: ['setup'],
    },

    {
      name: 'firefox',
      use: {
        ...devices['Desktop Firefox'],
        deviceScaleFactor: 1,
        viewport: { width: 1920, height: 1080 },
      },
      dependencies: ['setup'],
    },

    {
      name: 'webkit',
      use: {
        ...devices['Desktop Safari'],
        // Explicitly set the device pixel ratio as webkit is 2 by default, and
        // chromium and firefox are 1.
        deviceScaleFactor: 1,
        viewport: { width: 1920, height: 1080 },
      },
      dependencies: ['setup'],
    },
  ],
});
