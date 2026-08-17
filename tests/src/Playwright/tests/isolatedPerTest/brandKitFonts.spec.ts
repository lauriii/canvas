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
  // The rows and the flyout are styled through CSS modules, so they are
  // addressed by the hashed class name prefix. This is what
  // brandKitColors.spec.ts does for folder counts.
  familyList: '[class*="_familyList"]',
  familyRow: 'button[class*="_familyRow"]',
  flyout: '[class*="_flyoutContent"]',
  variantRow: 'button[class*="_variantRow"]',
  // The family name field is the first input inside the flyout; the variant
  // editor below it contributes the others.
  familyNameInput: '[class*="_flyoutContent"] input',
};

/**
 * Uploads a font file through the fonts section's hidden file input.
 *
 * The uploaded bytes are not a real font. Metadata parsing fails gracefully and
 * the family name falls back to the filename, which keeps this spec free of a
 * binary fixture.
 */
const uploadFont = async (page: Page, filename: string) => {
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

    // Regression guard: the family row is a <button> that resets its user agent
    // styles with `all: unset` before declaring its own layout. `all` is a
    // shorthand for every property, so if it is not the first declaration it
    // overrides the declarations preceding it, and the row computes to
    // `display: inline` with no height, padding, or width.
    // @see https://www.drupal.org/i/3577631
    await expect(familyRow).toHaveCSS('display', 'flex');
    const rowBox = await familyRow.boundingBox();
    const listBox = await page.locator(SEL.familyList).boundingBox();
    expect(rowBox).not.toBeNull();
    expect(listBox).not.toBeNull();
    // The row fills its list rather than shrinking to its text.
    expect(rowBox!.width).toBeGreaterThanOrEqual(listBox!.width - 1);
    expect(rowBox!.height).toBeGreaterThan(20);

    // Uploading selects the new font, which opens its family flyout. The
    // variant rows there carry the same `all: unset` reset.
    // @see useFontUpload's onFontUploaded / useBrandKitFontSelection.selectFont
    const variantRow = page.locator(SEL.variantRow).first();
    await expect(variantRow).toBeVisible();
    await expect(variantRow).toHaveCSS('display', 'flex');
    const variantBox = await variantRow.boundingBox();
    expect(variantBox).not.toBeNull();
    expect(variantBox!.height).toBeGreaterThan(20);

    // Rename the family. The name field commits on blur, and the commit applies
    // to local state before its auto-save PATCH resolves. Wait for the request
    // so the reload below cannot abort a still-in-flight save.
    const nameInput = page.locator(SEL.familyNameInput).first();
    await expect(nameInput).toHaveValue('Mona Sans');
    const autoSaved = page.waitForResponse(
      (response) =>
        response.url().includes('config/auto-save/brand_kit/global') &&
        response.request().method() === 'PATCH' &&
        response.ok(),
    );
    await nameInput.fill('Renamed Sans');
    await nameInput.blur();
    await expect(familyRow).toContainText('Renamed Sans');
    await autoSaved;

    // Switching to Colors and back keeps the fonts section working.
    await page.keyboard.press('Escape');
    await expect(page.locator(SEL.flyout)).toBeHidden();
    await page.locator(SEL.colorsTab).click();
    await expect(page.locator(SEL.uploadButton)).toBeHidden();
    await page.locator(SEL.tab).click();
    await expect(familyRow).toContainText('Renamed Sans');

    // The rename survives a reload, and the row keeps its layout.
    await page.reload();
    await canvas.openBrandKitPanel();
    await page.locator(SEL.tab).click();
    await expect(familyRow).toContainText('Renamed Sans');
    await expect(familyRow).toHaveCSS('display', 'flex');
  });
});
