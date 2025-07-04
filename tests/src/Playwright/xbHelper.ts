import { expect, Locator, Page } from '@playwright/test';

/**
 * Waits for the XB preview iframe to be initialized and active, and for the canvas to be ready.
 * Mirrors the Cypress `previewReady` command.
 *
 * @param page Playwright Page object
 * @param iframeSelector Selector for the initialized/active preview iframe
 * @returns Locator for the iframe element
 */
export async function previewReady(
  page: Page,
  iframeSelector = '[data-test-xb-content-initialized="true"][data-xb-swap-active="true"]',
): Promise<Locator> {
  // Wait for the canvas scaling container to be fully visible
  await expect(page.locator('.xbCanvasScalingContainer')).toHaveCSS(
    'opacity',
    '1',
  );

  await expect(page.locator('.xb--region-overlay__content')).toBeAttached();
  // The idea behind iframeSelector selector is that the data-xb-swap-active part ensures we are selecting the
  // correct/visible iFrame (because there are two that swap back and forth). The
  // data-test-xb-content-initialized="true" part ensures that XB has marked the iframe as ready.
  await expect(page.locator(iframeSelector)).toBeAttached({ timeout: 10000 });
  await page.waitForFunction((selector) => {
    const el = document.querySelector(selector) as HTMLIFrameElement;
    return !!el && !!el.contentDocument;
  }, iframeSelector);

  return page.locator(iframeSelector);
}

/**
 * Opens the Library Panel by clicking the "Add" button in the side menu.
 * Waits for the library loading indicator to disappear.
 *
 * @param page Playwright Page object
 */
export async function openLibraryPanel(page: Page): Promise<void> {
  await page.getByTestId('xb-side-menu').getByLabel('Add').click();
  await expect(
    page.getByTestId('xb-components-library-loading'),
  ).not.toBeVisible();
}

/**
 * Opens the Layers Panel by clicking the "Layers" button in the side menu.
 *
 * @param page Playwright Page object
 */
export async function openLayersPanel(page: Page): Promise<void> {
  await page.getByTestId('xb-side-menu').getByLabel('Layers').click();
}
