import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore cset

test.describe('Block form', () => {
  test('Block settings form with details element', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    // Don't wait for the preview as this user doesn't have permissions to see anything
    // in that menu.
    await canvas.addComponent(
      { id: 'block.system_menu_block.footer' },
      { waitForVisible: false },
    );

    const inputsForm = page.locator(
      '[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"]',
    );
    await expect(inputsForm).toContainText('Menu levels');
    await expect(inputsForm.locator('select')).toHaveCount(2);
    await expect(inputsForm.locator('input[type="checkbox"]')).toBeVisible();
  });

  //test('Block settings form values are stored and the preview is updated', async ({
  //  page,
  //  drupal,
  //  canvas,
  //}) => {
  //  await drupal.login({ username: 'editor', password: 'editor' });
  //  const canvasPage = await canvas.createCanvas();
  //  await canvas.openLibraryPanel();
  //  // Don't wait for the preview as there won't be anything to see initially.
  //  await canvas.addComponent(
  //    { name: 'Site branding' },
  //    { waitForVisible: false },
  //  );

  //  await canvas.openLayersPanel();
  //  await canvas.openComponent('Site branding');

  //  // Remove and re-add the site logo.
  //  const siteLogoCheckbox = page
  //    .locator(
  //      `[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"]`,
  //    )
  //    .getByLabel('Site logo');
  //  await expect(siteLogoCheckbox).toBeChecked();
  //  await expect(
  //    (await canvas.getActivePreviewFrame()).locator(
  //      `[data-canvas-component-id="block.system_branding_block"] img`,
  //    ),
  //  ).toBeVisible();
  //  await siteLogoCheckbox.click();
  //  await expect(siteLogoCheckbox).not.toBeChecked();
  //  await expect(
  //    (await canvas.getActivePreviewFrame()).locator(
  //      `[data-canvas-component-id="block.system_branding_block"] img`,
  //    ),
  //  ).not.toBeVisible();
  //  await siteLogoCheckbox.click();
  //  await expect(siteLogoCheckbox).toBeChecked();
  //  await expect(
  //    (await canvas.getActivePreviewFrame()).locator(
  //      `[data-canvas-component-id="block.system_branding_block"] img`,
  //    ),
  //  ).toBeVisible();

  //  // Remove the site name.
  //  const siteNameCheckbox = page
  //    .locator(
  //      `[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"]`,
  //    )
  //    .getByLabel('Site name');
  //  await expect(siteNameCheckbox).toBeChecked();
  //  await expect(
  //    (await canvas.getActivePreviewFrame()).locator(
  //      `[data-canvas-component-id="block.system_branding_block"]`,
  //    ),
  //  ).toHaveText('Drupal');
  //  await siteNameCheckbox.click();
  //  await expect(siteNameCheckbox).not.toBeChecked();
  //  await expect(
  //    (await canvas.getActivePreviewFrame()).locator(
  //      `[data-canvas-component-id="block.system_branding_block"]`,
  //    ),
  //  ).not.toHaveText('Drupal');

  //  // Verify the component is saved and renders with the new options.
  //  await canvas.publishAllChanges();
  //  await canvas.openCanvas(canvasPage);
  //  await expect(
  //    page.locator(
  //      'xpath=//img[ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]',
  //    ),
  //  ).toBeVisible();
  //  await expect(
  //    page.locator(
  //      'xpath=//img[ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]',
  //    ),
  //  ).toHaveAttribute('src', /logo\.svg/);
  //  await expect(
  //    page.locator(
  //      'xpath=//img[ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]',
  //    ),
  //  ).not.toHaveText('Drupal');
  //});
});
