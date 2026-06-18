import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({ modules: ['canvas_test_extension_page', 'canvas_test_extension'] });

test.describe('Page extensions', () => {
  test('sidebar navigation, extensions panel filtering, and iframe rendering', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvasRoot();

    const sideMenu = page.getByTestId('canvas-side-menu');

    // The page extension should have a dedicated sidebar link.
    await expect(sideMenu.getByLabel('Test Page Extension')).toBeVisible();

    // Open the extensions panel: only canvas-type extensions appear.
    await sideMenu.getByLabel('Extensions').click();
    const panel = page.getByTestId('canvas-primary-panel');
    await expect(panel.getByText('Canvas Test Extension')).toBeVisible();
    await expect(panel.getByText('Test Page Extension')).toBeHidden();

    // Navigate to the page extension via the sidebar link.
    await sideMenu.getByLabel('Test Page Extension').click();
    await expect(page).toHaveURL(/\/canvas\/app\/canvas_test_page/);

    // Verify the extension iframe loaded.
    const iframe = page
      .locator('iframe[id="canvas-extension-page-canvas_test_page"]')
      .contentFrame();
    await expect(iframe.getByText('Page extension is working.')).toBeVisible();

    // Navigate back by clicking a sidebar panel button.
    await sideMenu.getByLabel('Pages').click();
    await expect(page).toHaveURL(/\/canvas$/);
    await expect(page.getByRole('heading', { name: 'Pages' })).toBeVisible();
  });

  test('deep link with subpath renders the inner view on first mount', async ({
    page,
    drupal,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    // Load the extension directly with a subpath — no in-app navigation. The
    // iframe src is seeded from the initial URL, so the inner view must
    // receive the subpath as its hash route.
    await page.goto('/canvas/app/canvas_test_page/reports/weekly');
    const iframe = page
      .locator('iframe[id="canvas-extension-page-canvas_test_page"]')
      .contentFrame();
    await expect(iframe.getByText('Page extension is working.')).toBeVisible();
    await expect(iframe.locator('#inner-route')).toHaveText('#/reports/weekly');

    // The back button has no in-app history to pop on a deep link, so it must
    // fall back to the Canvas root instead of leaving Canvas.
    await page.getByLabel('Go back').click();
    await expect(page).toHaveURL(/\/canvas$/);
  });
});
