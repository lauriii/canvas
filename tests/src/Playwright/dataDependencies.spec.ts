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
      await drupal.setupMinimalXBTestSite();
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
    await xBEditor.addCodeComponent('PageTitle', code);
    const preview = page
      .locator('.xb-mosaic-window-preview iframe')
      .contentFrame()
      .locator('#xb-code-editor-preview-root');
    // @see \Drupal\experience_builder\Controller\ExperienceBuilderController::__invoke
    await expect(
      preview.getByRole('heading', {
        name: 'This is a page title for testing purposes',
      }),
    ).toBeVisible();
    await xBEditor.publishAllChanges(['PageTitle', 'Global CSS']);
    await page.getByRole('button', { name: 'Add to components' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Add' }).click();
    await xBEditor.addComponent('js.pagetitle', false, false);
    await xBEditor.publishAllChanges(['Homepage']);
    await page.goto('/homepage');
    expect(
      await page
        .locator('astro-island')
        .getByRole('heading', { name: 'Homepage' }),
    ).toBeVisible();
  });
});
