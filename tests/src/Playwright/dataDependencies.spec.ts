import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { getModuleDir } from './utilities/DrupalFilesystem';
import { readFile } from 'fs/promises';
import { Drupal } from './objects/Drupal';
// @cspell:ignore PageTitle
/**
 * Tests data dependencies.
 */

test.describe('Data dependencies', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['experience_builder']);
      await drupal.createXbPage('Homepage', '/homepage');
      await page.close();
    },
  );

  test('Are extracted and saved to the entity', async ({
    page,
    xBEditor,
    drupal,
  }) => {
    await drupal.loginAsAdmin();
    await page.goto('/homepage');
    await xBEditor.goToEditor();
    const moduleDir = await getModuleDir();
    const code = await readFile(
      `${moduleDir}/experience_builder/tests/fixtures/code_components/page-elements/PageTitle.jsx`,
      'utf-8',
    );
    await xBEditor.createCodeComponent('PageTitle', code);
    const preview = xBEditor.getCodePreviewFrame();
    // @see \Drupal\experience_builder\Controller\ExperienceBuilderController::__invoke
    await expect(
      preview.getByRole('heading', {
        name: 'This is a page title for testing purposes',
      }),
    ).toBeVisible();
    await xBEditor.publishAllChanges(['PageTitle', 'Global CSS']);
    await page.getByRole('button', { name: 'Add to components' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Add' }).click();
    await xBEditor.addComponent({ id: 'js.pagetitle' }, { hasInputs: false });
    await xBEditor.publishAllChanges(['Homepage']);
    await page.goto('/homepage');
    await expect(
      page.locator('xb-island').getByRole('heading', { name: 'Homepage' }),
    ).toBeVisible();
  });
});
