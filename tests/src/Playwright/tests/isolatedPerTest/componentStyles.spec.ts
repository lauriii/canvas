import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';
import { installStylePolyfill } from '../../utils/styles.js';

import type { Page } from '@playwright/test';

/**
 * Component styles on the core Style API (experimental).
 *
 * The polyfill lets this run on cores without the Style API; it does nothing
 * once core provides it.
 *
 * @see docs/adr/0027-component-styles-on-the-core-style-api.md
 */

const SECTION = 'sdc.canvas_test_styles.section';
const NARROW_CARD = 'sdc.canvas_test_styles.narrow-card';

/** Picks an option of Spacing, which also takes custom values. */
async function pickSpacing(page: Page, option: string): Promise<void> {
  await page
    .getByRole('button', { name: 'Unit and options for Spacing' })
    .click();
  await page.getByRole('menuitemcheckbox', { name: option }).click();
}

test.describe('Component styles', () => {
  test.use({ modules: ['canvas_test_styles'] });

  test('Style tab: set, remove, and undo', async ({ page, drupal, canvas }) => {
    await drupal.loginAsAdmin();
    await installStylePolyfill(page);
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: SECTION });
    await canvas.clickPreviewComponent(SECTION);

    await page.getByTestId('canvas-settings-tab-style').click();
    const tab = page.getByTestId('canvas-style-tab');
    await expect(tab).toBeVisible();

    const spacing = page.getByTestId('canvas-style-spacing');
    await pickSpacing(page, 'Large');
    await expect(spacing).toHaveAttribute('data-style-set', 'true');
    // Radix renders tab labels twice, one hidden to reserve bold width.
    await expect(
      page.getByTestId('canvas-settings-tab-style').getByLabel('1 set').first(),
    ).toBeVisible();
    await canvas.testInPreviewFrame('.styled-section', async (section) => {
      await expect(section).toHaveClass(/\bp-8\b/);
    });

    // Labels too long for the row get a select; unset is "Not set", never
    // "Default".
    const size = page.getByTestId('canvas-style-size');
    await expect(size.getByRole('combobox')).toHaveText('Not set');

    // Remove.
    // The set label opens Reset; choosing the selected option again also
    // clears it.
    await spacing.getByRole('button', { name: 'Spacing', exact: true }).click();
    await page.getByRole('button', { name: 'Reset' }).click();
    await expect(spacing).toHaveAttribute('data-style-set', 'false');
    await canvas.testInPreviewFrame('.styled-section', async (section) => {
      await expect(section).not.toHaveClass(/\bp-8\b/);
    });

    // Undo restores the removed style.
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(spacing).toHaveAttribute('data-style-set', 'true');
  });

  test('Copy and paste styles skips unsupported styles', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    await installStylePolyfill(page);
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: SECTION });
    await canvas.addComponent({ id: NARROW_CARD });

    await canvas.clickPreviewComponent(SECTION);
    await page.getByTestId('canvas-settings-tab-style').click();
    await pickSpacing(page, 'Small');
    await page.getByTestId('canvas-style-rounded').getByRole('switch').click();
    await page.keyboard.press('Meta+Alt+c');
    await expect(page.getByText('Copied 2 styles.')).toBeVisible();

    await canvas.clickPreviewComponent(NARROW_CARD);
    await page.keyboard.press('Meta+Alt+v');
    await expect(
      page.getByText(
        "Rounded corners wasn't pasted on 1 component: it doesn't support it.",
      ),
    ).toBeVisible();
    await canvas.testInPreviewFrame('.narrow-card', async (card) => {
      await expect(card).toHaveClass(/\bp-2\b/);
      await expect(card).not.toHaveClass(/rounded-lg/);
    });
  });

  test('A component that declares no styles shows no style options', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    await installStylePolyfill(page);
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_styles.divider' });
    await canvas.clickPreviewComponent('sdc.canvas_test_styles.divider');
    await page.getByTestId('canvas-settings-tab-style').click();
    await expect(page.getByTestId('canvas-style-tab-empty')).toHaveText(
      'This component has no style options.',
    );
  });
});
