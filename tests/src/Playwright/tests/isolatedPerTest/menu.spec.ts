import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests adding a menu code component.
 */
test.describe('Menu Component', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/menu`,
    );
    // @todo remove the cache clear once https://www.drupal.org/project/drupal/issues/3534825
    // is fixed.
    await drupal.clearCache();
    await drupal.logout();
  });

  test('Add and test menu component', async ({ page, drupal, canvas }) => {
    await page.setViewportSize({ width: 2560, height: 1080 });
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());

    const code = await readFile(
      `tests/fixtures/code_components/menus/Menu.tsx`,
      'utf-8',
    );
    await canvas.createCodeComponent('Menu', code);
    const preview = canvas.getCodePreviewFrame();

    await expect(preview).toContainText('JSON:API Menu');
    await expect(preview).toContainText('Core Linkset Menu');
    const menus = await preview.getByTestId('menu').all();
    for (const menu of menus) {
      await expect(menu.getByTestId('menu-links')).toMatchAriaSnapshot(`
        - list:
          - listitem:
            - link "Home":
              - /url: "/"
          - listitem:
            - link "Shop":
              - /url: ""
          - listitem:
            - link "Space Bears":
              - /url: ""
            - button
          - listitem:
            - link "Mars Cars":
              - /url: ""
          - listitem:
            - link "Contact":
              - /url: "/"
      `);
      await menu.getByTestId('open-submenu').click({ force: true });
      await expect(menu.getByTestId('submenu')).toBeVisible();
      await expect(menu.getByTestId('submenu')).toMatchAriaSnapshot(`
        - list:
          - listitem:
            - link "Space Bear 6":
              - /url: "/"
          - listitem:
            - link "Space Bear 6 Plus":
              - /url: /user
          - listitem:
            - link "Mega Space Bears":
              - /url: ""
      `);
      // Close the menu otherwise it will cover the menu below and cause it to be not visible.
      await menu.getByTestId('open-submenu').click({ force: true });
    }
  });
});
