import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

/**
 * Tests the page variant editing flow.
 *
 * Covers creating a variant in the Page variants panel, opening it in the
 * editor by clicking it, the "Page content" marker placeholder and its delete
 * protection, editing the variant's tree, publishing, and selecting the
 * variant for a page through the Page data select widget.
 */

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

/**
 * Creates a page variant labeled "Marketing" through the Page variants panel.
 */
async function createMarketingVariant(page: Page) {
  await page
    .getByTestId('canvas-side-menu')
    .getByRole('button', { name: 'Page variants' })
    .click();
  await page.getByTestId('canvas-page-variant-new-button').click();
  await page.getByTestId('canvas-page-variant-label-input').fill('Marketing');
  await page.getByRole('button', { name: 'Create variant' }).click();
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

  test('select a page variant for a page', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas({ title: 'Variant host' });
    await canvas.openCanvas(canvasPage);
    await createMarketingVariant(page);
    // Reload the page editor so the Page data form's variant options include
    // the variant that was just created.
    await canvas.openCanvas(canvasPage);

    // Select the variant for the page with the Page data select widget.
    const variantSelect = page
      .getByTestId('canvas-page-data-form')
      .getByLabel('Page variant');
    await expect(variantSelect).toBeVisible();
    const autoSave = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
        response.request().method() === 'POST',
    );
    await variantSelect.selectOption({ label: 'Marketing' });
    await autoSave;

    // After a reload, the layout reports the resolved variant and the topbar
    // offers to jump to editing it.
    await page.reload();
    await canvas.waitForEditorUi();
    const jumpButton = page.getByTestId('canvas-page-variant-jump');
    await expect(jumpButton).toHaveText('Marketing');
    await jumpButton.click();
    await expect(page).toHaveURL(/\/canvas\/editor\/page_variant\/marketing/);
  });
});
