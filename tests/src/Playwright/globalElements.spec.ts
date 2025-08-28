import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { getModuleDir } from './utilities/DrupalFilesystem';
import { readFile } from 'fs/promises';
import { Drupal } from './objects/Drupal';
/**
 * Tests global elements.
 */

test.describe('Global elements', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['experience_builder']);
      await page.close();
    },
  );

  test('Page title', async ({ page, xBEditor, drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('Page Title', '/page-title');
    await page.goto('/page-title');
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
  });

  test('Site branding', async ({ page, xBEditor, drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('Site Branding', '/site-branding');
    await page.goto('/site-branding');
    await xBEditor.goToEditor();
    const moduleDir = await getModuleDir();
    const code = await readFile(
      `${moduleDir}/experience_builder/tests/fixtures/code_components/page-elements/SiteBranding.jsx`,
      'utf-8',
    );
    await xBEditor.createCodeComponent('SiteBranding', code);
    const preview = xBEditor.getCodePreviewFrame();
    // Site name defaults to 'Drupal'.
    // @see \Drupal\Core\Command\InstallCommand::configure
    await expect(preview.getByRole('link', { name: 'Drupal' })).toBeVisible();
  });

  test('Breadcrumbs', async ({ page, xBEditor, drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('Breadcrumbs', '/breadcrumbs');
    await page.goto('/breadcrumbs');
    await xBEditor.goToEditor();
    const moduleDir = await getModuleDir();
    const code = await readFile(
      `${moduleDir}/experience_builder/tests/fixtures/code_components/page-elements/Breadcrumbs.jsx`,
      'utf-8',
    );
    await xBEditor.createCodeComponent('Breadcrumbs', code);
    const preview = xBEditor.getCodePreviewFrame();
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
