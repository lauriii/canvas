import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Locator, Page } from '@playwright/test';

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
  familyName: '[class*="_familyName"]',
  familyCount: '[class*="_familyCount"]',
  flyout: '[class*="_flyoutContent"]',
  variantRow: 'button[class*="_variantRow"]',
  variantRowMeta: '[class*="_variantRowMeta"]',
  variantRowChevron: '[class*="_variantRowChevron"]',
  // The family name field is the first input inside the flyout; the variant
  // editor below it contributes the others.
  familyNameInput: '[class*="_flyoutContent"] input',
};

/**
 * Returns an element's on-screen box, failing the test if it has none.
 */
const boxOf = async (locator: Locator) => {
  const box = await locator.boundingBox();
  expect(box, `expected ${locator} to be laid out on screen`).not.toBeNull();
  return box!;
};

const centerY = (box: { y: number; height: number }) => box.y + box.height / 2;

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

    // Regression guard: the family row must read as a row. Its name sits on the
    // left and its variant count on the right of the same line, and the row
    // spans the list rather than shrinking to its text. When the row's
    // `all: unset` reset stopped being its first declaration, the row lost its
    // layout and the count dropped onto a second line under the name.
    // @see https://www.drupal.org/i/3577631
    const rowBox = await boxOf(familyRow);
    const listBox = await boxOf(page.locator(SEL.familyList));
    const nameBox = await boxOf(familyRow.locator(SEL.familyName));
    const countBox = await boxOf(familyRow.locator(SEL.familyCount));

    expect(rowBox.width).toBeGreaterThanOrEqual(listBox.width - 1);
    expect(rowBox.height).toBeGreaterThan(20);
    // The count is beside the name, not stacked under it.
    expect(countBox.x).toBeGreaterThanOrEqual(nameBox.x + nameBox.width - 1);
    expect(Math.abs(centerY(countBox) - centerY(nameBox))).toBeLessThan(6);
    // Both sit inside the row's own height.
    expect(countBox.y + countBox.height).toBeLessThanOrEqual(
      rowBox.y + rowBox.height + 1,
    );

    // Uploading selects the new font, which opens its family flyout. The
    // variant rows there carry the same reset, and read as rows the same way:
    // label on the left, chevron on the right of the same line.
    // @see useFontUpload's onFontUploaded / useBrandKitFontSelection.selectFont
    const variantRow = page.locator(SEL.variantRow).first();
    await expect(variantRow).toBeVisible();
    const variantBox = await boxOf(variantRow);
    const variantMetaBox = await boxOf(variantRow.locator(SEL.variantRowMeta));
    const chevronBox = await boxOf(variantRow.locator(SEL.variantRowChevron));

    expect(variantBox.height).toBeGreaterThan(20);
    expect(chevronBox.x).toBeGreaterThanOrEqual(
      variantMetaBox.x + variantMetaBox.width - 1,
    );
    expect(
      Math.abs(centerY(chevronBox) - centerY(variantMetaBox)),
    ).toBeLessThan(6);

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

    // Whether the flyout is still open at this point is not deterministic:
    // `handleFamilyCommit` re-opens it on the renamed family only after its
    // save resolves, and the effect that closes a flyout whose family no
    // longer exists may run either side of that. So do not assert on it here —
    // just switch tabs, which dismisses it either way.
    await page.locator(SEL.colorsTab).click();
    await expect(page.locator(SEL.uploadButton)).toBeHidden();
    await page.locator(SEL.tab).click();
    await expect(familyRow).toContainText('Renamed Sans');

    // The rename survives a reload, and the row keeps its layout.
    await page.reload();
    await canvas.openBrandKitPanel();
    await page.locator(SEL.tab).click();
    await expect(familyRow).toContainText('Renamed Sans');
    const reloadedRowBox = await boxOf(familyRow);
    const reloadedCountBox = await boxOf(familyRow.locator(SEL.familyCount));
    expect(reloadedRowBox.width).toBeGreaterThanOrEqual(listBox.width - 1);
    expect(reloadedCountBox.y + reloadedCountBox.height).toBeLessThanOrEqual(
      reloadedRowBox.y + reloadedRowBox.height + 1,
    );
  });
});
