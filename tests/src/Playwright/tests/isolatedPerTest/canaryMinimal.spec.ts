import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests installing Drupal Canvas.
 */

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

test.describe('Canary Canvas Minimal', () => {
  test('View page', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/page/${canvasPage.entity_id}`);
    /* eslint-disable no-useless-escape */
    await expect(page.locator('#block-stark-local-tasks')).toMatchAriaSnapshot(`
      - heading "Primary tabs" [level=2]
      - list:
        - listitem:
          - link "View":
            - /url: /\/page\/\\d+/
        - listitem:
          - link "Edit":
            - /url: /\/canvas\/editor\/canvas_page\/\\d+/
        - listitem:
          - link "Revisions":
            - /url: /\/page\/\\d+\/revisions/
    `);
    /* eslint-enable no-useless-escape */
  });
});
