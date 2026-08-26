import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

// @cspell:ignore fontmaster

const SEL = {
  tab: '[data-testid="canvas-brand-kit-fonts-tab-select"]',
  colorsTab: '[data-testid="canvas-brand-kit-colors-tab-select"]',
  uploadButton: '[data-testid="canvas-brand-kit-upload-font-button"]',
  fileInput:
    '[data-testid="canvas-brand-kit-fonts-tab-content"] input[type="file"]',
  familyRow: 'button[class*="_familyRow"]',
  variantRow: 'button[class*="_variantRow"]',
  familyNameInput: '[class*="_flyoutContent"] input',
};

/**
 * Uploads a font file through the fonts section's hidden file input.
 */
const uploadFont = async (page: Page, filename: string) => {
  // Use fake font bytes to avoid the need for real font files.
  await page.locator(SEL.fileInput).setInputFiles({
    name: filename,
    mimeType: 'font/woff2',
    buffer: Buffer.from('canvas-playwright-font'),
  });
};

test.use({
  modules: ['canvas_dev_mode'],
  enableTestExtensions: true,
});

test.describe('brand kit fonts', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();

    await drupal.createRole({ name: 'fontmaster' });
    await drupal.createUser({
      email: 'fontmaster@example.com',
      username: 'fontmaster',
      password: 'fontmaster',
      roles: ['fontmaster'],
    });
    await drupal.addPermissions({
      role: 'fontmaster',
      permissions: [
        'create canvas_page',
        'edit canvas_page',
        'publish auto-saves',
        'administer code components',
        'administer brand kit',
      ],
    });
    await drupal.logout();
  });

  test('uploads, renames and persists a font family', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'fontmaster', password: 'fontmaster' });
    await canvas.openCanvasRoot();
    await canvas.openBrandKitPanel();

    // The Brand Kit panel opens on the Colors tab; fonts live behind a tab.
    await page.locator(SEL.tab).click();
    await expect(page.locator(SEL.uploadButton)).toBeVisible();
    await expect(page.getByText('No fonts uploaded yet.')).toBeVisible();

    await uploadFont(page, 'mona-sans.woff2');

    const familyRow = page.locator(SEL.familyRow);
    await expect(familyRow).toBeVisible();
    await expect(familyRow).toContainText('Mona Sans');
    // @see https://www.drupal.org/i/3591954
    await expect(familyRow).toHaveCSS('display', 'flex');

    // Uploading selects the new font, which opens its family flyout. The
    // variant rows there carry the same reset.
    // @see useFontUpload's onFontUploaded / useBrandKitFontSelection.selectFont
    const variantRow = page.locator(SEL.variantRow).first();
    await expect(variantRow).toBeVisible();
    await expect(variantRow).toHaveCSS('display', 'flex');

    // Rename the family. The name field commits on blur.
    const nameInput = page.locator(SEL.familyNameInput).first();
    await expect(nameInput).toHaveValue('Mona Sans');
    await nameInput.fill('Renamed Sans');
    await nameInput.blur();
    await expect(familyRow).toContainText('Renamed Sans');

    // Leave the fonts tab then return to it to ensure the flyout is closed.
    await page.locator(SEL.colorsTab).click();
    await expect(page.locator(SEL.uploadButton)).toBeHidden();
    await page.locator(SEL.tab).click();
    await expect(familyRow).toContainText('Renamed Sans');

    // The rename survives a reload.
    // Ensure all requests complete before reloading.
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');
    await page.reload();
    await canvas.openBrandKitPanel();
    await page.locator(SEL.tab).click();
    await expect(familyRow).toContainText('Renamed Sans');
  });
});
