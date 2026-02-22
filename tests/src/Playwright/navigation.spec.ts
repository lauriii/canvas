import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

import type { Request } from '@playwright/test';

let entityPath: string;
let uuid: string;
declare global {
  interface Window {
    navigationMarker?: number;
  }
}

test.describe('Routing', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['canvas', 'canvas_test_sdc']);
      await page.close();
    },
  );

  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
  });

  test('Visits a component router URL directly', async ({
    page,
    canvasEditor,
    drupal,
  }) => {
    await drupal.createCanvasPage('Preview 1', '/preview-page-1');
    await page.goto('/preview-page-1');
    await canvasEditor.goToEditor();
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ name: 'Hero' });
    await expect(page.getByText('Review 1 change')).toBeAttached();

    // get the current URL to extract the entity type/ID and component UUID
    const currentURL = page.url();

    // Extract the dynamic entity type and ID part (e.g., "canvas_page/1" or "node/1")
    // This matches everything between /canvas/editor/ and optional /component/uuid
    const entityMatch = currentURL.match(/\/canvas\/editor\/([^/]+\/[^/]+)/);
    entityPath = entityMatch ? entityMatch[1] : null;
    console.log('entityPath', entityPath);

    // Extract the component UUID if present
    const uuidMatch = currentURL.match(/\/component\/([a-f0-9-]+)/);
    uuid = uuidMatch ? uuidMatch[1] : null;

    console.log('uuid', uuid);

    // Visit the component router URL directly
    await page.goto(currentURL);
    await canvasEditor.waitForEditorUi();

    // Verify the contextual panel exists for the component (sidebar mounts after load).
    await expect(
      page.getByTestId(`canvas-contextual-panel-${uuid}`),
    ).toBeAttached({ timeout: 15_000 });
  });

  test('Visits a preview router URL directly', async ({ page }) => {
    await page.goto(`/canvas/preview/${entityPath}/full`);

    // Verify the exit preview button is visible
    await expect(page.getByText('Exit Preview')).toBeVisible();

    // Access the preview iframe and verify content
    const iframeElement = await page.$('iframe[title="Page preview"]');
    const previewFrame = await iframeElement?.contentFrame();
    if (!previewFrame) throw new Error('Preview iframe not found');

    // Wait for iframe body to be populated
    await expect(previewFrame.locator('body')).not.toBeEmpty();

    // Verify the hero heading exists in the iframe
    await expect(previewFrame.locator('.my-hero__heading')).toBeAttached();

    // Verify the URL contains the expected path
    await expect(page).toHaveURL(
      new RegExp(`/canvas/preview/${entityPath}/full`),
    );
  });

  test('has the expected performance', async ({ page }) => {
    // Set up route listeners for the API calls
    const getLayoutRequests: Request[] = [];
    const getPreviewRequests: Request[] = [];

    page.on('request', (request) => {
      if (
        request.url().includes(`/canvas/api/v0/layout/${entityPath}`) &&
        request.method() === 'GET'
      ) {
        getLayoutRequests.push(request);
      }
      if (
        request.url().includes(`/canvas/api/v0/layout/${entityPath}`) &&
        request.method() === 'POST'
      ) {
        getPreviewRequests.push(request);
      }
    });

    const layoutResponse = page.waitForResponse(
      (response) =>
        response.url().includes(`/canvas/api/v0/layout/${entityPath}`) &&
        response.request().method() === 'GET',
    );

    await page.goto(`/canvas/editor/${entityPath}`);

    const response = await layoutResponse;
    expect(response.status()).toBe(200);

    // Give it a moment to ensure no additional requests are made
    await page.waitForTimeout(500);

    // Assert that only the GET layout request was sent
    expect(getLayoutRequests.length).toBe(1);
    expect(getPreviewRequests.length).toBe(0);
  });

  test('Can navigate between pages without page reloads', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.createCanvasPage('Navigation Page 1', '/navigation-page-1');
    await drupal.createCanvasPage('Navigation Page 2', '/navigation-page-2');

    // Go to the first page
    await page.goto('/navigation-page-1');
    await canvasEditor.goToEditor();

    // Before navigation, inject a unique marker so that we can verify the page does not reload during navigation
    await page.evaluate(() => {
      window.navigationMarker = Math.random();
    });
    const marker = await page.evaluate(() => window.navigationMarker);

    // Verify page data form values
    await expect(page.getByLabel('Title')).toHaveValue('Navigation Page 1');
    await expect(page.getByLabel('URL Alias')).toHaveValue(
      '/navigation-page-1',
    );

    // Update the title
    await expect(page.getByLabel('Title')).toBeAttached();
    await page.getByLabel('Title').fill('New Title');
    await expect(page.getByLabel('Title')).toHaveValue('New Title');

    await canvasEditor.openLibraryPanel();

    // Add a component
    await canvasEditor.addComponent({ name: 'Hero' });
    await expect(
      page.locator('#canvasPreviewOverlay [aria-label="Hero"]'),
    ).toHaveCount(1);

    // Verify undo is available (because we added the component, updated values).
    await expect(page.getByLabel('Undo')).not.toBeDisabled();

    // Navigate to the second page
    await canvasEditor.openPagesPanel();
    await page.getByText('Navigation Page 2 /navigation-page-2').click();

    // Verify page data form values for the second page
    await expect(page.getByLabel('Title')).toHaveValue('Navigation Page 2');
    await expect(page.getByLabel('URL Alias')).toHaveValue(
      '/navigation-page-2',
    );

    // Verify the component is not present on the second page - it was only added to the first page
    await expect(
      page.locator('#canvasPreviewOverlay [aria-label="Hero"]'),
    ).toHaveCount(0);

    // Should have cleared the undo stack when navigating to a new page
    await expect(page.getByLabel('Undo')).toBeDisabled();

    // Navigate back to the first page
    await page.getByText('New Title /navigation-page-1').click();

    // Verify page data form values for the first page again
    await expect(page.getByLabel('Title')).toHaveValue('New Title');
    await expect(page.getByLabel('URL Alias')).toHaveValue(
      '/navigation-page-1',
    );

    // Verify the component is present again on the first page
    await expect(
      page.locator('#canvasPreviewOverlay [aria-label="Hero"]'),
    ).toHaveCount(1);

    // Should still have cleared the undo stack when navigating back to the first page
    await expect(page.getByLabel('Undo')).toBeDisabled();

    // Verify navigationMarker still exists (it would have been lost if there was a full page reload)
    const markerAfter = await page.evaluate(() => window.navigationMarker);
    expect(markerAfter).toBe(marker);
  });
});
