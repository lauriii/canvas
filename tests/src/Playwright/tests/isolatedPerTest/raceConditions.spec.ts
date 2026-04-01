import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.describe('Test race conditions are avoided', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules([
      'canvas_test_sdc',
      'canvas_test_autocomplete',
    ]);
    await drupal.logout();
  });

  test('Avoids race condition between AJAX and layout updates', async ({
    page,
    drupal,
    canvas,
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
      /\/canvas\/api\/v0\/form\/component-instance\/canvas_page\/\d+\?.*drupal_ajax/,
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
      /\/canvas\/api\/v0\/form\/component-instance\/canvas_page\/\d+$/,
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
      /\/canvas\/api\/v0\/layout\/canvas_page\/\d+$/,
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

    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });
    await expect(
      (await canvas.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText('There goes my hero');

    // Test AJAX waits for layout PATCH.
    await canvas.editComponentProp('heading', 'Les');
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
