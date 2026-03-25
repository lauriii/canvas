import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.describe('Views contextual filter in Canvas preview', () => {
  test.beforeEach(async ({ drupal, page }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules(['canvas_test_views_contextual']);
    await page.goto('/admin/structure/types/add');
    await page.getByRole('textbox', { name: 'name' }).fill('Article');
    await page.getByRole('button', { name: 'Save' }).click();
    await drupal.addPermissions({
      role: 'editor',
      permissions: ['create article content'],
    });
    await drupal.createUser({
      email: `author@example.com`,
      username: 'author',
      password: 'author',
      roles: ['editor'],
    });
    await drupal.logout();
  });

  test('Views block with contextual filter shows correct author in preview', async ({
    page,
    drupal,
    canvas,
  }) => {
    // Create an article by each author.
    await drupal.login({ username: 'author', password: 'author' });
    await page.goto('/node/add/article');
    await page.getByLabel('Title').fill('Article One');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
      page.getByRole('contentinfo', { name: 'Status message' }),
    ).toContainText('Article Article One has been created.');
    await drupal.logout();
    await drupal.login({ username: 'editor', password: 'editor' });
    await page.goto('/node/add/article');
    await page.getByLabel('Title').fill('Article Two');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
      page.getByRole('contentinfo', { name: 'Status message' }),
    ).toContainText('Article Article Two has been created.');

    // Go to Canvas and open templates.
    await canvas.openCanvasRoot();
    await canvas.openTemplatesPanel();

    // Add article template.
    await canvas.addTemplate('Article', 'Full content');

    // Navigate to the article template.
    await page.getByTestId('template-list-item-article-Full content').click();
    expect(page.url()).toContain('canvas/template/node/article/full');

    // Open the library panel and add the Author Display block.
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Author Display' });

    await page.getByTestId('select-content-preview-item').click();
    await page.getByRole('menuitem', { name: 'Article One' }).click();
    const previewFrameOne = await canvas.getActivePreviewFrame();
    await expect(
      previewFrameOne.locator('[data-canvas-uuid] .views-field-name'),
    ).toContainText('author');

    await page.getByTestId('select-content-preview-item').click();
    await page.getByRole('menuitem', { name: 'Article Two' }).click();
    const previewFrameTwo = await canvas.getActivePreviewFrame();
    await expect(
      previewFrameTwo.locator('[data-canvas-uuid] .views-field-name'),
    ).toContainText('editor');
  });
});
