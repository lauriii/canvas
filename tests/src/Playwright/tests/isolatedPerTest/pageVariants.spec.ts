import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

/**
 * Tests the page variant editing flow.
 *
 * Page variants are called "page templates" in the UI. Covers creating one in
 * the Templates panel's "Page templates" section, opening it in the editor by clicking it, the
 * "Page content" marker placeholder and its delete protection, editing the
 * variant's tree, publishing, and selecting the variant for a page through
 * the Page data form's collapsed "Page template" section.
 */

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

/**
 * Creates a page variant labeled "Marketing" through the Templates panel.
 */
async function createMarketingVariant(page: Page) {
  await page
    .getByTestId('canvas-side-menu')
    .getByRole('button', { name: 'Templates' })
    .click();
  await page.getByTestId('canvas-page-variant-new-button').click();
  await page.getByTestId('canvas-page-variant-label-input').fill('Marketing');
  await page.getByRole('button', { name: 'Create template' }).click();
  await expect(page.getByTestId('canvas-page-variant-marketing')).toBeVisible();
}

test.describe('Page variants', () => {
  test('create, edit, and publish a page variant', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await createMarketingVariant(page);

    // Clicking the variant opens its tree in the editor. The contextual panel
    // stays hidden until a component is selected (variants have no page data),
    // so wait for the editor frame only.
    await page.getByTestId('canvas-page-variant-marketing').click();
    await expect(page).toHaveURL(/\/canvas\/editor\/page_variant\/marketing/);
    await canvas.waitForEditorFrame();

    // The "Page content" marker renders as a visible placeholder. Locate the
    // preview frame directly: testInPreviewFrame() waits for the contextual
    // panel, which variants only show once a component is selected.
    const previewFrame = page
      .locator(
        '[data-testid="canvas-editor-frame-scaling"] iframe[data-test-canvas-content-initialized="true"][data-canvas-swap-active="true"]',
      )
      .contentFrame();
    await expect(
      previewFrame.locator('.canvas--page-content-marker-placeholder'),
    ).toBeAttached();

    // The marker can only be repositioned: its menu offers no Delete,
    // Duplicate, or Copy.
    await canvas.openLayersPanel();
    const markerRow = page.getByRole('treeitem', { name: /Page content/ });
    // The menu trigger only becomes visible when the row is hovered.
    await markerRow.hover();
    await markerRow.getByLabel('Open contextual menu').click();
    const menu = page.getByRole('menu');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Delete' })).toHaveCount(0);
    await expect(menu.getByRole('menuitem', { name: 'Duplicate' })).toHaveCount(
      0,
    );
    await page.keyboard.press('Escape');

    // Edit the variant: add a component next to the marker, then publish.
    await canvas.openLibraryPanel();
    await canvas.addComponent(
      { id: 'sdc.canvas_test_sdc.heading' },
      { hasInputs: true },
    );
    await canvas.publishAllChanges(['Marketing']);
  });

  test('disable and enable a page variant', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas({ title: 'Disable host' });
    await canvas.openCanvas(canvasPage);
    await createMarketingVariant(page);

    const row = page.getByTestId('canvas-page-variant-marketing');
    const openRowMenu = async () => {
      await row.hover();
      await row.getByLabel('Open contextual menu').click();
      const menu = page.getByRole('menu');
      await expect(menu).toBeVisible();
      return menu;
    };
    const patched = () =>
      page.waitForResponse(
        (response) =>
          response
            .url()
            .includes('/canvas/api/v0/config/page_variant/marketing') &&
          response.request().method() === 'PATCH',
      );

    // Disable the variant from its row menu; the row shows a badge.
    let menu = await openRowMenu();
    let patch = patched();
    await menu.getByRole('menuitem', { name: 'Disable' }).click();
    await patch;
    await expect(row.getByText('Disabled')).toBeVisible();

    // A disabled variant cannot be selected for a page: it is omitted from
    // the Page data form's "Page template" options.
    await canvas.openCanvas(canvasPage);
    const pageDataForm = page.getByTestId('canvas-page-data-form');
    await pageDataForm
      .locator('button')
      .filter({ hasText: 'Page template' })
      .click();
    const variantSelect = pageDataForm.getByLabel('Page template');
    await expect(variantSelect).toBeVisible();
    await expect(
      variantSelect.locator('option', { hasText: 'Marketing' }),
    ).toHaveCount(0);

    // Re-enabling makes the variant selectable again. Open another panel
    // first: the
    // templates list can get stuck loading when it mounts with an already
    // fulfilled variants cache (a pre-existing panel bug); remounting it
    // through a toggle reliably shows the list.
    await page
      .getByTestId('canvas-side-menu')
      .getByRole('button', { name: 'Templates' })
      .click();
    await page
      .getByTestId('canvas-side-menu')
      .getByRole('button', { name: 'Pages' })
      .click();
    await page
      .getByTestId('canvas-side-menu')
      .getByRole('button', { name: 'Templates' })
      .click();
    await expect(row).toBeVisible();
    menu = await openRowMenu();
    patch = patched();
    await menu.getByRole('menuitem', { name: 'Enable' }).click();
    await patch;
    await expect(row.getByText('Disabled')).toHaveCount(0);
    await canvas.openCanvas(canvasPage);
    await pageDataForm
      .locator('button')
      .filter({ hasText: 'Page template' })
      .click();
    await expect(variantSelect).toBeVisible();
    await expect(
      variantSelect.locator('option', { hasText: 'Marketing' }),
    ).toHaveCount(1);
  });

  test('select a page variant for a page', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas({ title: 'Variant host' });
    await canvas.openCanvas(canvasPage);
    await createMarketingVariant(page);
    // Reload the page editor so the Page data form's variant options include
    // the variant that was just created.
    await canvas.openCanvas(canvasPage);

    // Select the variant inside the Page data form's collapsed "Page
    // template" section.
    const pageDataForm = page.getByTestId('canvas-page-data-form');
    // Element locator, not getByRole: the Drupal summary inside the trigger
    // also exposes role=button with the same name.
    await pageDataForm
      .locator('button')
      .filter({ hasText: 'Page template' })
      .click();
    const variantSelect = pageDataForm.getByLabel('Page template');
    await expect(variantSelect).toBeVisible();
    // Wait for the auto-save POST that actually carries the new selection:
    // preview posts queue, so an earlier in-flight POST (without the value)
    // must not satisfy the wait.
    const autoSave = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
        response.request().method() === 'POST' &&
        (response.request().postData() ?? '').includes('marketing'),
    );
    await variantSelect.selectOption({ label: 'Marketing' });
    await autoSave;

    // After a reload, the layout reports the resolved variant and the Layers
    // panel offers to jump to editing it.
    await page.reload();
    await canvas.waitForEditorUi();
    await canvas.openLayersPanel();
    const variantLayer = page.getByTestId('canvas-page-variant-layer');
    const variantRow = variantLayer.getByText('Marketing');
    await expect(variantRow).toBeVisible();
    await variantRow.click();
    await expect(page).toHaveURL(/\/canvas\/editor\/page_variant\/marketing/);
  });
});
