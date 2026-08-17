import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Must match
 * \Drupal\canvas_test_render_message\Hook\CanvasTestRenderMessageHooks::MESSAGE.
 */
const MESSAGE = 'Added while rendering.';

/**
 * The editor's preview iframe.
 *
 * @see \Drupal\Tests\canvas\Playwright\objects\canvas\CanvasUtilities
 */
const PREVIEW_IFRAME =
  'iframe[data-test-canvas-content-initialized="true"][data-canvas-swap-active="true"]';

test.use({
  modules: ['canvas_test_sdc', 'canvas_test_render_message'],
  enableTestExtensions: true,
});

test.describe('Preview status messages', () => {
  test('are shown in the editor, not in the preview', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    // Without page regions the preview is rendered by core's page variant,
    // which still renders status messages.
    // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
    await canvas.enableGlobalRegions();

    // canvas_test_render_message adds a status message every time a page
    // renders, so the preview this opens has one.
    await canvas.openCanvas(await canvas.createCanvas());

    await expect(page.getByText(MESSAGE)).toBeVisible();
    await expect(
      page.frameLocator(PREVIEW_IFRAME).getByText(MESSAGE),
    ).toHaveCount(0);
  });
});
