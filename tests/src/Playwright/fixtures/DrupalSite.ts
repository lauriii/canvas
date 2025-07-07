import { mergeTests } from '@playwright/test';
import { test as base } from '@playwright/test';
import { Drupal } from '../objects/Drupal';
import { XBEditor } from '../objects/XBEditor';
import { exec } from '../utilities/DrupalExec';
import { hasDrush } from '../utilities/DrupalFilesystem';

export type DrupalSite = {
  dbPrefix: string;
  userAgent: string;
  sitePath: string;
  url: string;
  hasDrush: boolean;
  teardown: Promise<string>;
};

type DrupalSiteInstall = {
  drupalSite: DrupalSite;
};

const drupalSite = base.extend<DrupalSiteInstall>({
  drupalSite: [
    async ({}, use, workerInfo) => {
      const stdout = await exec(
        `php core/scripts/test-site.php install --no-interaction --install-profile minimal --base-url ${process.env.DRUPAL_TEST_BASE_URL} --db-url ${process.env.DRUPAL_TEST_DB_URL}-${workerInfo.workerIndex} --json`,
      );
      const installData = JSON.parse(stdout.toString());
      const withDrush = await hasDrush();

      await use({
        dbPrefix: installData.db_prefix,
        userAgent: installData.user_agent,
        sitePath: installData.site_path,
        url: process.env.DRUPAL_TEST_BASE_URL,
        hasDrush: withDrush,
        teardown: async () => {
          if (
            process.env.DRUPAL_TEST_PLAYWRIGHT_SKIP_TEARDOWN &&
            process.env.DRUPAL_TEST_PLAYWRIGHT_SKIP_TEARDOWN === 'true'
          ) {
            return Promise.resolve('');
          }
          return await exec(
            `php core/scripts/test-site.php tear-down --no-interaction --db-url ${process.env.DRUPAL_TEST_DB_URL}-${workerInfo.workerIndex} ${installData.db_prefix}`,
          );
        },
      });
    },
    { scope: 'worker' },
  ],
});

type DrupalObj = {
  drupal: Drupal;
};

const drupal = base.extend<DrupalObj>({
  drupal: [
    async ({ page, drupalSite }, use) => {
      const drupal = new Drupal({ page, drupalSite });
      await use(drupal);
    },
    { auto: true },
  ],
});

type XBEditorObj = {
  xBEditor: XBEditor;
};

const xBEditor = base.extend<XBEditorObj>({
  xBEditor: [
    async ({ page }, use) => {
      const xBEditor = new XBEditor({ page });
      await use(xBEditor);
    },
    { auto: true },
  ],
});

export const beforeAllTests = base.extend<{ forEachWorker: void }>({
  forEachWorker: [
    async ({ drupalSite }, use) => {
      await use();
      // This code runs after all the tests in the worker process.
      drupalSite.teardown();
    },
    { scope: 'worker', auto: true },
  ], // automatically starts for every worker.
});

const beforeEachTest = base.extend<{ forEachTest: void }>({
  forEachTest: [
    async ({ drupal }, use) => {
      // This code runs before every test.
      await drupal.setTestCookie();
      await use();
    },
    { auto: true },
  ], // automatically starts for every test.
});

export const test = mergeTests(
  drupalSite,
  drupal,
  xBEditor,
  beforeAllTests,
  beforeEachTest,
);
