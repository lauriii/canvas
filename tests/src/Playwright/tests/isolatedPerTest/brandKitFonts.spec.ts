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

    const familyRowWrapper = page.locator(SEL.familyRowWrapper);
    const familyRow = page.locator(SEL.familyRow);
    await expect(familyRow).toBeVisible();
    await expect(familyRow).toContainText('Mona Sans');
    // The row reports the file format and whether the family is variable.
    await expect(familyRow.locator(SEL.familyFormat)).toHaveText('WOFF2');
    await expect(familyRowWrapper.locator(SEL.familyBadge)).toHaveText(
      'Static',
    );

    // Regression guard: the family row must read as a row. Its name sits on the
    // left and its Variable/Static badge on the right of the same line, and the
    // row spans the list rather than shrinking to its text. When the row's
    // `all: unset` reset stopped being its first declaration, the row lost its
    // layout and the badge dropped onto a second line under the name.
    // @see https://www.drupal.org/i/3591954
    const rowBox = await boxOf(familyRowWrapper);
    const listBox = await boxOf(page.locator(SEL.familyList));
    const nameBox = await boxOf(familyRow.locator(SEL.familyName));
    const badgeBox = await boxOf(familyRowWrapper.locator(SEL.familyBadge));

    expect(rowBox.width).toBeGreaterThanOrEqual(listBox.width - 1);
    expect(rowBox.height).toBeGreaterThan(20);
    // The badge is beside the name, not stacked under it.
    expect(badgeBox.x).toBeGreaterThanOrEqual(nameBox.x + nameBox.width - 1);
    // Both sit inside the row's own height.
    expect(badgeBox.y + badgeBox.height).toBeLessThanOrEqual(
      rowBox.y + rowBox.height + 1,
    );

    // Uploading selects the new font, which opens its family flyout. The
    // flyout carries the design's two consoles side by side: the font's own
    // settings on the left, the example code on the right.
    // @see useFontUpload's onFontUploaded / useBrandKitFontSelection.selectFont
    await expect(page.locator(SEL.flyout)).toBeVisible();
    const centerBox = await boxOf(page.locator(SEL.centerConsole));
    const rightBox = await boxOf(page.locator(SEL.rightConsole));
    expect(rightBox.x).toBeGreaterThanOrEqual(
      centerBox.x + centerBox.width - 1,
    );

    // A family with one file has nothing to choose between, so Preview is the
    // specimen card rather than a list of variant cards.
    await expect(
      page.locator('[data-testid^="canvas-brand-kit-font-preview-"]'),
    ).toBeVisible();
    await expect(page.locator(SEL.variantRow)).toHaveCount(0);

    // The right console names what the code below it is scoped to, and emits
    // the @font-face rule for the selected variant.
    await expect(
      page.locator('[data-testid="canvas-brand-kit-font-code-context"]'),
    ).toContainText('Variant: 400 Normal');
    await expect(
      page.locator('[data-testid^="canvas-brand-kit-font-face-snippet-"]'),
    ).toContainText('font-display: swap;');

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
    const reloadedRowBox = await boxOf(familyRowWrapper);
    const reloadedBadgeBox = await boxOf(
      familyRowWrapper.locator(SEL.familyBadge),
    );
    expect(reloadedRowBox.width).toBeGreaterThanOrEqual(listBox.width - 1);
    expect(reloadedBadgeBox.y + reloadedBadgeBox.height).toBeLessThanOrEqual(
      reloadedRowBox.y + reloadedRowBox.height + 1,
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

    // "Add variant" uploads into the family that is open, so the family keeps
    // one row, and Preview becomes the list of variant cards.
    await page
      .locator('[data-testid="canvas-brand-kit-font-add-variant-button"]')
      .click();
    await uploadFont(page, 'mona-sans-bold.woff2');
    await expect(page.locator(SEL.variantRow)).toHaveCount(2);
    await expect(page.locator(SEL.familyRow)).toHaveCount(1);

    // A card reads as a card: its monospace label above the specimen typeset in
    // that variant. The card carries the same `all: unset` reset as the family
    // row, so this is also the second half of the layout guard above.
    const variantRow = page.locator(SEL.variantRow).first();
    const variantBox = await boxOf(variantRow);
    const labelBox = await boxOf(variantRow.locator(SEL.variantLabel));
    const specimenBox = await boxOf(variantRow.locator(SEL.variantSpecimen));

    expect(variantBox.height).toBeGreaterThan(40);
    expect(labelBox.width).toBeGreaterThan(0);
    expect(centerY(specimenBox)).toBeGreaterThan(centerY(labelBox));
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

    // "Delete font" in the row's overflow menu removes the whole family, which
    // empties the section again. Dismiss the flyout first — it is anchored
    // beside the row and covers the row's own controls.
    await page.keyboard.press('Escape');
    await expect(
      page.locator('[data-radix-popper-content-wrapper]'),
    ).toHaveCount(0);
    await page
      .locator('[data-testid="canvas-brand-kit-font-family-menu-Mona Sans"]')
      .click();
    await page
      .locator('[data-testid="canvas-brand-kit-font-family-delete"]')
      .click();
    await expect(page.getByText('No fonts uploaded yet.')).toBeVisible();
  });
});
