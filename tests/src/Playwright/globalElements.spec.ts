import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { getModuleDir } from './utilities/DrupalFilesystem';
import { readFile } from 'fs/promises';
/**
 * Tests global elements.
 */

test.describe('Global elements', () => {
  test('Setup test site with Experience Builder', async ({ drupal }) => {
    await drupal.setupXBTestSite();
  });
  test('Page title', async ({ page, xBEditor, drupal }) => {
    await drupal.loginAsAdmin();
    await page.goto('/first');
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
  });
  test('Site branding', async ({ page, xBEditor, drupal }) => {
    await drupal.loginAsAdmin();
    await page.goto('/first');
    await xBEditor.goToEditor();
    const moduleDir = await getModuleDir();
    const code = await readFile(
      `${moduleDir}/experience_builder/tests/fixtures/code_components/page-elements/SiteBranding.jsx`,
      'utf-8',
    );
    await xBEditor.addCodeComponent('SiteBranding', code);
    const preview = page
      .locator('.xb-mosaic-window-preview iframe')
      .contentFrame()
      .locator('#xb-code-editor-preview-root');
    // Site name defaults to 'Drupal'.
    // @see \Drupal\Core\Command\InstallCommand::configure
    await expect(preview.getByRole('link', { name: 'Drupal' })).toBeVisible();
  });
  test('Breadcrumbs', async ({ page, xBEditor, drupal }) => {
    await drupal.loginAsAdmin();
    await page.goto('/first');
    await xBEditor.goToEditor();
    const moduleDir = await getModuleDir();
    const code = await readFile(
      `${moduleDir}/experience_builder/tests/fixtures/code_components/page-elements/Breadcrumbs.jsx`,
      'utf-8',
    );
    await xBEditor.addCodeComponent('Breadcrumbs', code);
    const preview = page
      .locator('.xb-mosaic-window-preview iframe')
      .contentFrame()
      .locator('#xb-code-editor-preview-root');
    // @see \Drupal\experience_builder\Controller\ExperienceBuilderController::__invoke
    await expect(preview.getByRole('link', { name: 'Home' })).toBeVisible();
    await expect(
      preview.getByRole('link', { name: 'My account' }),
    ).toBeVisible();
    expect(await preview.getByRole('listitem').all()).toHaveLength(2);
    await expect(
      preview.getByRole('heading', { name: 'Breadcrumb' }),
    ).toBeVisible();
  });
});
