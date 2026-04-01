import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore Bwidth Fitok treehouse
test.describe('Templates - General', () => {
  test.beforeEach(async ({ drupal, page }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules(['canvas_test_sdc']);

    await page.goto('/admin/structure/types/add');
    await page.getByRole('textbox', { name: 'name' }).fill('Article');
    await page.getByRole('button', { name: 'Save' }).click();

    await page.goto('/node/add/article');
    await page.getByLabel('Title').fill('Article One');
    await page.getByRole('link', { name: 'URL alias' }).click();
    await page
      .locator('[data-drupal-selector="edit-path-0-alias"]')
      .fill('/article-one');
    await page.getByRole('link', { name: 'Promotion options' }).click();
    await page.getByLabel('Promoted to front page').check();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
      page.getByRole('contentinfo', { name: 'Status message' }),
    ).toContainText('Article Article One has been created.');

    await drupal.logout();
  });

  test('Add templates to page', async ({ page, drupal, canvas }) => {
    await drupal.loginAsAdmin();
    await page.goto('/admin/structure/types/add');
    await page.getByRole('textbox', { name: 'name' }).fill('Page');
    await page.getByRole('button', { name: 'Save' }).click();
    await drupal.logout();

    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvasRoot();
    await canvas.openTemplatesPanel();

    await expect(
      page.locator('[data-canvas-folder-name="Article"]'),
    ).toBeVisible();
    await expect(page.locator('.primaryPanelContent')).toMatchAriaSnapshot(`
      - button "Add new template":
        - img
      - button "Content types" [expanded]
      - region "Content types"
    `);

    await canvas.addTemplate('Page', 'Full content');
    await page.getByTestId('template-list-item-page-Full content').click();
    expect(page.url()).toContain('canvas/template/node/page/full');
    await expect(
      page.locator('span:has-text("No preview content is available")'),
    ).toBeVisible();
    await expect(
      page.locator(
        'span:has-text("To build a template, you must have at least one Page")',
      ),
    ).toBeVisible();

    await canvas.addTemplate('Article', 'Full content');
    await page.getByTestId('template-list-item-article-Full content').click();
    expect(page.url()).toContain('canvas/template/node/article/full/1');
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });

    const defaultHeading = 'There goes my hero';
    const inputLocator = `[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"] .field--name-heading input`;
    const linkedBoxLocator = '[data-testid="linked-field-box-heading"]';

    await expect(page.locator(inputLocator)).toBeVisible();
    await expect(page.locator(inputLocator)).toHaveValue(defaultHeading);
    await expect(page.locator(linkedBoxLocator)).not.toBeAttached();

    await expect(
      (await canvas.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(defaultHeading);

    await expect(page.getByTestId('select-content-preview-item')).toContainText(
      'Article One',
    );

    // Test the field linker.
    await page
      .locator('xpath=//*[@data-canvas-link-suggestions=\'["Title"]\']')
      .click();
    await page.locator('[data-link-suggestion-option="Title"]').click();
    await expect(page.getByTestId('linked-field-label-heading')).toHaveText(
      'Title',
    );
    await expect(
      (await canvas.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toHaveText('Article One');
    // Confirm that the heading is still linked after making a change to an
    // unlinked field
    await page
      .locator(`[data-drupal-selector$="-subheading-0-value"]`)
      .fill('submarine');
    await expect(
      (await canvas.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] .my-hero__subheading',
      ),
    ).toHaveText('submarine');
    await canvas.publishAllChanges();

    await page.goto('/article-one');
    await expect(page.locator('h1.my-hero__heading')).toHaveText('Article One');
    await expect(page.locator('p.my-hero__subheading')).toHaveText('submarine');
  });

  test('Add teaser template and verify rendering', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvasRoot();
    await page.click('[aria-label="Templates"]');
    await canvas.addTemplate('Article', 'Teaser');

    // Navigate to the teaser template
    await page.getByTestId('template-list-item-article-Teaser').click();
    expect(page.url()).toContain('canvas/template/node/article/teaser');

    // Add Hero component to the teaser template
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });

    // Link heading to Title field
    await page.getByLabel('Link heading to an other field').click();
    await page.getByRole('menuitem', { name: 'Title' }).click();

    // Verify the linked field box appears
    await expect(page.getByTestId('linked-field-box-heading')).toBeVisible();

    // Publish changes
    await canvas.publishAllChanges();

    // Visit the frontpage (/node) which displays articles as teasers
    await page.goto('/node');

    // Verify the Hero component renders with article title.
    await expect(page.locator('.my-hero__heading')).toBeVisible();
    await expect(page.locator('.my-hero__heading')).toHaveCount(1);
  });
});
