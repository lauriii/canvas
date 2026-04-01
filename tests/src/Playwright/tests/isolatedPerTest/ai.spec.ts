import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// @cspell:ignore canvasai
/**
 * This test suite will verify Canvas AI related features.
 */

test.describe('AI Features', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules(['canvas_ai_test']);
    await drupal.createRole({ name: 'ai_editor' });
    await drupal.createUser({
      email: `ai_editor@example.com`,
      username: 'ai_editor',
      password: 'ai_editor',
      roles: ['ai_editor'],
    });
    await drupal.addPermissions({
      role: 'ai_editor',
      permissions: [
        'create canvas_page',
        'edit canvas_page',
        'publish auto-saves',
        'administer code components',
        'use drupal canvas ai',
        'create media',
      ],
    });
    await drupal.logout();
  });

  test('Show AI panel only to users with Canvas AI permissions', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await expect(
      page.getByRole('button', { name: 'Open AI Panel' }),
    ).not.toBeAttached();
    await drupal.logout();
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.openCanvas(canvasPage);
    await expect(
      page.getByRole('button', { name: 'Open AI Panel' }),
    ).toBeAttached();
  });

  test('Create component workflow', async ({ page, drupal, canvas, ai }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();
    await ai.submitQuery('Create component');
    await expect(page).toHaveURL(
      /\/canvas\/code-editor\/component\/herobanner/,
    );
    await page.getByTestId('canvas-publish-review').click();
    await page
      .getByRole('checkbox', { name: 'Select all changes in Components' })
      .click();
    await page
      .getByRole('checkbox', { name: 'Select all changes in Assets' })
      .click();
    await page.getByRole('button', { name: 'Publish 2 selected' }).click();
    await page.getByRole('button', { name: 'Add to components' }).click();
    await page.getByRole('button', { name: 'Add' }).click();
    await expect(page).toHaveURL(/\/canvas\/editor\/canvas_page\/\d+$/);
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'HeroBanner' });
    await canvas.clickPreviewComponent('js.herobanner');

    // Create a second component.
    await ai.submitQuery('Create second component');
    await expect(page).toHaveURL(
      /\/canvas\/code-editor\/component\/herobannersecond/,
    );
    const preview = canvas.getCodePreviewFrame();
    const redElements = preview.locator('.bg-red-600');
    const blueElements = preview.locator('.bg-blue-600');
    await expect(redElements).toHaveCount(1);
    await expect(blueElements).toHaveCount(0);

    await ai.submitQuery('Edit component');
    const updatedPreview = canvas.getCodePreviewFrame();
    const redElementsUpdated = updatedPreview.locator('.bg-red-600');
    const blueElementsUpdated = updatedPreview.locator('.bg-blue-600');
    await expect(redElementsUpdated).toHaveCount(0);
    await expect(blueElementsUpdated).toHaveCount(1);
  });

  test('Image upload', async ({ page, drupal, canvas, ai }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
    await ai.openPanel();

    await canvas.dropFile(
      page.locator('[data-testid="canvas-ai-panel"] deep-chat #drag-and-drop'),
      'tests/fixtures/images/gracie-big.jpg',
      'image/jpeg',
    );

    await expect(
      page.locator('#file-attachment-container img.image-attachment'),
    ).toBeVisible();
    await expect(
      page.locator('#file-attachment-container .remove-file-attachment-button'),
    ).toBeVisible();

    await expect(
      page
        .getByTestId('canvas-ai-panel')
        .locator('div.submit-button[role="button"]'),
    ).not.toBeVisible();

    await page
      .getByRole('textbox', { name: 'Build me a' })
      .fill('What is a CMS?');

    await expect(
      page
        .getByTestId('canvas-ai-panel')
        .locator('div.submit-button[role="button"]'),
    ).toBeVisible();

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

  test('Generate title', async ({ page, drupal, canvas, ai }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas({ title: 'Canvas AI title' });
    await ai.openPanel();
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'Canvas AI title',
    );
    await ai.submitQuery('Generate title');
    await expect(page.getByRole('textbox', { name: 'Title*' })).toHaveValue(
      'Welcome to Our Interactive Experience',
    );
  });

  test('Generate metadata', async ({ page, drupal, canvas, ai }) => {
    await drupal.login({ username: 'ai_editor', password: 'ai_editor' });
    await canvas.createCanvas();
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
