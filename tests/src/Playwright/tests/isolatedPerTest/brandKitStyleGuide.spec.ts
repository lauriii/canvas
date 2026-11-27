import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

/**
 * The brand kit style guide: theme design tokens on core's design tokens API.
 *
 * The test theme declares tokens in its tokens.yml and sets the same CSS
 * variables in its own `:root` rule, so these tests also prove that published
 * values win over the theme's defaults, with CSS aggregation on.
 *
 * @see tests/modules/canvas_test_design_tokens_theme
 */

const PRIMARY = '[data-token-path="color.semantic.primary"]';
const BODY_FONT = '[data-token-path="font.body"]';
const SPACING_MD = '[data-token-path="spacing.md"]';
const TYPE_HEADING = '[data-token-path="type.heading"]';

/** The theme's own value, from its CSS and its tokens.yml. */
const THEME_PRIMARY = '#0055aa';

/** A front-end page, rendered by the default theme. */
const frontEndVariable = async (page: Page, variable: string) => {
  const frontEnd = await page.context().newPage();
  await frontEnd.goto('/user/login');
  const value = await frontEnd
    .locator('html')
    .evaluate(
      (html, name) => getComputedStyle(html).getPropertyValue(name).trim(),
      variable,
    );
  await frontEnd.close();
  return value;
};

const openStyleGuide = async (page: Page) => {
  await page.getByTestId('canvas-side-menu').getByLabel('Brand Kit').click();
  await page.getByTestId('canvas-brand-kit-style-guide-tab-select').click();
  await expect(page.getByTestId('canvas-style-guide')).toBeVisible();
};

const turnOn = async (page: Page) => {
  await page
    .getByTestId('canvas-style-guide-banner')
    .getByRole('button', { name: 'Turn on' })
    .click();
  await page
    .getByRole('alertdialog')
    .getByRole('button', { name: 'Turn on' })
    .click();
};

/** Discards one pending change from the Review changes popover. */
const discardChange = async (page: Page, label: string) => {
  await page.getByRole('button', { name: /Review \d+ changes?/ }).click();
  const review = page.getByTestId('canvas-publish-reviews-content');
  await expect(review).toContainText('Style guide');
  const change = review.locator('[data-testid="pending-change-row"]', {
    hasText: label,
  });
  await change.hover();
  await change.getByRole('button', { name: 'More options' }).click();
  await page.getByRole('menuitem', { name: 'Discard changes' }).click();
};

