import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Olivero', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['experience_builder']);
      await page.close();
    },
  );

  // can be tidied up too.#
  // See https://www.drupal.org/project/experience_builder/issues/3485842
  test('CSS ', async ({ page, drupal, xBEditor }) => {
    await drupal.createXbPage('Olivero', '/olivero');
    await drupal.drush('theme:enable olivero');
    await drupal.drush('config:set system.theme default olivero');
    await drupal.drush('config:set system.performance css.preprocess 0');
    await drupal.drush('cache:rebuild');
    await drupal.loginAsAdmin();
    await page.goto('/olivero');
    await xBEditor.goToEditor();
    await expect(
      page.locator(
        'link[rel="stylesheet"][href^="/core/themes/olivero/css/base/base.css"]',
      ),
    ).toHaveCount(0);
  });
});
