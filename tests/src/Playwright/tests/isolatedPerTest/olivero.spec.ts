import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.describe('Theming', () => {
  // See https://www.drupal.org/project/canvas/issues/3485842
  test("The active theme's base CSS should not be loaded when loading the Canvas UI.", async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    await page.goto('/admin/appearance');
    await page.getByTitle('Install Olivero as default theme').click();
    await expect(
      page.getByRole('contentinfo', { name: 'Status message' }),
    ).toContainText('Olivero is now the default theme.');
    await drupal.setPreprocessing({ css: false });
    await canvas.openCanvas(await canvas.createCanvas());
    // We expect the correct CSS files to be loaded for the Drupal Canvas UI.
    await expect(
      page.locator(
        'link[rel="stylesheet"][href^="/modules/contrib/canvas/ui/dist/assets/index.css"]',
      ),
    ).toHaveCount(1);
    // But we do not expect the base CSS of the active theme to be loaded.
    await expect(
      page.locator(
        'link[rel="stylesheet"][href^="/core/themes/olivero/css/base/base.css"]',
      ),
    ).toHaveCount(0);
  });
});
