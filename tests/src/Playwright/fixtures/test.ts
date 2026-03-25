import {
  Drupal,
  isolatedPerTest as isolatedPerTestBase,
  parallelWorker as parallelWorkerBase,
} from '@drupal/playwright';

import { Ai } from '../objects/Ai.js';
import { Canvas } from '../objects/Canvas.js';
import { setupSite } from '../setup.js';

import type { DrupalSite } from '@drupal/playwright';
import type { Browser } from '@playwright/test';

export const isolatedPerTest = isolatedPerTestBase.extend<{
  canvas: Canvas;
  ai: Ai;
  beforeEach: void;
}>({
  beforeEach: [
    async ({ drupal }, use) => {
      await setupSite({ drupal });
      await use();
    },
    { auto: true },
  ],
  canvas: [
    async ({ drupal }, use) => {
      const canvas = new Canvas({ drupal });
      await use(canvas);
    },
    { auto: true },
  ],
  ai: [
    async ({ page }, use) => {
      const ai = new Ai({ page });
      await use(ai);
    },
    { auto: true },
  ],
});

export const parallelWorker = parallelWorkerBase.extend<
  { canvas: Canvas; ai: Ai },
  { browser: Browser; drupalSite: DrupalSite; beforeAll: void }
>({
  beforeAll: [
    async ({ browser, drupalSite }, use, testInfo) => {
      const baseURL = testInfo.project?.use?.baseURL || '';
      const context = await browser.newContext({
        baseURL,
      });
      const page = await context.newPage();
      const drupal = new Drupal({ page, drupalSite });
      await setupSite({ drupal });
      await page.close();
      await context.close();
      await use();
    },
    { scope: 'worker', auto: true },
  ],
  canvas: [
    async ({ drupal }, use) => {
      const canvas = new Canvas({ drupal });
      await use(canvas);
    },
    { auto: true },
  ],
  ai: [
    async ({ page }, use) => {
      const ai = new Ai({ page });
      await use(ai);
    },
    { auto: true },
  ],
});
