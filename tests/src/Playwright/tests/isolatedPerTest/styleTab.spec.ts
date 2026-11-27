import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';
import { installStylePolyfill } from '../../utils/styles.js';

/**
 * The Style tab for a component with a named part (experimental).
 *
 * The teaser SDC accepts `spacing` on its root and has a `title` part that
 * accepts `spacing` and `background_color`. Root styles land on the
 * `<article>`, title styles on the `<h2>` through `title_attributes`.
 *
 * The polyfill lets this run on cores without the Style API; it does nothing
 * once core provides it. Installing canvas_test_styles turns on
 * `experimental.styles`.
 *
 * @see tests/modules/canvas_test_styles/components/teaser
 * @see docs/adr/0027-component-styles-on-the-core-style-api.md
 */

const TEASER = 'sdc.canvas_test_styles.teaser';
const ARTICLE = 'article.teaser';
const TITLE = 'article.teaser h2.teaser__title';

test.describe('Style tab', () => {
  test.use({ modules: ['canvas_test_styles'] });

  test('Root and part styles land on their own elements', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    await installStylePolyfill(page);
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: TEASER });
    await canvas.clickPreviewComponent(TEASER);

    await page.getByTestId('canvas-settings-tab-style').click();
    const tab = page.getByTestId('canvas-style-tab');
    await expect(tab).toBeVisible();

    // The root's styles are grouped by category; the part has its own group
    // under its label.
    await expect(
      tab.getByTestId('canvas-style-category-Spacing'),
    ).toBeVisible();
    const titlePart = tab.getByTestId('canvas-style-part-title');
    await expect(titlePart).toBeVisible();
    await expect(titlePart).toHaveAttribute('aria-label', 'Title');
    await expect(titlePart.getByText('Title', { exact: true })).toBeVisible();

    // Root Spacing: Large (`p-8`) goes on the article only. The root's
    // Spacing takes custom values, so its options are in the field's menu;
    // the title's does not, so it gets a segmented control.
    const rootSpacing = tab.getByTestId('canvas-style-spacing');
    await rootSpacing
      .getByRole('button', { name: 'Unit and options for Spacing' })
      .click();
    await page.getByRole('menuitemcheckbox', { name: 'Large' }).click();
    await expect(rootSpacing).toHaveAttribute('data-style-set', 'true');
    await canvas.testInPreviewFrame(ARTICLE, async (article) => {
      await expect(article).toHaveClass(/\bp-8\b/);
    });
    await canvas.testInPreviewFrame(TITLE, async (title) => {
      await expect(title).not.toHaveClass(/\bp-8\b/);
    });

    // Title Spacing: Small (`p-2`) goes on the h2; the article keeps `p-8`.
    const titleSpacing = titlePart.getByTestId('canvas-style-title.spacing');
    const small = titleSpacing.getByRole('radio', { name: 'Small' });
    await small.click();
    await expect(titleSpacing).toHaveAttribute('data-style-set', 'true');
    await canvas.testInPreviewFrame(TITLE, async (title) => {
      await expect(title).toHaveClass(/\bp-2\b/);
      await expect(title).not.toHaveClass(/\bp-8\b/);
    });
    await canvas.testInPreviewFrame(ARTICLE, async (article) => {
      await expect(article).toHaveClass(/\bp-8\b/);
      await expect(article).not.toHaveClass(/\bp-2\b/);
    });

    // Choosing the selected option again clears it, as its own undo step,
    // even right after setting it.
    await small.click();
    await expect(titleSpacing).toHaveAttribute('data-style-set', 'false');
    await canvas.testInPreviewFrame(TITLE, async (title) => {
      await expect(title).not.toHaveClass(/\bp-2\b/);
    });
    await canvas.testInPreviewFrame(ARTICLE, async (article) => {
      await expect(article).toHaveClass(/\bp-8\b/);
    });

    // Undo restores the cleared part style.
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(titleSpacing).toHaveAttribute('data-style-set', 'true');
    await canvas.testInPreviewFrame(TITLE, async (title) => {
      await expect(title).toHaveClass(/\bp-2\b/);
    });
  });
});
