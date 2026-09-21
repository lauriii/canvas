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
  familyList: '[class*="_familyList"]',
  familyRowWrapper: '[class*="_familyRowWrapper"]',
  familyRow: 'button[class*="_familyRow"]',
  familyName: '[class*="_familyName"]',
  familyFormat: '[class*="_familyFormat"]',
  familyBadge: '[class*="_familyBadge"]',
  flyout: '[class*="_flyoutContent"]',
  centerConsole: '[class*="_centerConsole"]',
  rightConsole: '[class*="_rightConsole"]',
  variantRow: 'label[class*="_variantRow"]',
  variantLabel: '[class*="_variantLabel"]',
  variantSpecimen: '[class*="_variantSpecimen"]',
  familyNameInput:
    '[class*="_flyoutContent"] input[aria-label="Font family name"]',
};

const LAYOUT_SCREENSHOT_OPTIONS = {
  // Allow up to 4% of the compared pixels to differ. Measured against these
  // fixtures, worst-case cross-environment aliasing (a full 1px diagonal shift)
  // moves ~2.5% of pixels, while a real content change such as adding new
  // sub headers moves ~6%. A 10% ratio let that content change pass unnoticed;
  // 4% keeps headroom over aliasing while still flagging genuine layout drift.
  maxDiffPixelRatio: 0.04,
  // Raise the per-pixel color-difference threshold so small cross-environment
  // rendering differences are not counted as differing pixels.
  threshold: 0.3,
} as const;

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

    const familyRowWrapper = page.locator(SEL.familyRowWrapper);
    const familyRow = page.locator(SEL.familyRow);
    await expect(familyRow).toBeVisible();

    // Text content (family name, format, badge) and layout are covered by the
    // screenshot assertion below.
    await expect(familyRowWrapper).toHaveScreenshot(
      'family-row-layout.png',
      LAYOUT_SCREENSHOT_OPTIONS,
    );

    // Uploading selects the new font, which opens its family flyout. The
    // flyout carries the design's two consoles side by side: the font's own
    // settings on the left, the example code on the right.
    // @see useFontUpload's onFontUploaded / useBrandKitFontSelection.selectFont
    await expect(page.locator(SEL.flyout)).toBeVisible();
    await expect(page.locator(SEL.flyout)).toHaveScreenshot(
      'font-flyout-layout.png',
      LAYOUT_SCREENSHOT_OPTIONS,
    );

    // Rename the family. The name field commits on blur, and the commit applies
    // to local state before its auto-save PATCH resolves. Wait for the request
    // so the reload below cannot abort a still-in-flight save.
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
    await expect(familyRowWrapper).toHaveScreenshot(
      'reloaded-family-row-layout.png',
      LAYOUT_SCREENSHOT_OPTIONS,
    );
  });

  test('adds and deletes variants, and deletes a family', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'fontmaster', password: 'fontmaster' });
    await canvas.openCanvasRoot();
    await canvas.openBrandKitPanel();
    await page.locator(SEL.tab).click();

    await uploadFont(page, 'mona-sans.woff2');
    await expect(page.locator(SEL.flyout)).toBeVisible();

    // "Add variant" updates the open family, replacing the Preview with a list
    // of variant cards.
    await page
      .locator('[data-testid="canvas-brand-kit-font-add-variant-button"]')
      .click();
    await uploadFont(page, 'mona-sans-bold.woff2');
    await expect(page.locator(SEL.variantRow)).toHaveCount(2);
    await expect(page.locator(SEL.familyRow)).toHaveCount(1);

    // A card displays its label above the font specimen. This also verifies the
    // card's layout against the all: unset reset.
    const variantRow = page.locator(SEL.variantRow).first();
    await expect(variantRow).toHaveScreenshot(
      'variant-card-layout.png',
      LAYOUT_SCREENSHOT_OPTIONS,
    );
    await expect(variantRow).toContainText('400 Normal [WOFF2]');

    // Arrow keys move the selection, which re-scopes the code beside it.
    await page.locator(SEL.variantRow).first().locator('input').focus();
    await page.keyboard.press('ArrowDown');
    await expect(
      page.locator(SEL.variantRow).nth(1).locator('input'),
    ).toBeChecked();

    // The selected card carries the delete affordance.
    await page
      .locator('[data-testid^="canvas-brand-kit-font-variant-delete-"]')
      .click();
    await expect(page.locator(SEL.variantRow)).toHaveCount(0);

    // Close the flyout to access the row's menu and delete the entire font family.
    await page.keyboard.press('Escape');
    await expect(page.locator(SEL.flyout)).toHaveCount(0);
    await page
      .locator('[data-testid="canvas-brand-kit-font-family-menu-Mona Sans"]')
      .click();
    await page
      .locator('[data-testid="canvas-brand-kit-font-family-delete"]')
      .click();
    await expect(page.getByText('No fonts uploaded yet.')).toBeVisible();
  });
});
