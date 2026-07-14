import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

/**
 * Guards against editor state leaking between page variants (page templates).
 *
 * The layout/model live in a store shared across entities and are not cleared
 * on navigation, while a preview/auto-save request derives its target entity
 * from the current route. When the newly navigated variant's layout is still
 * loading, the store keeps holding the previously edited variant's model, so a
 * layout change made in that window used to persist one variant's content onto
 * another. This test drives that exact window and asserts no cross-variant
 * leak occurs.
 */

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

async function openTemplatesPanel(page: Page) {
  await page
    .getByTestId('canvas-side-menu')
    .getByRole('button', { name: 'Templates' })
    .click();
}

// The templates list can get stuck loading when it mounts with an already
// fulfilled variants cache, so remount it (toggle to Pages and back) before
// creating a second variant.
async function remountTemplatesPanel(page: Page) {
  await openTemplatesPanel(page);
  await page
    .getByTestId('canvas-side-menu')
    .getByRole('button', { name: 'Pages' })
    .click();
  await openTemplatesPanel(page);
}

async function createVariant(page: Page, label: string, id: string) {
  await page.getByTestId('canvas-page-variant-new-button').click();
  await page.getByTestId('canvas-page-variant-label-input').fill(label);
  await page.getByRole('button', { name: 'Create template' }).click();
  await expect(page.getByTestId(`canvas-page-variant-${id}`)).toBeVisible();
}

test.describe('Page variant state isolation', () => {
  test('editing while a variant loads does not leak into it', async ({
    page,
    drupal,
    canvas,
  }) => {
    test.setTimeout(200_000);
    const MARKER = 'AlphaOnlyLeakMarker';

    // Record every layout mutation and whether it carries Alpha's marker.
    const layoutMutations: {
      method: string;
      url: string;
      hasMarker: boolean;
    }[] = [];
    page.on('request', (req) => {
      const url = req.url().replace(/^https?:\/\/[^/]+/, '');
      const method = req.method();
      if (
        (method === 'POST' || method === 'PATCH') &&
        url.includes('/canvas/api/v0/layout/')
      ) {
        layoutMutations.push({
          method,
          url,
          hasMarker: (req.postData() || '').includes(MARKER),
        });
      }
    });

    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas({ title: 'Host' }));

    await openTemplatesPanel(page);
    await createVariant(page, 'Alpha', 'alpha');
    await remountTemplatesPanel(page);
    await createVariant(page, 'Beta', 'beta');

    // Give Alpha a distinctive heading.
    await page.getByTestId('canvas-page-variant-alpha').click();
    await expect(page).toHaveURL(/\/canvas\/editor\/page_variant\/alpha/);
    await canvas.waitForEditorFrame();
    await canvas.openLibraryPanel();
    await canvas.addComponent(
      { id: 'sdc.canvas_test_sdc.heading' },
      { hasInputs: true },
    );
    await canvas.editComponentProp('text', MARKER);
    // Let Alpha's edit persist before we start watching for leaks.
    // eslint-disable-next-line playwright/no-wait-for-timeout
    await page.waitForTimeout(2500);

    // Hold Beta's layout GET so the store keeps Alpha's model while the route
    // is already Beta.
    const betaLayoutDelayMs = 12000;
    await page.route(
      /\/canvas\/api\/v0\/layout\/page_variant\/beta(\?|$)/,
      async (route) => {
        if (route.request().method() === 'GET') {
          await new Promise((r) => setTimeout(r, betaLayoutDelayMs));
        }
        await route.continue();
      },
    );

    layoutMutations.length = 0; // only care about what happens after nav.

    // Navigate to Beta; its layout is slow, so Alpha's model is still shown.
    const betaLoaded = page.waitForResponse(
      (r) =>
        /\/canvas\/api\/v0\/layout\/page_variant\/beta(\?|$)/.test(r.url()) &&
        r.request().method() === 'GET',
    );
    await openTemplatesPanel(page);
    await page.getByTestId('canvas-page-variant-beta').click();
    await expect(page).toHaveURL(/\/canvas\/editor\/page_variant\/beta/);
    // Wait briefly so we act squarely inside the still-loading window.
    // eslint-disable-next-line playwright/no-wait-for-timeout
    await page.waitForTimeout(2000);

    // Insert a component while Beta is still loading. This mutates the model
    // and schedules an auto-save; it must not be written to Beta.
    await canvas.openLibraryPanel();
    const libraryItem = page
      .getByTestId('canvas-primary-panel')
      .locator(
        '[data-canvas-type="component"][data-canvas-component-id="sdc.canvas_test_sdc.heading"]',
      );
    await libraryItem.hover();
    await libraryItem.getByLabel('Open contextual menu').click();
    await page.getByRole('menuitem', { name: 'Insert' }).click();

    // Let Beta finish loading and any scheduled/parked requests flush.
    await betaLoaded;
    // eslint-disable-next-line playwright/no-wait-for-timeout
    await page.waitForTimeout(2000);

    // No layout mutation may write Alpha's content to Beta.
    const leaks = layoutMutations.filter(
      (m) => m.url.includes('/page_variant/beta') && m.hasMarker,
    );
    expect(
      leaks,
      `Alpha's content leaked into Beta via: ${JSON.stringify(leaks)}`,
    ).toEqual([]);

    // Beta, once loaded, must show only its own "Page content" marker — never
    // Alpha's heading. Layout tree items are the only tree items on the page.
    await canvas.openLayersPanel();
    await expect(
      page.getByRole('treeitem', { name: /Page content/ }),
    ).toBeVisible();
    await expect(page.getByRole('treeitem', { name: /Heading/ })).toHaveCount(
      0,
    );
  });
});
