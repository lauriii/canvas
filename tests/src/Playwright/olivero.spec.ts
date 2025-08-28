import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Theming', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['experience_builder']);
      await page.close();
    },
  );

  // See https://www.drupal.org/project/experience_builder/issues/3485842
  test("The active theme's base CSS should not be loaded when loading the XB UI.", async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('Olivero', '/olivero');
    await drupal.drush('theme:enable olivero');
    await drupal.drush('config:set system.theme default olivero');
    await drupal.setPreprocessing({ css: false });
    await page.goto('/olivero');
    await xBEditor.goToEditor();
    // We expect the correct CSS files to be loaded for the Experience Builder UI.
    await expect(
      page.locator(
        'link[rel="stylesheet"][href^="/modules/contrib/experience_builder/ui/dist/assets/index.css"]',
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
