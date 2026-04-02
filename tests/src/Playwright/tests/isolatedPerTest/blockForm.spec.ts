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

  test('Block settings form values are stored and the preview is updated', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    // Don't wait for the preview as there won't be anything to see initially.
    await canvas.addComponent(
      { name: 'Site branding' },
      { waitForVisible: false },
    );

    await canvas.openLayersPanel();
    await canvas.openComponent('Site branding');

    // Add and remove the site logo.
    const imgLocator =
      'xpath=//img[ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]';
    const siteLogoCheckbox = page.getByRole('checkbox', { name: 'Site logo' });
    await expect(siteLogoCheckbox).not.toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).not.toBeVisible();
    await siteLogoCheckbox.click();
    await expect(siteLogoCheckbox).toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).toBeVisible();
    await siteLogoCheckbox.click();
    await expect(siteLogoCheckbox).not.toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).not.toBeVisible();

    // Add the site name.
    const siteNameLocator =
      'xpath=//a[ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]';
    const siteNameCheckbox = page.getByRole('checkbox', { name: 'Site name' });
    await expect(siteNameCheckbox).not.toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).not.toBeVisible();
    await siteNameCheckbox.click();
    await expect(siteNameCheckbox).toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).toHaveText('Drupal');

    // Verify the component is saved and renders with the new options.
    await canvas.publishAllChanges();
    await page.reload();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).not.toBeVisible();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).toHaveText('Drupal');
  });
});
