import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';
import { expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// @cspell:ignore xbai
/**
 * This test suite will verify XB AI related features.
 */

test.describe('AI Features', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal = new Drupal({ page, drupalSite });
      await drupal.installModules([
        'experience_builder',
        'xb_ai',
        'xb_ai_test',
      ]);
      await drupal.createRole({ name: 'xb_ai' });
      await drupal.addPermissions({
        role: 'xb_ai',
        permissions: ['view the administration theme', 'edit xb_page'],
      });
      await drupal.createUser({
        email: `xbai@example.com`,
        username: 'xbai',
        password: 'superstrongpassword1337',
        roles: ['xb_ai'],
      });
      await page.close();
    },
  );

  test('Show AI panel only to users with XB AI permissions', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.login({
      username: 'xbai',
      password: 'superstrongpassword1337',
    });
    await drupal.createXbPage('XB AI User', '/xbai_user');
    await page.goto('/xbai_user');
    await xBEditor.goToEditor();

    // @todo This test should fail once https://www.drupal.org/i/3533449 is implemented
    await expect(
      page.getByRole('button', { name: 'Open AI Panel' }),
    ).toBeAttached();

    await drupal.addPermissions({
      role: 'xb_ai',
      permissions: ['use experience builder ai'],
    });
    await page.reload();
    await expect(
      page.getByRole('button', { name: 'Open AI Panel' }),
    ).toBeAttached();
  });

  test('Create component workflow', async ({ page, drupal, xBEditor, ai }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('XB AI component', '/xbai_component');
    await page.goto('/xbai_component');
    await xBEditor.goToEditor();
    await ai.openPanel();
    await ai.submitQuery('Create component');
    await expect(page).toHaveURL(
      /\/xb\/xb_page\/\d+\/code-editor\/component\/herobanner/,
    );
    await page.getByTestId('xb-publish-review').click();
    await page
      .getByRole('checkbox', { name: 'Select all changes in Components' })
      .click();
    await page
      .getByRole('checkbox', { name: 'Select all changes in Assets' })
      .click();
    await page.getByRole('button', { name: 'Publish 2 selected' }).click();
    await page.getByRole('button', { name: 'Add to components' }).click();
    await page.getByRole('button', { name: 'Add' }).click();
    await expect(page).toHaveURL(/\/xb\/xb_page\/\d+\/editor/);
    await page.getByRole('button', { name: 'Add' }).click();
    await page
      .locator('div')
      .filter({ hasText: /^HeroBanner$/ })
      .nth(4)
      .click();
    await xBEditor.clickPreviewComponent('js.herobanner');

    // Create a second component.
    await ai.submitQuery('Create second component');
    await expect(page).toHaveURL(
      /\/xb\/xb_page\/\d+\/code-editor\/component\/herobannersecond/,
    );
    const preview = xBEditor.getCodePreviewFrame();
    const redElements = preview.locator('.bg-red-600');
    const blueElements = preview.locator('.bg-blue-600');
    await expect(redElements).toHaveCount(1);
    await expect(blueElements).toHaveCount(0);

    await ai.submitQuery('Edit component');
    const updatedPreview = xBEditor.getCodePreviewFrame();
    const redElementsUpdated = updatedPreview.locator('.bg-red-600');
    const blueElementsUpdated = updatedPreview.locator('.bg-blue-600');
    await expect(redElementsUpdated).toHaveCount(0);
    await expect(blueElementsUpdated).toHaveCount(1);
  });

  test('Image upload', async ({ page, drupal, xBEditor, ai }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('XB AI image upload', '/xbai_image');
    await page.goto('/xbai_image');
    await xBEditor.goToEditor();
    await ai.openPanel();

    const buffer = readFileSync('tests/fixtures/images/gracie-big.jpg');
    const dataTransfer = await page.evaluateHandle(async (bufferAsHex) => {
      const dt = new DataTransfer();
      const file = new File([bufferAsHex], 'gracie-big.jpg', {
        type: 'image/jpeg',
      });
      dt.items.add(file);
      return dt;
    }, buffer.toString('binary'));
    await page.dispatchEvent(
      '[data-testid="xb-ai-panel"] deep-chat #drag-and-drop',
      'drop',
      {
        dataTransfer,
      },
    );

    await expect(
      page.locator('#file-attachment-container img.image-attachment'),
    ).toBeVisible();
    await expect(
      page.locator('#file-attachment-container .remove-file-attachment-button'),
    ).toBeVisible();

    const submitButton = page
      .getByTestId('xb-ai-panel')
      .locator('.input-button.inside-right');
    await expect(submitButton).not.toBeVisible();

    await page
      .getByRole('textbox', { name: 'Build me a' })
      .fill('What is a CMS?');
    await expect(submitButton).toBeVisible();

    await page
      .locator('#file-attachment-container .remove-file-attachment-button')
      .click();
    await expect(
      page.locator('#file-attachment-container img.image-attachment'),
    ).not.toBeVisible();
    await expect(
      page.locator('#file-attachment-container .remove-file-attachment-button'),
    ).not.toBeVisible();
  });

  test('Generate title', async ({ page, drupal, xBEditor, ai }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('XB AI title', '/xbai_title');
    await page.goto('/xbai_title');
    await xBEditor.goToEditor();
    await ai.openPanel();
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'XB AI title',
    );
    await ai.submitQuery('Generate title');
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'Welcome to Our Interactive Experience',
    );
  });

  test('Generate metadata', async ({ page, drupal, xBEditor, ai }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('XB AI metadata', '/xbai_metadata');
    await page.goto('/xbai_metadata');
    await xBEditor.goToEditor();
    await ai.openPanel();
    await expect(
      page.getByRole('textbox', { name: 'Meta description' }),
    ).toHaveValue('');
    await ai.submitQuery('Generate metadata');
    await expect(
      page.getByRole('textbox', { name: 'Meta description' }),
    ).toHaveValue(
      'Experience a journey through our interactive digital space, designed to engage and inspire visitors with immersive content and seamless navigation.',
    );
  });
});
