import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { FrameLocator, Page } from '@playwright/test';

async function getImageOrder(page: Page): Promise<string[]> {
  const items = await page
    .locator('[data-testid="canvas-contextual-panel"] .js-media-library-item')
    .all();
  const alts: string[] = [];
  for (const item of items) {
    const alt = await item
      .locator('.js-media-library-item-preview img')
      .getAttribute('alt');
    if (alt) {
      alts.push(alt);
    }
  }
  return alts;
}

async function getPreviewImageOrder(
  previewFrame: FrameLocator,
): Promise<string[]> {
  const figure = previewFrame.locator('main figure').first();
  const images = await figure.locator('img').all();
  const alts: string[] = [];
  for (const img of images) {
    const alt = await img.getAttribute('alt');
    if (alt) {
      alts.push(alt);
    }
  }
  return alts;
}

test.describe('Media Multi-Cardinality', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(`core/recipes/image_media_type`);
    await drupal.installModules(['canvas_test_sdc']);
    await drupal.logout();
  });

  test('Can add multiple media items', async ({ page, drupal, canvas }) => {
    await drupal.loginAsAdmin();
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.image-gallery',
    });

    await canvas.addMediaImage(
      '../../../../../fixtures/recipes/test_site/content/file/cats-1.jpg',
      'A cat on top of a cat tree trying to reach a Christmas tree',
    );
    await canvas.addMediaImage(
      '../../../../../fixtures/recipes/test_site/content/file/PrincesHead.jpg',
      'A pub called The Princes Head surrounded by trees and two red London phone boxes',
    );

    await expect(
      page.locator(
        '[data-testid="canvas-contextual-panel"] .js-media-library-item',
      ),
    ).toHaveCount(2);

    const initialOrder = await getImageOrder(page);
    expect(initialOrder.length).toBe(2);
    expect(initialOrder).toContain(
      'A cat on top of a cat tree trying to reach a Christmas tree',
    );
    expect(initialOrder).toContain(
      'A pub called The Princes Head surrounded by trees and two red London phone boxes',
    );

    const previewFrame = await canvas.getActivePreviewFrame();
    await expect(previewFrame.locator('main figure img')).toHaveCount(2);
    const previewInitialOrder = await getPreviewImageOrder(previewFrame);
    expect(previewInitialOrder.length).toBe(2);
    expect(previewInitialOrder).toEqual(initialOrder);
  });

  test('Adding media after page reload preserves existing items', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.image-gallery',
    });

    await canvas.addMediaImage(
      '../../../../../fixtures/recipes/test_site/content/file/cats-1.jpg',
      'A cat on top of a cat tree trying to reach a Christmas tree',
    );

    // Set up auto-save listener before the second insert
    const autoSavePromise = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
        response.request().method() === 'PATCH',
    );

    await canvas.addMediaImage(
      '../../../../../fixtures/recipes/test_site/content/file/PrincesHead.jpg',
      'A pub called The Princes Head surrounded by trees and two red London phone boxes',
    );

    await expect(
      page.locator(
        '[data-testid="canvas-contextual-panel"] .js-media-library-item',
      ),
    ).toHaveCount(2);

    // Wait for auto-save to complete before reloading
    await autoSavePromise;

    // Reload the page
    await page.reload();
    await canvas.waitForEditorUi();

    // Verify both images are still there after reload
    await expect(
      page.locator(
        '[data-testid="canvas-contextual-panel"] .js-media-library-item',
      ),
    ).toHaveCount(2);

    // Add a third image after reload
    await canvas.addMediaImage(
      '../../../../../fixtures/images/gracie-big.jpg',
      'A cute dog',
    );

    // Verify all three images are present
    await expect(
      page.locator(
        '[data-testid="canvas-contextual-panel"] .js-media-library-item',
      ),
    ).toHaveCount(3);

    const finalOrder = await getImageOrder(page);
    expect(finalOrder.length).toBe(3);
    expect(finalOrder).toContain(
      'A cat on top of a cat tree trying to reach a Christmas tree',
    );
    expect(finalOrder).toContain(
      'A pub called The Princes Head surrounded by trees and two red London phone boxes',
    );
    expect(finalOrder).toContain('A cute dog');
  });
});
