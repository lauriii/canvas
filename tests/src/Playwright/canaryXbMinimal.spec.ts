import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

/**
 * Tests installing Experience Builder.
 */

test.describe('Canary XB Minimal', () => {
  test.beforeAll(
    'Setup minimal test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['experience_builder', 'xb_test_sdc']);
      await drupal.createXbPage('Homepage', '/homepage');
      await page.close();
    },
  );

  test('View homepage', async ({ page, drupal }) => {
    await drupal.loginAsAdmin();
    await page.goto('/homepage');
    /* eslint-disable no-useless-escape */
    await expect(page.locator('#block-stark-local-tasks')).toMatchAriaSnapshot(`
      - heading "Primary tabs" [level=2]
      - list:
        - listitem:
          - link "View":
            - /url: /homepage
        - listitem:
          - link "Edit":
            - /url: /\/xb\/xb_page\/\\d+/
    `);
    /* eslint-enable no-useless-escape */
  });
});
