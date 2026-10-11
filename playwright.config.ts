import { defineConfig, devices } from '@playwright/test';

/**
 * E2E config for the WebP Studio admin UI.
 *
 * Credentials come from the environment. The defaults are the local Docker stack
 * values only (see .env, which is gitignored) so `npm test` works out of the box on a
 * developer machine. CI overrides every one of them with its own secrets; no real
 * credential is ever committed here.
 */
const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8080';

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.ts',
  outputDir: './test-results',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  // Zero retries, on purpose. A retry in CI is the mechanism that turns an unstable
  // test into one that quietly passes: the first failure is discarded and nobody sees
  // it, so the suite looks green and gets less trustworthy every run. Failures here
  // have to be visible. Raise this only with a reason, and only alongside a fix.
  retries: 0,
  // The suite mutates plugin options. Workers sharing one WordPress install would race
  // on the same option row, so run serially.
  workers: 1,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
  ],
  timeout: 60_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    // To add a browser, install it (`npx playwright install firefox`) and uncomment:
    // { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    // { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
