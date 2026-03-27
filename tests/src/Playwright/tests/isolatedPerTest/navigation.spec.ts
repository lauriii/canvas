import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Request } from '@playwright/test';

test.describe('Routing', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules(['canvas_test_sdc']);
    await drupal.logout();
  });

  test('Visits a component router URL directly', async ({
    page,
    canvas,
    drupal,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Hero' });
    await expect(page.getByText('Review 1 change')).toBeAttached();

    // get the current URL to extract the entity type/ID and component UUID
    const currentURL = page.url();

    // Extract the component UUID if present
    const uuidMatch = currentURL.match(/\/component\/([a-f0-9-]+)/);
    const uuid = uuidMatch ? uuidMatch[1] : null;

    // Visit the component router URL directly
    await page.goto(currentURL);
    await canvas.waitForEditorUi();

    // Verify the contextual panel exists for the component (sidebar mounts after load).
    await expect(
      page.getByTestId(`canvas-contextual-panel-${uuid}`),
    ).toBeAttached();
  });

  test('Visits a preview router URL directly', async ({
    drupal,
    canvas,
    page,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Hero' });

    await page.goto(`/canvas/preview/canvas_page/${canvasPage.entity_id}/full`);

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
      new RegExp(`/canvas/preview/canvas_page/${canvasPage.entity_id}/full`),
    );
  });

  test('has the expected performance', async ({ drupal, canvas, page }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    // Set up route listeners for the API calls
    const getLayoutRequests: Request[] = [];
    const getPreviewRequests: Request[] = [];

    page.on('request', (request) => {
      if (
        request
          .url()
          .includes(
            `/canvas/api/v0/layout/canvas_page/${canvasPage.entity_id}`,
          ) &&
        request.method() === 'GET'
      ) {
        getLayoutRequests.push(request);
      }
      if (
        request
          .url()
          .includes(
            `/canvas/api/v0/layout/canvas_page/${canvasPage.entity_id}`,
          ) &&
        request.method() === 'POST'
      ) {
        getPreviewRequests.push(request);
      }
    });

    const layoutResponse = page.waitForResponse(
      (response) =>
        response
          .url()
          .includes(
            `/canvas/api/v0/layout/canvas_page/${canvasPage.entity_id}`,
          ) && response.request().method() === 'GET',
    );

    await page.reload();

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
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas({ title: 'Navigation Page 1' });
    await page
      .locator('[data-drupal-selector="edit-path-0-alias"]')
      .fill('/navigation-page-1');
    await canvas.publishAllChanges();
    await canvas.createCanvas({ title: 'Navigation Page 2' });
    await page
      .locator('[data-drupal-selector="edit-path-0-alias"]')
      .fill('/navigation-page-2');
    await canvas.publishAllChanges();

    // Go to the first page
    await page.goto('/navigation-page-1');
    await page.getByRole('link', { name: 'Edit' }).click();

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

    await canvas.openLibraryPanel();

    // Add a component
    await canvas.addComponent({ name: 'Hero' });
    await expect(
      page.locator('#canvasPreviewOverlay [aria-label="Hero"]'),
    ).toHaveCount(1);

    // Verify undo is available (because we added the component, updated values).
    await expect(page.getByLabel('Undo')).not.toBeDisabled();

    // Navigate to the second page
    await canvas.openPagesPanel();
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
