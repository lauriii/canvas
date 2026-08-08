import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Locator, Page } from '@playwright/test';

// @cspell:ignore colormaster

// Shared selector prefixes
const POP_SEL = '[data-state="open"][data-testid="canvas-color-form-popover"]';
const FORM_SEL = 'form[data-form-id="component_instance_form"]';

interface ColorSectionExpectation {
  cssColorValue: string;
  displayedCssColorValue?: string;
  cssVar: string | null;
  colorName: string | null;
  colorSpace: string;
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

async function assertColorSection(
  component: Locator,
  name: string,
  expected: ColorSectionExpectation,
): Promise<void> {
  await expect(component.locator(PROP.preview.section(name))).toBeVisible();
  await expect(component.locator(PROP.preview.swatch(name))).toHaveAttribute(
    'style',
    new RegExp(`background-color: ${escapeRegExp(expected.cssColorValue)}`),
  );
  await expect(component.locator(PROP.preview.cssColorValue(name))).toHaveText(
    expected.displayedCssColorValue ?? expected.cssColorValue,
  );
  await expect(component.locator(PROP.preview.cssVar(name))).toHaveText(
    expected.cssVar ?? '',
  );
  await expect(component.locator(PROP.preview.colorName(name))).toHaveText(
    expected.colorName ?? '',
  );
  await expect(component.locator(PROP.preview.colorSpace(name))).toHaveText(
    expected.colorSpace,
  );
}

async function openColorPropPopover(
  page: Page,
  propName: string,
): Promise<void> {
  await page.locator(PROP.form.trigger(propName)).click();
  await expect(page.locator(PROP.popover.open)).toBeVisible();
}

async function setFreeformColor(
  page: Page,
  rgba: { r: number; g: number; b: number; a: number },
): Promise<void> {
  await page.locator(PROP.popover.rgba.r).fill(String(rgba.r));
  await page.locator(PROP.popover.rgba.g).fill(String(rgba.g));
  await page.locator(PROP.popover.rgba.b).fill(String(rgba.b));
  await page.locator(PROP.popover.rgba.a).fill(String(rgba.a));
  await page.keyboard.press('Escape');
}

async function selectKitColor(page: Page, colorName: string): Promise<void> {
  await page.locator(PROP.popover.colorRow(colorName)).click();
}

async function assertPropTrigger(
  page: Page,
  propName: string,
  expected: { label: string; swatchPattern: RegExp; triggerLabel?: string },
): Promise<void> {
  await expect(page.locator(PROP.form.label(propName))).toHaveText(
    expected.label,
  );
  if (expected.triggerLabel) {
    await expect(page.locator(PROP.form.triggerLabel(propName))).toHaveText(
      expected.triggerLabel,
    );
  }
  await expect(page.locator(PROP.form.triggerSwatch(propName))).toHaveAttribute(
    'style',
    expected.swatchPattern,
  );
}

const SEL = {
  newBtn: '[data-testid="canvas-brand-kit-colors-new-button"]',
  newColorBtn: '[data-testid="canvas-brand-kit-colors-new-color-button"]',
  newFolderBtn: '[data-testid="canvas-brand-kit-colors-new-folder-button"]',
  form: {
    name: `${POP_SEL} [data-testid="canvas-color-name-input"]`,
    variable: `${POP_SEL} [data-testid="canvas-color-variable-input"]`,
    rgba: {
      r: `${POP_SEL} [data-input-mode="rgba"] #color-r`,
      g: `${POP_SEL} [data-input-mode="rgba"] #color-g`,
      b: `${POP_SEL} [data-input-mode="rgba"] #color-b`,
      a: `${POP_SEL} [data-input-mode="rgba"] #color-a-rgba`,
    },
    hsla: {
      h: `${POP_SEL} [data-input-mode="hsla"] #color-h`,
      s: `${POP_SEL} [data-input-mode="hsla"] #color-s`,
      l: `${POP_SEL} [data-input-mode="hsla"] #color-l`,
      a: `${POP_SEL} [data-input-mode="hsla"] #color-a-hsla`,
    },
    hex: `${POP_SEL} [data-input-mode="hex"] #color-hex`,
    switchFmt: `${POP_SEL} [aria-label="Switch color format"]`,
    switchModeFrom: (currentMode: string) =>
      `${POP_SEL} [data-input-mode="${currentMode}"] [aria-label="Switch color format"]`,
    cancel: `${POP_SEL} [data-testid="canvas-color-cancel-button"]`,
    save: `${POP_SEL} [data-testid="canvas-color-save-button"]`,
    header: `${POP_SEL} [data-testid="canvas-color-form-header"]`,
    curSwatch: `${POP_SEL} [data-testid="canvas-color-current-swatch"]`,
    prevSwatch: `${POP_SEL} [data-testid="canvas-color-preview-swatch"]`,
    info: `${POP_SEL} [data-testid="canvas-color-edit-info"]`,
  },
  folderNew: {
    content: '[data-testid="canvas-manage-library-add-folder-content"]',
    nameInput: '[data-testid="canvas-manage-library-new-folder-name"]',
  },
  search: '[aria-label="Search colors"]',
  tab: '[data-testid="canvas-brand-kit-colors-tab-select"]',
  row: (name: string) => `[data-testid="canvas-color-row-${name}"]`,
  // First child div of the row is the color swatch (inline style with background-color)
  rowSwatch: (name: string) =>
    `[data-testid="canvas-color-row-${name}"] > div:first-child`,
  rowMenu: (name: string) =>
    `[data-testid="canvas-color-row-${name}"] [aria-label="Open contextual menu"]`,
  folder: (name: string) => `[data-canvas-folder-name="${name}"]`,
  folderRow: (folder: string, color: string) =>
    `[data-canvas-folder-name="${folder}"] ~ * [data-testid="canvas-color-row-${color}"]`,
  folderRowMenu: (folder: string, color: string) =>
    `[data-canvas-folder-name="${folder}"] ~ * [data-testid="canvas-color-row-${color}"] [aria-label="Open contextual menu"]`,
  folderCount: (name: string) =>
    `[data-canvas-folder-name="${name}"] [class*="_folderCount"]`,
  folderMenuOpen: (name: string) =>
    `[data-canvas-folder-name="${name}"] [aria-label="Open contextual menu"]`,
  folderMenu: {
    addColor: '[data-testid="canvas-brand-kit-colors-folder-add-color-button"]',
    rename: '[data-testid="canvas-rename-folder-button"]',
    delete: '[data-testid="canvas-delete-folder-button"]',
  },
  menu: {
    edit: '[data-state="open"] [data-testid="canvas-color-row-edit"]',
    rename: '[data-testid="canvas-color-row-rename"]',
    instances: '[data-testid="canvas-color-row-find-instances"]',
    delete: '[data-testid="canvas-color-row-delete"]',
  },
  renameInput: '[data-testid="canvas-color-row-rename-input"]',
  deleteConfirm: '[data-testid="canvas-color-delete-confirm-button"]',
  instancesPop: '[data-testid="canvas-find-color-instances-popover"]',
};

const PROP = {
  preview: {
    section: (name: string) => `[data-color-${name}]`,
    swatch: (name: string) => `[data-color-${name}] > div:first-child`,
    cssColorValue: (name: string) =>
      `[data-color-${name}] [data-css-color-value]`,
    cssVar: (name: string) => `[data-color-${name}] [data-css-var]`,
    colorName: (name: string) => `[data-color-${name}] [data-color-name]`,
    colorSpace: (name: string) => `[data-color-${name}] [data-color-space]`,
    cssVarPastel: '[data-var-pastel]',
    cssVarNeon: '[data-var-neon]',
  },
  form: (() => {
    const field = (name: string) =>
      `${FORM_SEL} .field--name-${name.toLowerCase()}`;
    return {
      field,
      trigger: (name: string) => `${field(name)} button`,
      triggerLabel: (name: string) =>
        `${field(name)} [class*="_triggerLabel_"]`,
      triggerSwatch: (name: string) =>
        `${field(name)} [class*="_swatchTrigger_"]`,
      label: (name: string) => `${field(name)} label`,
    };
  })(),
  popover: {
    open: '[role="dialog"][data-state="open"]',
    kitFolders: '[data-state="open"] [data-color-folder]',
    colorRow: (name: string) =>
      `[data-state="open"] [data-color-row="${name}"]`,
    colorRows: '[data-state="open"] [data-color-row]',
    rgba: {
      r: '[data-state="open"] #color-r',
      g: '[data-state="open"] #color-g',
      b: '[data-state="open"] #color-b',
      a: '[data-state="open"] #color-a-rgba',
    },
  },
};

test.use({
  modules: ['canvas_dev_mode'],
  enableTestExtensions: true,
});

test.describe('brand kit colors', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();

    await drupal.installModules(['canvas_test_code_components_color']);
    await drupal.createRole({ name: 'colormaster' });
    await drupal.createUser({
      email: `colormaster@example.com`,
      username: 'colormaster',
      password: 'colormaster',
      roles: ['colormaster'],
    });
    await drupal.addPermissions({
      role: 'colormaster',
      permissions: [
        'create canvas_page',
        'edit canvas_page',
        'publish auto-saves',
        'administer code components',
        'administer brand kit',
        'administer folders',
      ],
    });
    await drupal.logout();
  });

  test('basic color', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'colormaster', password: 'colormaster' });
    await canvas.openCanvasRoot();
    await canvas.openBrandKitPanel();

    // - There should already be three color rows, "Brand Green", "Brand Red", "Brand Blue"
    await expect(page.locator(SEL.row('Brand Green'))).toBeVisible();
    await expect(page.locator(SEL.row('Brand Red'))).toBeVisible();
    await expect(page.locator(SEL.row('Brand Blue'))).toBeVisible();

    // - Verify each color loads in its expected format based on how it was created:
    //   * Brand Green (hsl) → should open in HSLA mode
    //   * Brand Blue (srgb without hex) → should open in RGBA mode
    // Note that a color created in hex will save/open as RGB, as hex is just
    // another way of representing RGB.

    // Edit Brand Green - should open in HSLA mode (HSL colorSpace)
    await page.locator(SEL.row('Brand Green')).hover();
    await page.locator(SEL.rowMenu('Brand Green')).click();
    await page.locator(SEL.menu.edit).click();
    await expect(page.locator(SEL.form.hsla.h)).toBeVisible();
    await expect(page.locator(SEL.form.hsla.h)).toHaveValue('142');
    await expect(page.locator(SEL.form.hsla.s)).toHaveValue('100');
    await expect(page.locator(SEL.form.hsla.l)).toHaveValue('33');
    await page.locator(SEL.form.cancel).click();

    // Edit Brand Blue - should open in RGBA mode (srgb without hex)
    await page.locator(SEL.row('Brand Blue')).hover();
    await page.locator(SEL.rowMenu('Brand Blue')).click();
    await page.locator(SEL.menu.edit).click();
    // Brand Blue is in sRGB mode without hex, so should default to RGBA
    await expect(page.locator(SEL.form.rgba.r)).toBeVisible();
    await expect(page.locator(SEL.form.rgba.r)).toHaveValue('0');
    await expect(page.locator(SEL.form.rgba.g)).toHaveValue('68');
    await expect(page.locator(SEL.form.rgba.b)).toHaveValue('204');
    await expect(page.locator(SEL.form.rgba.a)).toHaveValue('0.9');

    // - Edit "Brand Blue" so the color is now yellow (255, 255, 0)
    // Color picker is already open from format verification above

    // Test validation: enter out-of-range RGB value should disable save
    await page.locator(SEL.form.rgba.r).fill('999');
    await expect(page.locator(SEL.form.save)).toBeDisabled();

    // Fix to valid value and continue
    await page.locator(SEL.form.rgba.r).fill('255');
    await expect(page.locator(SEL.form.save)).toBeEnabled();

    await page.locator(SEL.form.rgba.g).fill('255');
    await page.locator(SEL.form.rgba.b).fill('0');
    // Assert preview swatch updates to yellow before saving
    await expect(page.locator(SEL.form.prevSwatch)).toHaveAttribute(
      'style',
      /background-color: rgba\(255, 255, 0, 0.9\)/,
    );
    await page.locator(SEL.form.save).click();
    // Assert the row swatch style includes the new yellow color
    await expect(page.locator(SEL.rowSwatch('Brand Blue'))).toHaveAttribute(
      'style',
      /background-color: rgba\(255, 255, 0, 0.9\)/,
    );

    // - Rename "Brand Blue" to "Brand Yellow"
    await page.locator(SEL.row('Brand Blue')).hover();
    await page.locator(SEL.rowMenu('Brand Blue')).click();
    await page.locator(SEL.menu.rename).click();
    await expect(page.locator(SEL.renameInput)).toBeVisible();
    await expect(page.locator(SEL.menu.rename)).toBeHidden();
    await page.locator(SEL.renameInput).fill('Brand Yellow');
    await page.locator(SEL.renameInput).press('Enter');
    await expect(page.locator(SEL.row('Brand Yellow'))).toBeVisible();
    await expect(page.locator(SEL.row('Brand Blue'))).toBeHidden();

    // - Create a folder called "Color Pocket"
    await page.locator(SEL.newBtn).click();
    await page.locator(SEL.newFolderBtn).click();
    await expect(page.locator(SEL.folderNew.content)).toBeVisible();
    await expect(page.locator(SEL.newFolderBtn)).toBeHidden();
    await page.locator(SEL.folderNew.nameInput).fill('Color Pocket');
    await page.locator(SEL.folderNew.nameInput).press('Enter');
    await expect(page.locator(SEL.folder('Color Pocket'))).toBeVisible();

    // Drag "Brand Yellow" into "Color Pocket"
    const sourceBox = (await page
      .locator(SEL.row('Brand Yellow'))
      .boundingBox())!;
    const targetBox = (await page
      .locator(SEL.folder('Color Pocket'))
      .boundingBox())!;
    await page.mouse.move(
      sourceBox.x + sourceBox.width / 2,
      sourceBox.y + sourceBox.height / 2,
    );
    await page.mouse.down();
    await page.mouse.move(
      sourceBox.x + sourceBox.width / 2,
      sourceBox.y + sourceBox.height / 2 + 10,
      { steps: 5 },
    );
    await page.mouse.move(
      targetBox.x + targetBox.width / 2,
      targetBox.y + targetBox.height / 2,
      { steps: 10 },
    );
    await page.mouse.up();
    await expect(
      page.locator(SEL.folderRow('Color Pocket', 'Brand Yellow')),
    ).toBeVisible();
    // Folder count should now reflect 1 item
    await expect(page.locator(SEL.folderCount('Color Pocket'))).toHaveText('1');

    // Add "New Blue" (0, 0, 255) inside "Color Pocket"
    await page.locator(SEL.folder('Color Pocket')).hover();
    await page.locator(SEL.folderMenuOpen('Color Pocket')).click();
    await page.locator(SEL.folderMenu.addColor).click();
    await page.locator(SEL.form.name).fill('New Blue');

    // Test validation: negative alpha value should disable save
    await page.locator(SEL.form.rgba.a).fill('-0.5');
    await expect(page.locator(SEL.form.save)).toBeDisabled();

    // Fix alpha and continue with blue color
    await page.locator(SEL.form.rgba.a).fill('1');
    await expect(page.locator(SEL.form.save)).toBeEnabled();

    // Enter blue in RGBA (starting mode assumed to be RGBA)
    await page.locator(SEL.form.rgba.r).fill('0');
    await page.locator(SEL.form.rgba.g).fill('0');
    await page.locator(SEL.form.rgba.b).fill('255');

    // Before saving, switch color modes and assert values translate correctly
    // Switch RGBA → HSLA.
    await page.locator(SEL.form.switchModeFrom('rgba')).click();
    await expect(page.locator(SEL.form.hsla.h)).toHaveValue('240');
    await expect(page.locator(SEL.form.hsla.s)).toHaveValue('100');
    await expect(page.locator(SEL.form.hsla.l)).toHaveValue('50');

    // Test HSLA validation: out-of-range hue should disable save
    await page.locator(SEL.form.hsla.h).fill('500');
    await expect(page.locator(SEL.form.save)).toBeDisabled();

    // Fix back to valid hue
    await page.locator(SEL.form.hsla.h).fill('240');
    await expect(page.locator(SEL.form.save)).toBeEnabled();

    // Switch HSLA → HEX.
    await page.locator(SEL.form.switchModeFrom('hsla')).click();
    await expect(page.locator(SEL.form.hex)).toHaveValue('0000FF');

    // Test HEX validation: invalid characters should disable save
    await page.locator(SEL.form.hex).fill('GGGGGG');
    await expect(page.locator(SEL.form.save)).toBeDisabled();

    // Test HEX validation: too short should disable save
    await page.locator(SEL.form.hex).fill('FFF');
    await expect(page.locator(SEL.form.save)).toBeDisabled();

    // Fix to valid hex
    await page.locator(SEL.form.hex).fill('0000FF');
    await expect(page.locator(SEL.form.save)).toBeEnabled();

    await page.locator(SEL.form.switchModeFrom('hex')).click();
    await page.locator(SEL.form.rgba.a).fill('0.9');

    await page.locator(SEL.form.switchFmt).click();
    await expect(page.locator(SEL.form.hsla.h)).toHaveValue('240');
    await expect(page.locator(SEL.form.hsla.s)).toHaveValue('100');
    await expect(page.locator(SEL.form.hsla.l)).toHaveValue('50');
    await expect(page.locator(SEL.form.hsla.a)).toHaveValue('0.9');

    // Assert HEX reflects the new alpha.
    await page.locator(SEL.form.switchFmt).click();
    await expect(page.locator(SEL.form.hex)).toHaveValue('0000FFE5');

    // Save the color, confirm it was added inside "Color Pocket"
    await page.locator(SEL.form.switchFmt).click();
    await page.locator(SEL.form.save).click();
    await expect(
      page.locator(SEL.folderRow('Color Pocket', 'New Blue')),
    ).toBeVisible();
    // Folder count should now show 2 (Brand Yellow + New Blue)
    await expect(page.locator(SEL.folderCount('Color Pocket'))).toHaveText('2');

    // Add a new color via newBtn, call it "Groovy Gray", make it gray.
    await page.locator(SEL.newBtn).click();
    await page.locator(SEL.newColorBtn).click();
    await page.locator(SEL.form.name).fill('Groovy Gray');

    // Test additional validation scenarios on this new color
    // Start in RGBA, set initial values
    await page.locator(SEL.form.rgba.r).fill('128');
    await page.locator(SEL.form.rgba.g).fill('128');
    await page.locator(SEL.form.rgba.b).fill('128');

    // Switch to HSLA and test validation (RGBA → HSLA)
    await page.locator(SEL.form.switchFmt).click();
    await expect(page.locator(SEL.form.hsla.h)).toBeVisible();

    // Test saturation > 100 disables save
    await page.locator(SEL.form.hsla.s).fill('150');
    await expect(page.locator(SEL.form.save)).toBeDisabled();

    // Test lightness > 100 disables save
    // Fix saturation first
    await page.locator(SEL.form.hsla.s).fill('0');
    await page.locator(SEL.form.hsla.l).fill('200');
    await expect(page.locator(SEL.form.save)).toBeDisabled();

    // Fix lightness to valid value and save
    await page.locator(SEL.form.hsla.l).fill('50');
    await expect(page.locator(SEL.form.save)).toBeEnabled();
    await page.locator(SEL.form.save).click();
    // Confirm it appears in the top-level list (not inside any folder)
    await expect(page.locator(SEL.row('Groovy Gray'))).toBeVisible();
    await expect(
      page.locator(SEL.folderRow('Color Pocket', 'Groovy Gray')),
    ).toBeHidden();
  });

  test('code component color prop', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'colormaster', password: 'colormaster' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'js.canvas_test_code_components_color_three_colors',
    });

    // Verify initial render in the preview frame.
    await canvas.testInPreviewFrame('canvas-island', async (component) => {
      await assertColorSection(component, 'free', {
        cssColorValue: 'rgba(104, 125, 247, 0.89)',
        cssVar: null,
        colorName: null,
        colorSpace: 'srgb',
      });
      await assertColorSection(component, 'pastel', {
        cssColorValue: 'rgb(255, 173, 255)',
        cssVar: '--baguette-legs',
        colorName: 'Baguette Legs',
        colorSpace: 'srgb',
      });
      await assertColorSection(component, 'neon', {
        cssColorValue: 'rgb(255, 108, 108)',
        cssVar: '--santa-face',
        colorName: 'Father Christmas',
        colorSpace: 'srgb',
      });
      await assertColorSection(component, 'two', {
        cssColorValue: 'rgb(255, 173, 255)',
        cssVar: '--baguette-legs',
        colorName: 'Baguette Legs',
        colorSpace: 'srgb',
      });
      await assertColorSection(component, 'all', {
        cssColorValue: 'rgb(255, 0, 0)',
        displayedCssColorValue: 'rgba(255, 0, 0, 1.00)',
        cssVar: null,
        colorName: null,
        colorSpace: 'srgb',
      });

      const pastelVar = component.locator(PROP.preview.cssVarPastel);
      const neonVar = component.locator(PROP.preview.cssVarNeon);
      await expect(pastelVar).toHaveAttribute(
        'style',
        /background-color: var\(--baguette-legs\)/,
      );
      await expect(neonVar).toHaveAttribute(
        'style',
        /background-color: var\(--santa-face\)/,
      );
      expect(
        await pastelVar.evaluate(
          (el) => window.getComputedStyle(el).backgroundColor,
        ),
      ).toBe('rgb(255, 173, 255)');
      expect(
        await neonVar.evaluate(
          (el) => window.getComputedStyle(el).backgroundColor,
        ),
      ).toBe('rgb(255, 108, 108)');
    });

    // Verify initial prop form state.
    await assertPropTrigger(page, 'free', {
      label: 'free',
      triggerLabel: '#687DF7',
      swatchPattern: /background-color: rgba\(104, 125, 247, 0\.89\)/,
    });
    await assertPropTrigger(page, 'brandPastel', {
      label: 'Brand Pastel',
      triggerLabel: 'Baguette Legs',
      swatchPattern: /background-color: rgb\(255, 173, 255\)/,
    });
    await assertPropTrigger(page, 'brandNeon', {
      label: 'Brand Neon',
      triggerLabel: 'Father Christmas',
      swatchPattern: /background-color: rgb\(255, 108, 108\)/,
    });
    await assertPropTrigger(page, 'brandAll', {
      label: 'brand - all',
      triggerLabel: '#FF0000',
      swatchPattern: /background-color: rgb\(255, 0, 0\)/,
    });
    await assertPropTrigger(page, 'brandTwoFolders', {
      label: 'brand - two folders',
      triggerLabel: 'Baguette Legs',
      swatchPattern: /background-color: rgb\(255, 173, 255\)/,
    });

    // Change `free` to semi-transparent green via freeform RGBA.
    // free has no folder restriction — both folders and all 7 colors must be present.
    await openColorPropPopover(page, 'free');
    await expect(page.locator(PROP.popover.kitFolders)).toHaveCount(2);
    await expect(page.locator(PROP.popover.colorRows)).toHaveCount(7);
    await setFreeformColor(page, { r: 0, g: 255, b: 0, a: 0.5 });
    await expect(page.locator(PROP.popover.open)).toBeHidden();
    await assertPropTrigger(page, 'free', {
      label: 'free',
      swatchPattern: /background-color: rgba\(0, 255, 0, 0\.5\)/,
    });
    await canvas.testInPreviewFrame('canvas-island', async (component) => {
      await assertColorSection(component, 'free', {
        displayedCssColorValue: 'rgba(0, 255, 0, 0.50)',
        cssColorValue: 'rgba(0, 255, 0, 0.5)',
        cssVar: null,
        colorName: null,
        colorSpace: 'srgb',
      });
    });

    // Change `brandPastel` → "Wow Monster".
    // brandPastel is restricted to Pastel Lab Revelation only — 1 folder, 2 colors.
    await openColorPropPopover(page, 'brandPastel');
    await expect(page.locator(PROP.popover.kitFolders)).toHaveCount(1);
    await expect(page.locator(PROP.popover.colorRows)).toHaveCount(2);
    await selectKitColor(page, 'Wow Monster');
    await expect(page.locator(PROP.popover.open)).toBeHidden();
    await assertPropTrigger(page, 'brandPastel', {
      label: 'Brand Pastel',
      swatchPattern: /background-color: rgb\(116, 211, 217\)/,
    });
    await canvas.testInPreviewFrame('canvas-island', async (component) => {
      await assertColorSection(component, 'pastel', {
        cssColorValue: 'rgb(116, 211, 217)',
        cssVar: '--wow-monster',
        colorName: 'Wow Monster',
        colorSpace: 'srgb',
      });
    });

    // Change `brandNeon` → "Doctor Cigarettes".
    // brandNeon is restricted to Absolute Neon Casserole only — 1 folder, 2 colors.
    await openColorPropPopover(page, 'brandNeon');
    await expect(page.locator(PROP.popover.kitFolders)).toHaveCount(1);
    await expect(page.locator(PROP.popover.colorRows)).toHaveCount(2);
    await selectKitColor(page, 'Doctor Cigarettes');
    await expect(page.locator(PROP.popover.open)).toBeHidden();
    await assertPropTrigger(page, 'brandNeon', {
      label: 'Brand Neon',
      swatchPattern: /background-color: rgb\(125, 117, 94\)/,
    });
    await canvas.testInPreviewFrame('canvas-island', async (component) => {
      await assertColorSection(component, 'neon', {
        cssColorValue: 'rgb(125, 117, 94)',
        cssVar: '--doctor-cigarettes',
        colorName: 'Doctor Cigarettes',
        colorSpace: 'srgb',
      });
    });

    // Change `brandAll` → "Brand Green".
    // brandAll has no folder restriction — both folders and all 7 colors must be present.
    await openColorPropPopover(page, 'brandAll');
    await expect(page.locator(PROP.popover.kitFolders)).toHaveCount(2);
    await expect(page.locator(PROP.popover.colorRows)).toHaveCount(7);
    await selectKitColor(page, 'Brand Green');
    await expect(page.locator(PROP.popover.open)).toBeHidden();
    await assertPropTrigger(page, 'brandAll', {
      label: 'brand - all',
      swatchPattern: /background-color: rgb\(0, 168, 62\)/,
    });
    await canvas.testInPreviewFrame('canvas-island', async (component) => {
      await assertColorSection(component, 'all', {
        cssColorValue: 'rgb(0, 168, 62)',
        displayedCssColorValue: 'hsl(142, 100%, 33%)',

        cssVar: '--brand-green',
        colorName: 'Brand Green',
        colorSpace: 'hsl',
      });
    });

    // Change `brandTwoFolders` → "Father Christmas" — both Pastel + Neon folders, 4 colors, no top-level.
    await openColorPropPopover(page, 'brandTwoFolders');
    await expect(page.locator(PROP.popover.kitFolders)).toHaveCount(2);
    await expect(page.locator(PROP.popover.colorRows)).toHaveCount(4);
    await selectKitColor(page, 'Father Christmas');
    await expect(page.locator(PROP.popover.open)).toBeHidden();
    await assertPropTrigger(page, 'brandTwoFolders', {
      label: 'brand - two folders',
      swatchPattern: /background-color: rgb\(255, 108, 108\)/,
    });
    await canvas.testInPreviewFrame('canvas-island', async (component) => {
      await assertColorSection(component, 'two', {
        cssColorValue: 'rgb(255, 108, 108)',
        cssVar: '--santa-face',
        colorName: 'Father Christmas',
        colorSpace: 'srgb',
      });
    });

    // Edit "Father Christmas" in the brand kit — change it to a new color (0, 0, 128).
    // `brandTwoFolders` is currently bound to this color, so both the form trigger
    // swatch and the preview should update in real time without a page refresh.
    await canvas.openBrandKitPanel();
    await page.locator(SEL.row('Father Christmas')).hover();
    await page.locator(SEL.rowMenu('Father Christmas')).click();
    await page.locator(SEL.menu.edit).click();
    await page.locator(SEL.form.rgba.r).fill('0');
    await page.locator(SEL.form.rgba.g).fill('0');
    await page.locator(SEL.form.rgba.b).fill('128');
    await page.locator(SEL.form.rgba.a).fill('1');
    await page.locator(SEL.form.save).click();
    await expect(
      page.locator(SEL.rowSwatch('Father Christmas')),
    ).toHaveAttribute('style', /background-color: rgb\(0, 0, 128\)/);

    // The form trigger swatch for `brandTwoFolders` should reflect the new color.
    await assertPropTrigger(page, 'brandTwoFolders', {
      label: 'brand - two folders',
      swatchPattern: /background-color: rgb\(0, 0, 128\)/,
    });

    // The preview should also update in real time to show the new color.
    await canvas.testInPreviewFrame('canvas-island', async (component) => {
      await assertColorSection(component, 'two', {
        cssColorValue: 'rgb(0, 0, 128)',
        cssVar: '--santa-face',
        colorName: 'Father Christmas',
        colorSpace: 'srgb',
      });
    });
  });

  test('optimistic color edits', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'colormaster', password: 'colormaster' });
    await canvas.openCanvasRoot();
    await canvas.openBrandKitPanel();

    const redSwatch = page.locator(SEL.rowSwatch('Brand Red'));
    await expect(redSwatch).toHaveCSS('background-color', 'rgb(204, 0, 0)');

    // Hold the write open so the assertions below land while it is in flight.
    let releaseWrite: () => void = () => {};
    const writeHeld = new Promise<void>((resolve) => {
      releaseWrite = resolve;
    });
    await page.route(/\/canvas\/api\/v0\/config\/color\/.+/, async (route) => {
      if (route.request().method() !== 'PATCH') {
        await route.continue();
        return;
      }
      await writeHeld;
      await route.continue();
    });

    // Edit "Brand Red" to pure blue (0, 0, 255).
    await page.locator(SEL.row('Brand Red')).hover();
    await page.locator(SEL.rowMenu('Brand Red')).click();
    await page.locator(SEL.menu.edit).click();
    await expect(page.locator(SEL.form.rgba.r)).toBeVisible();
    await page.locator(SEL.form.rgba.r).fill('0');
    await page.locator(SEL.form.rgba.g).fill('0');
    await page.locator(SEL.form.rgba.b).fill('255');
    await page.locator(SEL.form.save).click();

    // The row swatch shows the new color while the request is still open, and
    // the form has already closed. This is the whole point of the optimistic
    // write: nothing waits for the server.
    await expect(redSwatch).toHaveCSS('background-color', 'rgb(0, 0, 255)');
    await expect(page.locator(POP_SEL)).toBeHidden();

    releaseWrite();
    await expect(redSwatch).toHaveCSS('background-color', 'rgb(0, 0, 255)');
  });

  test('rolls back a rejected color edit', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'colormaster', password: 'colormaster' });
    await canvas.openCanvasRoot();
    await canvas.openBrandKitPanel();

    // Capture the stored color rather than hard-coding the hsl() conversion,
    // so this asserts "restored to what it was" without depending on how the
    // browser rounds the components.
    const greenSwatch = page.locator(SEL.rowSwatch('Brand Green'));
    const storedGreen = await greenSwatch.evaluate(
      (element) => getComputedStyle(element).backgroundColor,
    );

    await page.route(/\/canvas\/api\/v0\/config\/color\/.+/, async (route) => {
      if (route.request().method() !== 'PATCH') {
        await route.continue();
        return;
      }
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ errors: [{ detail: 'Rejected by test.' }] }),
      });
    });

    await page.locator(SEL.row('Brand Green')).hover();
    await page.locator(SEL.rowMenu('Brand Green')).click();
    await page.locator(SEL.menu.edit).click();
    await expect(page.locator(SEL.form.rgba.r)).toBeVisible();
    await page.locator(SEL.form.rgba.r).fill('255');
    await page.locator(SEL.form.rgba.g).fill('0');
    await page.locator(SEL.form.rgba.b).fill('255');
    await page.locator(SEL.form.save).click();

    // The rejected value must not survive anywhere in the UI.
    await expect(greenSwatch).toHaveCSS('background-color', storedGreen);
    // The form closes as soon as the edit is applied, so the failure is
    // reported by the colors section rather than by the form.
    await expect(page.locator(POP_SEL)).toBeHidden();
    await expect(page.getByText(/Failed to update color/)).toBeVisible();
  });

  test('restores a color when deleting it fails', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'colormaster', password: 'colormaster' });
    await canvas.openCanvasRoot();
    await canvas.openBrandKitPanel();

    await page.route(/\/canvas\/api\/v0\/config\/color\/.+/, async (route) => {
      if (route.request().method() !== 'DELETE') {
        await route.continue();
        return;
      }
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ errors: [{ detail: 'Rejected by test.' }] }),
      });
    });

    await page.locator(SEL.row('Brand Blue')).hover();
    await page.locator(SEL.rowMenu('Brand Blue')).click();
    await page.locator(SEL.menu.delete).click();
    await page.locator(SEL.deleteConfirm).click();

    // The row must come back rather than silently vanishing from a failed write.
    await expect(page.locator(SEL.row('Brand Blue'))).toBeVisible();
  });

  test('reopens the add form on the entered values when the create fails', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'colormaster', password: 'colormaster' });
    await canvas.openCanvasRoot();
    await canvas.openBrandKitPanel();

    await page.route(/\/canvas\/api\/v0\/config\/color$/, async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue();
        return;
      }
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ errors: [{ detail: 'Rejected by test.' }] }),
      });
    });

    await page.locator(SEL.newBtn).click();
    await page.locator(SEL.newColorBtn).click();
    await page.locator(SEL.form.name).fill('Doomed Color');
    await page.locator(SEL.form.rgba.r).fill('1');
    await page.locator(SEL.form.rgba.g).fill('2');
    await page.locator(SEL.form.rgba.b).fill('3');
    await page.locator(SEL.form.save).click();

    // The optimistic row is withdrawn, and the form comes back holding what was
    // entered so it does not have to be retyped.
    await expect(page.locator(SEL.row('Doomed Color'))).toBeHidden();
    await expect(page.locator(SEL.form.name)).toHaveValue('Doomed Color');
    await expect(page.locator(SEL.form.rgba.r)).toHaveValue('1');
    await expect(page.locator(SEL.form.rgba.g)).toHaveValue('2');
    await expect(page.locator(SEL.form.rgba.b)).toHaveValue('3');
    await expect(page.getByText(/Failed to create color/)).toBeVisible();
  });
});