test.describe('brand kit style guide', () => {
  test.beforeEach(async ({ drupal, page }) => {
    await drupal.loginAsAdmin();
    // Make the test theme the default theme.
    await page.goto('/admin/appearance');
    await page
      .locator('a[href*="theme=canvas_test_design_tokens_theme"]', {
        hasText: 'Install and set as default',
      })
      .click();
    await expect(page.locator('[data-drupal-messages]')).toContainText(
      'is now the default theme',
    );
    const response = await page.request.get(
      '/canvas/api/v0/config/brand_kit/global',
    );
    expect(response.ok(), `${response.status()} ${await response.text()}`).toBe(
      true,
    );
    const brandKit = await response.json();
    // CI runs Playwright on a core version without the design tokens API.
    // eslint-disable-next-line playwright/no-skipped-test
    test.skip(
      !brandKit.designTokens?.available,
      'Requires the design tokens API of Drupal core 11.5 or later.',
    );
  });

  test('stage, preview, publish and reset a theme color', async ({
    page,
    canvas,
  }) => {
    await canvas.openCanvas(await canvas.createCanvas());
    await openStyleGuide(page);

    // Off: the theme's values are shown read-only, with an offer to turn the
    // style guide on.
    const banner = page.getByTestId('canvas-style-guide-banner');
    await expect(banner).toContainText(
      'defines 7 design values, including 2 colors and 2 font stacks',
    );
    await expect(page.locator(PRIMARY)).toContainText('Turn on to change');
    await banner.getByRole('button', { name: 'Turn on' }).click();
    const confirm = page.getByRole('alertdialog');
    await expect(confirm).toContainText('This changes your live site now.');
    await confirm.getByRole('button', { name: 'Turn on' }).click();

    // On: turning it on changes no value.
    const hex = page.locator(PRIMARY).getByLabel('Primary hex value');
    await expect(hex).toHaveValue(THEME_PRIMARY);
    expect(await frontEndVariable(page, '--color-semantic-primary')).toBe(
      THEME_PRIMARY,
    );

    // A draft previews at once, but is not live.
    await hex.fill('#ff0000');
    await expect(page.locator(PRIMARY)).toContainText('Draft');
    await canvas.testInPreviewFrame('html', async (html) => {
      expect(
        await html.evaluate((el) =>
          getComputedStyle(el).getPropertyValue('--color-semantic-primary'),
        ),
      ).toBe('#ff0000');
    });
    expect(await frontEndVariable(page, '--color-semantic-primary')).toBe(
      THEME_PRIMARY,
    );

    // Publishing applies the draft over the theme's own `:root` rule.
    await canvas.publishAllChanges(['Color / Semantic / Primary']);
    await expect(page.locator(PRIMARY)).toContainText('Customized');
    await expect(async () => {
      expect(await frontEndVariable(page, '--color-semantic-primary')).toBe(
        '#ff0000',
      );
    }).toPass();

    // Reset to default is a draft too, published like any other.
    await page.locator(PRIMARY).getByLabel('Primary options').click();
    await page.getByRole('menuitem', { name: 'Reset to default' }).click();
    await expect(page.locator(PRIMARY)).toContainText('Draft');
    await expect(hex).toHaveValue(THEME_PRIMARY);
    await canvas.publishAllChanges(['Color / Semantic / Primary']);
    await expect(page.locator(PRIMARY)).not.toContainText('Customized');
    await expect(async () => {
      expect(await frontEndVariable(page, '--color-semantic-primary')).toBe(
        THEME_PRIMARY,
      );
    }).toPass();
  });

  test('a font stack Canvas cannot save, and discarding a draft', async ({
    page,
    canvas,
  }) => {
    await canvas.openCanvasRoot();
    await openStyleGuide(page);
    await turnOn(page);
    const row = page.locator(BODY_FONT);

    // The theme declares "Font 3", which core would emit unquoted.
    await expect(row).toContainText(
      "Family names with digits at the start of a word or with punctuation aren't supported yet.",
    );
    await expect(row).not.toContainText('Draft');

    // Once it is removed, the stack saves as a draft.
    await row.getByRole('button', { name: 'Remove Font 3' }).click();
    await expect(row).toContainText('Draft');

    // Discarding it in Review changes removes the draft. Nothing went live.
    await discardChange(page, 'Font / Body');
    await expect(row).not.toContainText('Draft');
    await expect(row).toContainText('Font 3');
    expect(await frontEndVariable(page, '--font-body')).toBe('');
  });

  test('spacing and typography drafts', async ({ page, canvas }) => {
    await canvas.openCanvas(await canvas.createCanvas());
    await openStyleGuide(page);
    await turnOn(page);
    const previewVariable = async (variable: string, expected: string) =>
      canvas.testInPreviewFrame('html', async (html) => {
        expect(
          await html.evaluate(
            (el, name) => getComputedStyle(el).getPropertyValue(name).trim(),
            variable,
          ),
        ).toBe(expected);
      });

    // Switching the unit converts the value, at 16px per rem.
    const spacing = page.locator(SPACING_MD);
    const md = spacing.getByLabel('md value');
    await expect(md).toHaveValue('16');
    await spacing.getByRole('radio', { name: 'rem' }).click();
    await expect(md).toHaveValue('1');
    await expect(spacing).toContainText('Converted at 16px per rem.');

    // Invalid input stays in the field, and stages nothing.
    await md.fill('1..5');
    await expect(spacing).toContainText('Enter a number.');
    await md.fill('1.5');
    await expect(spacing).toContainText('Draft');
    await previewVariable('--spacing-md', '1.5rem');
    expect(await frontEndVariable(page, '--spacing-md')).toBe('');

    // Typography is the `font` shorthand; letter spacing is kept, not output.
    const heading = page.locator(TYPE_HEADING);
    await expect(heading).toContainText('-0.5px (Not output yet)');
    await heading.getByLabel('Heading size value').fill('40');
    await heading.getByLabel('Heading line height').fill('1.3');
    await expect(heading).toContainText('Draft');
    await previewVariable('--type-heading', '700 40px/1.3 Georgia, serif');

    // Discarding the spacing draft restores the theme's value in the preview.
    await discardChange(page, 'Spacing / md');
    await expect(spacing).not.toContainText('Draft');
    await expect(md).toHaveValue('16');
    await previewVariable('--spacing-md', '16px');
    expect(await frontEndVariable(page, '--type-heading')).toBe('');
  });
});
