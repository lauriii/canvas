import { getModuleDir } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

// cspell:ignore Bwidth Fitok treehouse
test.describe('Templates - General', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      await page.close();
    },
  );
  test('Template - add templates to page', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await canvasEditor.goToCanvasRoot();
    await page.click('[aria-label="Templates"]');
    await expect(page.getByTestId('big-add-template-button')).toBeVisible();
    await expect(
      page.locator('[data-canvas-folder-name="Article"]'),
    ).toBeVisible();

    await expect(page.locator('.primaryPanelContent')).toMatchAriaSnapshot(`
      - button "Add new template":
        - img
      - button "Content types" [expanded]
      - region "Content types"
    `);

    const addTemplate = async (bundle: string) => {
      await page.getByTestId('big-add-template-button').click();
      await expect(
        page.getByTestId('canvas-manage-library-add-template-content'),
      ).toBeVisible();
      await page.locator('#content-type').click();
      await expect(page.getByRole('option', { name: bundle })).toBeVisible();
      await page.getByRole('option', { name: bundle }).click();
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
      // The dialog should close after adding a template
      await expect(
        page.getByTestId('canvas-manage-library-add-template-content'),
      ).not.toBeVisible();
    };

    await addTemplate('Basic page');
    await page.getByTestId('template-list-item-page-Full content').click();
    expect(page.url()).toContain('canvas/template/node/page/full');
    await expect(
      page.locator('span:has-text("No preview content is available")'),
    ).toBeVisible();
    await expect(
      page.locator(
        'span:has-text("To build a template, you must have at least one Basic page")',
      ),
    ).toBeVisible();

    await addTemplate('Article');
    await page.getByTestId('template-list-item-article-Full content').click();
    expect(page.url()).toContain('canvas/template/node/article/full/1');
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });
    const currentEntityTitle = await page
      .getByTestId('select-content-preview-item')
      .textContent();
    let nodeTitle = 'Canvas With a block in the layout';
    let newArticleTitle = 'Canvas Needs This For The Time Being';
    // Account for unreliable entity ids.
    if (currentEntityTitle !== nodeTitle) {
      newArticleTitle = nodeTitle;
      nodeTitle = currentEntityTitle;
    }
    const defaultHeading = 'There goes my hero';
    const inputLocator = `[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"] .field--name-heading input`;
    const linkedBoxLocator = '[data-testid="linked-field-box-heading"]';

    await expect(page.locator(inputLocator)).toBeVisible();
    await expect(page.locator(inputLocator)).toHaveValue(defaultHeading);
    await expect(page.locator(linkedBoxLocator)).not.toBeAttached();

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(defaultHeading);

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).not.toContainText(nodeTitle);

    await expect(page.getByTestId('select-content-preview-item')).toContainText(
      nodeTitle,
    );
    await page.getByLabel('Link heading to an other field').click();
    await page.getByRole('menuitem', { name: 'Title' }).click();

    await expect(page.locator(inputLocator)).not.toBeAttached();
    await expect(page.locator(linkedBoxLocator)).toBeVisible();

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(nodeTitle);

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).not.toContainText(defaultHeading);

    await canvasEditor.editComponentProp('subheading', 'submarine');

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '.my-hero__subheading',
      ),
    ).toContainText('submarine');

    // Add a fixed timeout to allow for any problems with the prop linker to
    // occur - they don't necessarily happen immediately.
    await new Promise((resolve) => setTimeout(resolve, 1000));
    await expect(page.locator(inputLocator)).not.toBeAttached();
    await expect(page.locator(linkedBoxLocator)).toBeVisible();
    // Confirm that the heading is still linked after making a change to an
    // unlinked field
    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(nodeTitle);

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).not.toContainText(defaultHeading);

    await page.getByTestId('select-content-preview-item').click();
    await page.getByRole('menuitem', { name: newArticleTitle }).click();

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(newArticleTitle);

    expect(page.url()).toContain('canvas/template/node/article/full/2');
    await canvasEditor.clickPreviewComponent('sdc.canvas_test_sdc.my-hero');
    await expect(page.locator(linkedBoxLocator)).toBeVisible();
    await expect(page.locator(inputLocator)).not.toBeAttached();
    await canvasEditor.publishAllChanges();
    await page.goto('/node/1');
    await expect(
      page.locator(`.my-hero__heading:has-text("${nodeTitle}")`),
    ).toBeVisible();
    await expect(
      page.locator(`.my-hero__subheading:has-text("submarine")`),
    ).toBeVisible();
    await page.goto('/node/2');
    await expect(
      page.locator(`.my-hero__heading:has-text("${newArticleTitle}")`),
    ).toBeVisible();
    await expect(
      page.locator(`.my-hero__subheading:has-text("submarine")`),
    ).toBeVisible();
  });

  test('Template - add teaser template and verify rendering', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    const moduleDir = await getModuleDir();
    // Install Views and frontpage view to show teasers at /node
    await drupal.applyRecipe(
      `${moduleDir}/canvas/tests/fixtures/recipes/frontpage_view`,
    );
    // @todo Rebuilding caches should not be needed after applying a recipe. But
    //   the /node route the view we just installed is giving 404 sometimes
    //   making this a flaky test.
    await drupal.drush('cr');

    await drupal.loginAsAdmin();
    await canvasEditor.goToCanvasRoot();
    await page.click('[aria-label="Templates"]');
    await expect(page.getByTestId('big-add-template-button')).toBeVisible();

    // Click "Add new template" button
    await page.getByTestId('big-add-template-button').click();
    await expect(
      page.getByTestId('canvas-manage-library-add-template-content'),
    ).toBeVisible();

    // Select Article content type
    await page.locator('#content-type').click();
    await page.getByRole('option', { name: 'Article' }).click();

    // Open the template/view mode dropdown and verify multiple modes available
    await page.locator('#template-name').click();
    await expect(
      page.getByRole('option', { name: 'Full content' }),
    ).toBeVisible();
    await expect(page.getByRole('option', { name: 'Teaser' })).toBeVisible();

    // Select Teaser view mode
    await page.getByRole('option', { name: 'Teaser' }).click();

    // Create the template
    await expect(
      page
        .getByRole('dialog')
        .getByRole('button', { name: 'Add new template' }),
    ).not.toBeDisabled();
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Add new template' })
      .click();

    // Dialog should close
    await expect(
      page.getByTestId('canvas-manage-library-add-template-content'),
    ).not.toBeVisible();

    // Navigate to the teaser template
    await page.getByTestId('template-list-item-article-Teaser').click();
    expect(page.url()).toContain('canvas/template/node/article/teaser');

    // Add Hero component to the teaser template
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });

    // Link heading to Title field
    await page.getByLabel('Link heading to an other field').click();
    await page.getByRole('menuitem', { name: 'Title' }).click();

    // Verify the linked field box appears
    await expect(page.getByTestId('linked-field-box-heading')).toBeVisible();

    // Publish changes
    await canvasEditor.publishAllChanges();

    // Visit the frontpage (/node) which displays articles as teasers
    await page.goto('/node');

    // Verify the Hero component renders with article titles
    // The test site has multiple articles that should all render with the Hero component
    await expect(page.locator('.my-hero__heading').first()).toBeVisible();
    // Verify multiple articles are rendered with the teaser template
    await expect(page.locator('.my-hero__heading')).toHaveCount(3);
  });
});
