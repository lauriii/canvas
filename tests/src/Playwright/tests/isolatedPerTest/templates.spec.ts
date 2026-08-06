import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore Bwidth Fitok treehouse Artículo
test.use({
  modules: ['canvas_test_sdc'],
  enableTestExtensions: true,
});

test.describe('Templates - General', () => {
  test.beforeEach(async ({ drupal, page }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/article_translation`,
    );
    await drupal.installModules([
      'canvas_test_article_fields',
      'config_translation',
    ]);
    await drupal.addPermissions({
      role: 'editor',
      permissions: [
        'use editorial transition create_new_draft',
        'use editorial transition publish',
        'use editorial transition archive',
        'edit any article content',
        'translate configuration',
      ],
    });
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
    await canvas.publishAllChanges();

    const editorUrl = page.url();

    //Translate the content template into French via the config translation.
    await drupal.logout();
    await drupal.loginAsAdmin();
    await page.goto(
      '/admin/structure/content-template/node.article.full/translate/fr/add',
    );
    await page
      .locator('summary', { hasText: 'Component at position 0' })
      .click();
    await page
      .locator('input[name^="translation"][name*="[heading]"]')
      .first()
      .fill('Hero in french');
    await page.getByRole('button', { name: 'Save translation' }).click();
    await expect(page.locator('[data-drupal-messages]')).toContainText(
      'Successfully saved French translation.',
    );
    await page.goto(editorUrl);
    await canvas.waitForEditorFrame();

    const languageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"]',
    );
    await languageButton.click();

    // English (default) and French (config override) both carry a tick mark;
    // Spanish has no config translation so it does not.
    await expect(
      page.locator(
        '[data-testid="language-option-en"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveAttribute('data-canvas-has-translation', 'true');
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveAttribute('data-canvas-has-translation', 'true');
    await expect(
      page.locator(
        '[data-testid="language-option-es"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);

    // The three-dots trigger appears only for French (the only config translation).
    await expect(
      page.locator('[aria-label="More options for French"]'),
    ).toBeVisible();
    await expect(
      page.locator('[data-testid="language-options-popover-trigger"]'),
    ).toHaveCount(1);

    const frenchOption = page.locator('[data-testid="language-option-fr"]');
    await frenchOption.click();
    await page.waitForURL(
      /\/preview\/template\/node\/article\/\d+\/full\/full\?language=fr/,
      { timeout: 10000 },
    );
    await expect(
      page
        .locator('iframe[class^="_PagePreviewIframe"]')
        .contentFrame()
        .locator('[data-component-id="canvas_test_sdc:my-hero"] h1'),
    ).toHaveText('Hero in french');

    await languageButton.click();
    await page.locator('[data-testid="language-option-en"]').click();
    await page.waitForURL(/\/canvas\/template\/node\/article\/full\/\d+/, {
      timeout: 10000,
    });
    await canvas.waitForEditorFrame();

    // Delete the French config translation via the in-app three-dots popover.
    await page.keyboard.press('Escape');
    await expect(
      page.locator('[data-state="open"][role="menu"]'),
    ).not.toBeAttached();
    const closedLanguageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"][data-state="closed"]',
    );
    await closedLanguageButton.click();
    await page
      .locator('[aria-label="More options for French"]')
      .first()
      .click();

    const frenchPopover = page
      .locator('[data-testid="language-options-popover"]')
      .first();
    await expect(frenchPopover).toBeVisible();
    await expect(
      page.locator('[data-testid="language-options-popover-title"]').first(),
    ).toContainText('French');
    // The popover title must show the template caption (e.g.
    // "Article - Full content template").
    await expect(
      page.locator('[data-testid="language-options-popover-title"]').first(),
    ).toContainText('Article - Full content template');
    await expect(
      page.locator('[data-testid="language-options-delete"]').first(),
    ).toBeVisible();

    // Deleting opens a confirmation dialog – no new tab should open.
    let popupOpened = false;
    page.on('popup', () => {
      popupOpened = true;
    });
    await page
      .locator('[data-testid="language-options-delete"]')
      .first()
      .click();
    expect(popupOpened).toBe(false);

    const deleteDialog = page
      .locator('[role="dialog"][data-state="open"]')
      .filter({
        has: page.locator('h1', { hasText: 'Delete translation' }),
      })
      .first();
    await expect(deleteDialog).toBeVisible();
    const confirmButton = deleteDialog.getByRole('button', {
      name: 'Delete Translation',
    });
    await deleteDialog
      .locator('[data-testid="delete-translation-confirm-input"]')
      .fill('DELETE');
    await expect(confirmButton).toBeEnabled();
    await confirmButton.click();
    await expect(deleteDialog).toBeHidden();
    // Before confirming elements in the dialog do not exist, confirm the dialog
    // itself is still open by asserting an element it contains.
    await expect(
      page.locator('[data-testid="language-option-en"]'),
    ).toBeAttached();
    // After deletion the three-dots trigger disappears for French.
    await expect(
      page.locator('[aria-label="More options for French"]'),
    ).toHaveCount(0);
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);
    await expect(
      page.locator('[data-testid="language-options-popover-trigger"]'),
    ).toHaveCount(0);
    await frenchOption.click();
    await page.waitForURL(
      /\/preview\/template\/node\/article\/\d+\/full\/full\?language=fr/,
      { timeout: 10000 },
    );
    await expect(
      page
        .locator('iframe[class^="_PagePreviewIframe"]')
        .contentFrame()
        .locator('[data-component-id="canvas_test_sdc:my-hero"] h1'),
    ).toHaveText('There goes my hero');

    // Return to English editor to continue building the template.
    await page
      .locator('[data-testid="canvas-topbar"]')
      .getByRole('button', { name: 'Exit Preview' })
      .click();
    await page.waitForURL(/\/canvas\/template\/node\/article\/full\/\d+/, {
      timeout: 10000,
    });
    await canvas.waitForEditorFrame();
    await canvas.clickPreviewComponent('sdc.canvas_test_sdc.my-hero');

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
    await canvas.testInPreviewFrame(
      '[data-component-id="canvas_test_sdc:my-hero"] h1',
      async (h1) => {
        await expect(h1).toContainText('Article One');
      },
    );
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
    // Open the full-page preview and verify the template renders correctly.
    await canvas.openPreview();
    const previewFrame = page
      .locator('iframe[class^="_PagePreviewIframe"]')
      .contentFrame();
    await expect(
      previewFrame.locator('[data-component-id="canvas_test_sdc:my-hero"] h1'),
    ).toHaveText('Article One');
    await expect(
      previewFrame.locator(
        '[data-component-id="canvas_test_sdc:my-hero"] .my-hero__subheading',
      ),
    ).toHaveText('submarine');

    // Switch to the Spanish translation via the language selector.
    await expect(languageButton).toBeVisible();
    await languageButton.click();

    // Verify translation indicators: only English gets a tick mark here because
    // the French config translation was already deleted in the block above.
    await expect(
      page.locator(
        '[data-testid="language-option-en"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveAttribute('data-canvas-has-translation', 'true');
    await expect(
      page.locator(
        '[data-testid="language-option-es"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);

    const spanishOption = page.locator('[data-testid="language-option-es"]');
    await expect(spanishOption).toBeVisible();
    await spanishOption.click();

    await page.waitForURL(
      /\/preview\/template\/node\/article\/\d+\/full\/full\?language=es/,
      {
        timeout: 10000,
      },
    );
    expect(page.url()).toMatch(
      /\/preview\/template\/node\/article\/\d+\/full\/full\?language=es/,
    );

    // Verify the Spanish translation title is shown in the preview.
    await expect(
      page
        .locator('iframe[class^="_PagePreviewIframe"]')
        .contentFrame()
        .locator('[data-component-id="canvas_test_sdc:my-hero"] h1'),
    ).toHaveText('Artículo Uno');

    // Verify the template caption is shown in navigation on preview routes.
    await expect(
      page.locator('[data-testid="canvas-navigation-button"]'),
    ).toHaveText('Article - Full content template');

    await page.reload();
    // Verify the Spanish translation title after reload.
    await expect(
      page
        .locator('iframe[class^="_PagePreviewIframe"]')
        .contentFrame()
        .locator('[data-component-id="canvas_test_sdc:my-hero"] h1'),
    ).toHaveText('Artículo Uno');

    // Verify the template caption is shown in navigation after reload.
    await expect(
      page.locator('[data-testid="canvas-navigation-button"]'),
    ).toHaveText('Article - Full content template');

    // Verify that translation indicators survive a page reload on the preview
    // route.
    await languageButton.click();
    // English is the only config translation at this point: the French config
    // translation was deleted earlier in this test, Spanish never had one.
    await expect(
      page.locator(
        '[data-testid="language-option-en"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveAttribute('data-canvas-has-translation', 'true');
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);
    await expect(
      page.locator(
        '[data-testid="language-option-es"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);
    await expect(
      page.locator('[data-testid="language-options-popover-trigger"]'),
    ).toHaveCount(0);

    // Switch back to English (default) which returns to the editor.
    await page.locator('[data-testid="language-option-en"]').click();
    await page.waitForURL(/\/canvas\/template\/node\/article\/full\/\d+/, {
      timeout: 10000,
    });
    await expect(
      page.locator('iframe[title="Page preview"]'),
    ).not.toBeAttached();
    await canvas.waitForEditorFrame();

    await canvas.publishAllChanges();

    await page.goto('/article-one');
    await expect(page.locator('h1.my-hero__heading')).toHaveText('Article One');
    await expect(page.locator('p.my-hero__subheading')).toHaveText('submarine');
  });

  test('Preview reflects edits made after previewing a content template', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvasRoot();
    await canvas.openTemplatesPanel();

    // Build an Article "Full content" template with a hero component.
    await canvas.addTemplate('Article', 'Full content');
    await page.getByTestId('template-list-item-article-Full content').click();
    expect(page.url()).toContain('canvas/template/node/article/full/1');
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });
    await canvas.publishAllChanges();

    // Open the full-page preview and return to the editor. This seeds a preview
    // snapshot that must not mask subsequent edits.
    await canvas.openPreview();
    await page
      .locator('[data-testid="canvas-topbar"]')
      .getByRole('button', { name: 'Exit Preview' })
      .click();
    await page.waitForURL(/\/canvas\/template\/node\/article\/full\/\d+/, {
      timeout: 10000,
    });
    await canvas.waitForEditorFrame();

    // Link the hero heading to the entity Title. The preview must reflect the
    // resolved value, not the stale snapshot captured by the preview above.
    await canvas.clickPreviewComponent('sdc.canvas_test_sdc.my-hero');
    await page
      .locator('xpath=//*[@data-canvas-link-suggestions=\'["Title"]\']')
      .click();
    await page.locator('[data-link-suggestion-option="Title"]').click();
    await expect(page.getByTestId('linked-field-label-heading')).toHaveText(
      'Title',
    );
    await canvas.testInPreviewFrame(
      '[data-component-id="canvas_test_sdc:my-hero"] h1',
      async (h1) => {
        await expect(h1).toContainText('Article One');
      },
    );
  });

  test('Add teaser template and verify rendering', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvasRoot();
    await page.locator('[aria-label="Templates"]').click();
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

    // The preview iframe must be as tall as its content. View modes other than
    // "full" have their height driven entirely by their content, so a
    // regression there collapses the iframe and renders the preview invisible
    // while the preview document itself stays correct.
    // @see useSyncIframeHeightToContent()
    await expect(async () => {
      const { outer, inner } = await page
        .locator(
          '[data-testid="canvas-editor-frame-scaling"] iframe[data-test-canvas-content-initialized="true"][data-canvas-swap-active="true"]',
        )
        .evaluate((iframe: HTMLIFrameElement) => ({
          outer: iframe.clientHeight,
          inner: iframe.contentDocument?.documentElement.offsetHeight ?? 0,
        }));
      expect(inner).toBeGreaterThan(0);
      expect(outer).toBeGreaterThanOrEqual(inner);
    }).toPass();

    // Publish changes
    await canvas.publishAllChanges();

    // Visit the frontpage (/node) which displays articles as teasers
    await page.goto('/node');

    // Verify the Hero component renders with article title.
    await expect(page.locator('.my-hero__heading')).toBeVisible();
    await expect(page.locator('.my-hero__heading')).toHaveCount(1);
  });
});

test.describe('Templates - Preview content updates across entity types', () => {
  test.beforeEach(async ({ drupal, page }) => {
    await drupal.loginAsAdmin();
    // Create a "Page" content type so a content template can be created.
    await page.goto('/admin/structure/types/add');
    await page.getByRole('textbox', { name: 'name' }).fill('Page');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
      page.getByRole('contentinfo', { name: 'Status message' }),
    ).toContainText('The content type Page has been added.');
    // A content template needs a preview entity.
    await page.goto('/node/add/page');
    await page.getByLabel('Title').fill('Page One');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
      page.getByRole('contentinfo', { name: 'Status message' }),
    ).toContainText('Page One has been created.');
  });

  test('Preview updates when navigating from a content template preview to a canvas_page', async ({
    page,
    canvas,
  }) => {
    // Create a canvas_page with a Hero using a distinct heading.
    await canvas.createCanvas({ title: 'Test Page' });
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Hero' });
    await page.locator('.field--name-heading input').fill('Canvas page hero');
    await expect(
      (await canvas.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText('Canvas page hero');

    // Set up a Page content template with a Hero using a different heading.
    await canvas.openCanvasRoot();
    await canvas.openTemplatesPanel();
    await canvas.addTemplate('Page', 'Full content');
    await page.getByTestId('template-list-item-page-Full content').click();
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });
    await page
      .locator('.field--name-heading input')
      .fill('Content template hero');
    await expect(
      (await canvas.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText('Content template hero');

    // Open the content template full-page preview.
    await canvas.openPreview();
    const templatePreviewFrame = page
      .locator('iframe[class^="_PagePreviewIframe"]')
      .contentFrame();
    await expect(
      templatePreviewFrame.locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText('Content template hero');

    // While still in the content template preview, navigate to the canvas_page
    // via the page switcher. This navigates to the canvas_page editor.
    await page.getByTestId('canvas-navigation-button').click();
    const navigationResults = page.locator(
      '[data-testid="canvas-navigation-results"]',
    );
    await expect(navigationResults).toBeVisible();
    await navigationResults.locator('text=Test Page').click();
    await page.waitForURL(/\/canvas\/editor\/canvas_page\//);

    // The editor's inline preview must show the canvas_page content, not the
    // stale content template preview.
    await expect(
      (await canvas.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText('Canvas page hero');
  });

  test('Changing preview width in a content template preview keeps the template route and does not lock the editor', async ({
    page,
    canvas,
  }) => {
    // Set up a Page content template with a Hero component.
    await canvas.openCanvasRoot();
    await canvas.openTemplatesPanel();
    await canvas.addTemplate('Page', 'Full content');
    await page.getByTestId('template-list-item-page-Full content').click();
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });

    // Open the content template full-page preview.
    await canvas.openPreview();
    const templatePreviewFrame = page
      .locator('iframe[class^="_PagePreviewIframe"]')
      .contentFrame();
    await expect(
      templatePreviewFrame.locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toBeVisible();

    // Change the preview width.
    await page.getByRole('button', { name: 'Select preview width' }).click();
    await page.getByRole('menuitemradio', { name: 'Tablet (1024px)' }).click();

    // The width selector must keep the content template preview route rather
    // than navigating to the generic /preview/{entityType}/{entityId} route.
    await page.waitForURL(
      /\/canvas\/preview\/template\/node\/page\/[^/]+\/full\/tablet/,
    );
    await expect(page.locator('iframe[title="Page preview"]')).toHaveCSS(
      'width',
      '1024px',
    );

    // Exit preview: the editor must return to the template editor, not lock up.
    await page
      .locator('[data-testid="canvas-topbar"]')
      .getByRole('button', { name: 'Exit Preview' })
      .click();
    await page.waitForURL(/\/canvas\/template\/node\/page\/full/);
    await canvas.waitForCanvasSideMenu();
    await expect(
      page.getByText(
        'For now Canvas only works if the entity is a canvas_page',
      ),
    ).toHaveCount(0);
  });
});
