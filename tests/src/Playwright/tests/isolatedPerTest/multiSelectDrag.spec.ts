import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests moving a multi-selection with drag and drop.
 */

test.use({ modules: ['canvas_test_sdc'] });

test.describe('Multi-select drag and drop', () => {
  test('Dragging a selection member moves the whole selection', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());

    // Add three components at the root level.
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.heading' });
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.heading' });
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.heading' });

    await canvas.openLayersPanel();
    const layerItems = page.locator(
      '[data-testid="canvas-primary-panel"] [data-canvas-type="component"]',
    );
    await expect(layerItems).toHaveCount(3);
    const uuids = await layerItems.evaluateAll((elements) =>
      elements.map((element) => element.getAttribute('data-canvas-uuid')),
    );

    // Select the first two components: click, then meta+click.
    await layerItems.nth(0).click();
    await layerItems.nth(1).click({ modifiers: ['Meta'] });
    await expect(
      page.locator('[data-testid="canvas-contextual-panel"]'),
    ).toContainText('2 items selected');

    // Drag the first selected component onto the drop zone below the third
    // component. The whole selection must move, not just the dragged item.
    await canvas.drag(
      layerItems.nth(0),
      `[data-testid="canvas-primary-panel"] [data-canvas-uuid="${uuids[2]}"] [class*="ropZone"]`,
    );

    await expect(async () => {
      const order = await layerItems.evaluateAll((elements) =>
        elements.map((element) => element.getAttribute('data-canvas-uuid')),
      );
      expect(order).toEqual([uuids[2], uuids[0], uuids[1]]);
    }).toPass();

    // The moved components stay selected.
    await expect(
      page.locator(
        `[data-testid="canvas-primary-panel"] [data-canvas-uuid="${uuids[0]}"][data-canvas-selected="true"]`,
      ),
    ).toBeVisible();
    await expect(
      page.locator(
        `[data-testid="canvas-primary-panel"] [data-canvas-uuid="${uuids[1]}"][data-canvas-selected="true"]`,
      ),
    ).toBeVisible();
    await expect(
      page.locator('[data-testid="canvas-contextual-panel"]'),
    ).toContainText('2 items selected');

    // A single undo restores the original order.
    await page.getByLabel('Undo').click();
    await expect(async () => {
      const order = await layerItems.evaluateAll((elements) =>
        elements.map((element) => element.getAttribute('data-canvas-uuid')),
      );
      expect(order).toEqual([uuids[0], uuids[1], uuids[2]]);
    }).toPass();
  });
});
