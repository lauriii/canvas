import { getModuleDir } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Views contextual filter in Canvas preview', () => {
  test.beforeAll(
    'Setup test site with Views contextual filter',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.enableTestExtensions();
      await drupal.writeBaseUrl();
      const moduleDir = await getModuleDir();

      // Apply base recipe for Canvas setup.
      await drupal.applyRecipe(
        `${moduleDir}/canvas/tests/fixtures/recipes/base`,
      );

      // Install the test module (Views config auto-imported from config/install).
      await drupal.installModules(['canvas_test_views_contextual']);

      // Create test users.
      await drupal.drush(
        'user:create TestAuthorOne --password=test --mail=author1@test.com',
      );
      await drupal.drush(
        'user:create TestAuthorTwo --password=test --mail=author2@test.com',
      );

      // Create articles by each author using php-eval.
      await drupal.drush(
        `php-eval "\\Drupal\\node\\Entity\\Node::create(['type' => 'article', 'title' => 'Article by Author One', 'uid' => reset(\\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'TestAuthorOne']))->id(), 'status' => 1])->save();"`,
      );
      await drupal.drush(
        `php-eval "\\Drupal\\node\\Entity\\Node::create(['type' => 'article', 'title' => 'Article by Author Two', 'uid' => reset(\\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'TestAuthorTwo']))->id(), 'status' => 1])->save();"`,
      );

      await page.close();
    },
  );

  test('Views block with contextual filter shows correct author in preview', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    // Login as admin.
    await drupal.loginAsAdmin();

    // Go to Canvas and open templates.
    await canvasEditor.goToCanvasRoot();
    await page.click('[aria-label="Templates"]');
    await expect(page.getByTestId('big-add-template-button')).toBeVisible();

    // Add article template.
    await page.getByTestId('big-add-template-button').click();
    await expect(
      page.getByTestId('canvas-manage-library-add-template-content'),
    ).toBeVisible();
    await page.locator('#content-type').click();
    await expect(page.getByRole('option', { name: 'Article' })).toBeVisible();
    await page.getByRole('option', { name: 'Article' }).click();
    await page.locator('#template-name').click();
    await expect(
      page.getByRole('option', { name: 'Full content' }),
    ).toBeVisible();
    await page.getByRole('option', { name: 'Full content' }).click();

    await expect(
      page
        .getByRole('dialog')
        .getByRole('button', { name: 'Add new template' }),
    ).not.toBeDisabled();
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Add new template' })
      .click();
    // The dialog should close after adding a template.
    await expect(
      page.getByTestId('canvas-manage-library-add-template-content'),
    ).not.toBeVisible();

    // Navigate to the article template.
    await page.getByTestId('template-list-item-article-Full content').click();
    expect(page.url()).toContain('canvas/template/node/article/full');

    // Open the library panel and add the Author Display block.
    await canvasEditor.openLibraryPanel();

    // Search for the Author Display block.
    await page.getByPlaceholder('Search').fill('Author Display');

    // Wait for search results and find the block.
    const authorDisplayBlock = page
      .getByTestId('canvas-primary-panel')
      .locator(
        '[data-canvas-type="component"][data-canvas-component-id="block.views_block.canvas_test_author_display-block_1"]',
      );
    await expect(authorDisplayBlock).toBeVisible();

    // Add the component.
    await authorDisplayBlock.hover();
    const contextualMenuButton = authorDisplayBlock.getByLabel(
      'Open contextual menu',
    );
    await contextualMenuButton.click();
    await page.getByText('Insert').click();

    // Wait for the contextual menu to close (indicating the insert was processed).
    await expect(page.getByRole('menu')).not.toBeVisible();

    // Wait for the preview to update and get the preview frame.
    const previewFrame = await canvasEditor.getActivePreviewFrame();

    // Get current preview entity title to determine the order.
    const currentEntityTitle = await page
      .getByTestId('select-content-preview-item')
      .textContent();

    // Determine which article is currently being previewed.
    let firstAuthor: string;
    let secondArticleTitle: string;
    let secondAuthor: string;

    if (currentEntityTitle?.includes('Article by Author One')) {
      firstAuthor = 'TestAuthorOne';
      secondArticleTitle = 'Article by Author Two';
      secondAuthor = 'TestAuthorTwo';
    } else {
      firstAuthor = 'TestAuthorTwo';
      secondArticleTitle = 'Article by Author One';
      secondAuthor = 'TestAuthorOne';
    }

    // The Views block renders within the main content area.
    // We look for the author name text within the main element.
    // The View output contains just the username text.
    // Use a longer timeout since the preview needs to reload after inserting the component.
    await expect(previewFrame.locator('main')).toContainText(firstAuthor, {
      timeout: 15000,
    });

    // Switch preview entity to the other article.
    await page.getByTestId('select-content-preview-item').click();
    await page.getByRole('menuitem', { name: secondArticleTitle }).click();

    // Verify the author name updates to the second article's author.
    // Wait for the new preview to load and verify the author changed.
    await expect(previewFrame.locator('main')).toContainText(secondAuthor);
    // Also verify the first author is no longer shown (ensuring the switch happened).
    await expect(previewFrame.locator('main')).not.toContainText(firstAuthor);
  });
});
