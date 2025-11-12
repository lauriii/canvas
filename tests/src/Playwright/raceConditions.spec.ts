import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Test race conditions are avoided', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules([
        'canvas',
        'canvas_test_sdc',
        'canvas_test_autocomplete',
      ]);
      await page.close();
    },
  );

  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
  });

  test('Avoids race condition between AJAX and layout updates', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    const currentRequestCount = {
      layout: 0,
      ajax: 0,
    };

    const requestCount = {
      layout: 0,
      ajax: 0,
    };

    // Watch for AJAX requests.
    await page.route(
      /\/canvas\/api\/v0\/form\/component-instance\/canvas_page\/\d\?.*drupal_ajax/,
      async (route) => {
        await expect(currentRequestCount.layout).toEqual(0);
        currentRequestCount.ajax++;
        requestCount.ajax++;
        await route.continue();
        currentRequestCount.ajax--;
      },
    );

    // Watch for PATCH requests to update the form.
    await page.route(
      /\/canvas\/api\/v0\/form\/component-instance\/canvas_page\/\d$/,
      async (route) => {
        // Artificial delay.
        await new Promise((resolve) => setTimeout(resolve, 3_000));
        await expect(currentRequestCount.ajax).toEqual(0);
        currentRequestCount.layout++;
        requestCount.layout++;
        await route.continue();
        currentRequestCount.layout--;
      },
    );

    // Watch for PATCH requests to the layout.
    await page.route(
      /\/canvas\/api\/v0\/layout\/canvas_page\/\d$/,
      async (route) => {
        // Artificial delay.
        await new Promise((resolve) => setTimeout(resolve, 3_000));
        await expect(currentRequestCount.ajax).toEqual(0);
        currentRequestCount.layout++;
        requestCount.layout++;
        await route.continue();
        currentRequestCount.layout--;
      },
    );

    await drupal.createCanvasPage('Jerry Was A Race Car Driver', '/jerry');
    await page.goto('/jerry');
    await canvasEditor.goToEditor();
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });
    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText('There goes my hero');

    // Test AJAX waits for layout PATCH.
    await canvasEditor.editComponentProp('heading', 'Les');
    await page
      .getByRole('button', { name: 'Click to test AJAX vs PATCH' })
      .click();

    // Test layout PATCH waits for AJAX.
    await page.getByLabel('Autocomplete Field').fill('z');
    await page
      .getByRole('button', { name: 'Click to test AJAX vs PATCH' })
      .click();

    await expect(requestCount.layout).toBeGreaterThan(0);
    await expect(requestCount.ajax).toBeGreaterThan(0);
  });
});
