import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({
  modules: ['canvas_test_sdc', 'datetime', 'datetime_range'],
  enableTestExtensions: true,
});

test.describe('Multivalue Prop Types', () => {
  test.beforeEach(async ({ drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.multivalue-props' });
  });

  test('Helper functions', async ({ canvas }) => {
    await canvas.editMultiValueProp('Text (Unlimited)', 'Catbro', 0, 'string');

    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(2);
      await expect(textList.nth(0)).toHaveText('Catbro');
      await expect(textList.nth(1)).toHaveText('Sample Text');
    });

    await canvas.reorderMultiValueProp('Text (Unlimited)', 1, 0);

    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(2);
      await expect(textList.nth(0)).toHaveText('Sample Text');
      await expect(textList.nth(1)).toHaveText('Catbro');
    });

    await canvas.addMultiValueProp('Text (Unlimited)', 'Minibro', 'string');

    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(3);
      await expect(textList.nth(0)).toHaveText('Sample Text');
      await expect(textList.nth(1)).toHaveText('Catbro');
      await expect(textList.nth(2)).toHaveText('Minibro');
    });

    await canvas.removeMultiValueProp('Text (Unlimited)', 1);

    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(2);
      await expect(textList.nth(0)).toHaveText('Sample Text');
      await expect(textList.nth(1)).toHaveText('Minibro');
    });
  });

  test('Edit, remove, add, and re-order', async ({ page, canvas }) => {
    let textField = page.locator('.field--type-string').filter({
      has: page.getByRole('heading', { name: 'Text (Unlimited)' }),
    });
    await expect(textField).toBeVisible();
    await expect(
      page.getByText('An unlimited array of plain text strings.'),
    ).toBeVisible();
    await expect(textField.locator('tr.draggable')).toHaveCount(2);
    await expect(
      textField.getByRole('button', { name: '+ Add new' }),
    ).toBeVisible();

    // Open edit popover.
    const firstRow = textField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Text/ }).click();
    let popover = firstRow.getByRole('dialog');
    await expect(
      popover.getByText('Text (Unlimited)', { exact: true }),
    ).toBeVisible();

    // Make a change to the text — changes propagate live.
    let textbox = popover.getByRole('textbox');
    await textbox.fill('Minibro');

    // Row label should update while the popover is still open.
    await expect(
      firstRow.locator('[data-canvas-multivalue-label="true"]'),
    ).toHaveText('Minibro');

    // Preview should also update while the popover is still open.
    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList.nth(0)).toHaveText('Minibro');
    });

    // Close popover with close button.
    // The allotment sash (panel resize divider) sits on top of the Close button
    // and intercepts pointer events, causing Playwright's normal click() to time
    // out. Using dispatchEvent bypasses the sash entirely by firing the click
    // directly on the element without going through the pointer event system.
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');
    await expect(firstRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );

    // Value should persist after close — not be discarded.
    await expect(textField).toContainText('Minibro');

    // Preview should still reflect the value after close.
    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList.nth(0)).toHaveText('Minibro');
    });

    // Close popover with keyboard shortcut.
    // This only works if the edit field has focus.
    await firstRow.getByRole('button', { name: /^Edit Text/ }).click();
    const popoverTextbox = popover.getByRole('textbox');
    await expect(popoverTextbox).toHaveValue('Minibro');
    await popoverTextbox.click();
    await expect(popoverTextbox).toBeFocused();
    await popoverTextbox.press('Escape');
    await expect(firstRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );

    // Edit text.
    await firstRow.getByRole('button', { name: /^Edit Text/ }).click();
    textbox = popover.getByRole('textbox');
    await expect(textbox).toHaveValue('Minibro');
    await textbox.fill('Marshmallow Coast');
    await textbox.press('Enter');
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle'); // wait for auto-save PATCH to settle before asserting updated values

    // Verify text in the Settings pane is updated.
    await expect(
      textField
        .locator('tr.draggable')
        .first()
        .locator('[data-canvas-multivalue-label="true"]'),
    ).toHaveText('Marshmallow Coast');

    // Assert that a very long label text is visually truncated with ellipsis.
    // The scrollWidth of the label element exceeds its clientWidth when
    // CSS text-overflow: ellipsis is active and the text is clipped.
    const longText =
      'UI for value input breaks on entering string with more than 44 characters and keeps going even further';
    await firstRow.getByRole('button', { name: /^Edit Text/ }).click();
    textbox = popover.getByRole('textbox');
    await textbox.fill(longText);
    await textbox.press('Enter');
    const longLabel = textField
      .locator('tr.draggable')
      .first()
      .locator('[data-canvas-multivalue-label="true"]');
    await expect(longLabel).toHaveText(longText);
    const isTruncated = await longLabel.evaluate(
      (el) => el.scrollWidth > el.clientWidth,
    );
    expect(isTruncated).toBe(true);

    // Restore the original value before continuing the test.
    await firstRow.getByRole('button', { name: /^Edit Text/ }).click();
    textbox = popover.getByRole('textbox');
    await textbox.fill('Marshmallow Coast');
    await textbox.press('Enter');

    // Verify text in the Preview pane is updated.
    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(2);
      await expect(textList.nth(0)).toHaveText('Marshmallow Coast');
      await expect(textList.nth(1)).toHaveText('Sample Text');
    });

    // Remove an item.
    await textField
      .locator('tr.draggable')
      .nth(1)
      .getByRole('button', { name: /^Edit Text/ })
      .click();
    const secondRow = textField.locator('tr.draggable').nth(1);
    popover = secondRow.getByRole('dialog');
    const removeButton = popover.getByRole('button', { name: 'Remove' });
    await expect(removeButton).toBeVisible();
    await removeButton.click();
    await expect(
      page.locator('[role="dialog"][data-state="open"]'),
    ).toHaveCount(0);
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    // Check removed from Settings pane.
    await expect(textField.locator('tr.draggable')).toHaveCount(1);
    await expect(textField).not.toContainText('Sample Text');

    // Check removed from preview pane.
    // Uncomment when https://www.drupal.org/project/canvas/issues/3586289 is fixed.
    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(1);
      await expect(textList).not.toHaveText('Sample Text');
    });

    await expect(textField.locator('tr.draggable')).toHaveCount(1);

    // Add a new rows and populate their values.
    // @todo we should actually be using canvas.addMultiValueProp, but it will
    // fail due to a legitimate bug: https://drupal.org/i/3587472
    await textField.getByRole('button', { name: '+ Add new' }).click();
    await expect(textField.locator('tr.draggable')).toHaveCount(2);
    await canvas.editMultiValueProp(
      'Text (Unlimited)',
      'Hello, world!',
      1,
      'string',
    );

    await textField.getByRole('button', { name: '+ Add new' }).click();
    await expect(textField.locator('tr.draggable')).toHaveCount(3);
    await canvas.editMultiValueProp('Text (Unlimited)', 'Catbro', 2, 'string');

    // Verify text in the Settings pane is updated.
    await expect(textField).toContainText('Marshmallow Coast');
    await expect(textField).toContainText('Hello, world!');
    await expect(textField).toContainText('Catbro');
    await expect(textField).not.toContainText('Empty');

    // Verify text in the Preview pane is updated.
    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(3);
      await expect(textList.nth(0)).toHaveText('Marshmallow Coast');
      await expect(textList.nth(1)).toHaveText('Hello, world!');
      await expect(textList.nth(2)).toHaveText('Catbro');
    });

    let labels = textField.locator('[data-canvas-multivalue-label="true"]');

    // Drag row 3 (Catbro) to before row 1 (Marshmallow Coast).
    await canvas.reorderMultiValueProp('Text (Unlimited)', 2, 0);
    await expect(labels.nth(0)).toHaveText('Catbro');
    await expect(labels.nth(1)).toHaveText('Marshmallow Coast');
    await expect(labels.nth(2)).toHaveText('Hello, world!');

    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(3);
      await expect(textList.nth(0)).toHaveText('Catbro');
      await expect(textList.nth(1)).toHaveText('Marshmallow Coast');
      await expect(textList.nth(2)).toHaveText('Hello, world!');
    });

    // Drag row 3 (Hello, world!) to before row 2 (Marshmallow Coast).
    await canvas.reorderMultiValueProp('Text (Unlimited)', 2, 1);

    // Verify final order: Catbro, Hello, world!, Marshmallow Coast.
    await expect(labels.nth(0)).toHaveText('Catbro');
    await expect(labels.nth(1)).toHaveText('Hello, world!');
    await expect(labels.nth(2)).toHaveText('Marshmallow Coast');

    // Verify text in the Preview pane is updated.
    await canvas.testInPreviewFrame('#text-list li', async (textList) => {
      await expect(textList).toHaveCount(3);
      await expect(textList.nth(0)).toHaveText('Catbro');
      await expect(textList.nth(1)).toHaveText('Hello, world!');
      await expect(textList.nth(2)).toHaveText('Marshmallow Coast');
    });

    // Reload the page and verify the order is still the same.

    // Provide a fixed wait to ensure changes are saved.
    // It's highly preferable to use something non-arbitrary that confirms a
    // save has occurred. This should be considered a temporary hack to ensure
    // the tests run reliably.
    // eslint-disable-next-line playwright/no-wait-for-timeout
    await page.waitForTimeout(10000);
    await page.reload();
    await canvas.waitForEditorUi();
    textField = page.locator('.field--type-string').filter({
      has: page.getByRole('heading', { name: 'Text (Unlimited)' }),
    });
    await expect(textField).toBeVisible();
    // An extra "Empty" item is added.
    await expect(textField.locator('tr.draggable')).toHaveCount(3);
    labels = textField.locator('[data-canvas-multivalue-label="true"]');
    await expect(labels.nth(0)).toHaveText('Catbro');
    await expect(labels.nth(1)).toHaveText('Hello, world!');
    await expect(labels.nth(2)).toHaveText('Marshmallow Coast');
  });

  test('Limited items', async ({ page }) => {
    const textLimitedField = page.locator('.field--type-string').filter({
      has: page.getByRole('heading', { name: 'Text (Limited)', exact: true }),
    });

    await expect(textLimitedField.locator('tr.draggable')).toHaveCount(3);
    await expect(
      textLimitedField
        .locator('tr.draggable')
        .last()
        .locator('[data-canvas-multivalue-label="true"]'),
    ).toHaveText('Empty');
    await expect(
      textLimitedField.getByRole('button', { name: '+ Add new' }),
    ).toBeHidden();

    const firstRow = textLimitedField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Text/ }).click();

    const popover = firstRow.getByRole('dialog');

    // Remove is disabled.
    await expect(popover).toBeVisible();
    await expect(
      popover.getByRole('button', { name: 'Remove' }),
    ).toBeDisabled();
  });

  test('Required items', async ({ page, canvas }) => {
    const textLimitedField = page.locator('.field--type-string').filter({
      has: page.getByRole('heading', {
        name: 'Text (Required Unlimited)*',
        exact: true,
      }),
    });
    await expect(textLimitedField).toBeVisible();

    await expect(textLimitedField.locator('tr.draggable')).toHaveCount(2);
    await expect(
      textLimitedField
        .locator('tr.draggable')
        .last()
        .locator('[data-canvas-multivalue-label="true"]'),
    ).toHaveText('Required Text 2');

    await expect(
      textLimitedField.getByRole('button', { name: '+ Add new' }),
    ).toBeVisible();

    await canvas.removeMultiValueProp('Text (Required Unlimited)*', 0);

    const firstRow = textLimitedField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Text/ }).click();
    const popover = firstRow.getByRole('dialog');
    await expect(popover).toBeVisible();

    // Remove is disabled as there is only a single value left.
    await expect(
      popover.getByRole('button', { name: 'Remove' }),
    ).toBeDisabled();
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');
    await expect(firstRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );

    // Add new rows and populate their values.
    // @todo we should actually be using canvas.addMultiValueProp, but it will
    // fail due to a legitimate bug: https://drupal.org/i/3587472
    await textLimitedField.getByRole('button', { name: '+ Add new' }).click();
    const rows = textLimitedField.locator('tr.draggable');
    await expect(rows).toHaveCount(2);
    await rows
      .last()
      .getByRole('button', { name: /^Edit Text \(Required Unlimited\)/ })
      .click();
    const removeButton = rows
      .last()
      .getByRole('dialog')
      .getByRole('button', { name: 'Remove' });
    await expect(removeButton).toBeEnabled();
    await removeButton.click();
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');
    await expect(rows).toHaveCount(1);
  });

  test('Link items', async ({ page, canvas }) => {
    // Absolute links.
    await canvas.testInPreviewFrame(
      '#link-list li',
      async (absoluteLinkList) => {
        await expect(absoluteLinkList).toHaveCount(2);
        await expect(
          absoluteLinkList.locator('a[href="https://drupal.org"]'),
        ).toBeVisible();
        await expect(
          absoluteLinkList.locator('a[href="https://example.com"]'),
        ).toBeVisible();
      },
    );

    const absoluteLinkField = page.locator('.field--type-link').filter({
      has: page.getByRole('heading', {
        name: 'Link (Unlimited)',
        exact: true,
      }),
    });

    const firstRow = absoluteLinkField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Link/ }).click();

    const popover = firstRow.getByRole('dialog');
    await popover.getByRole('textbox').fill('Minibro');
    await expect(popover.locator('[data-prop-message="true"]')).toHaveText(
      '❌ data/0 must match format "uri"',
    );

    // Popover should refuse to close while the value is invalid.
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');
    await expect(firstRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'open',
    );

    // Fix the value — popover should now be permitted to close.
    await popover.getByRole('textbox').fill('https://drupal.org');
    await expect(popover.locator('[data-prop-message="true"]')).toHaveCount(0);
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');
    await expect(firstRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );

    await canvas.testInPreviewFrame('#link-list li', async (linkList) => {
      await expect(linkList).toHaveCount(2);
      await expect(
        linkList.locator('a[href="https://drupal.org"]'),
      ).toBeVisible();
      await expect(
        linkList.locator('a[href="https://example.com"]'),
      ).toBeVisible();
    });

    // Relative links
    await canvas.testInPreviewFrame(
      '#relative-link-list li',
      async (relativeLinkList) => {
        await expect(relativeLinkList).toHaveCount(2);
        await expect(
          relativeLinkList.locator('a[href="/about"]'),
        ).toBeVisible();
        await expect(
          relativeLinkList.locator('a[href="/contact"]'),
        ).toBeVisible();
      },
    );
  });

  test('Numbers', async ({ page, canvas }) => {
    // Float.
    const numberField = page.locator('.field--type-float').filter({
      has: page.getByRole('heading', {
        name: 'Number (Unlimited)',
        exact: true,
      }),
    });

    let firstRow = numberField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Number/ }).click();
    let popover = firstRow.getByRole('dialog');
    let spinbutton = popover.getByRole('spinbutton');

    // Verify it contains an integer value.
    await expect(spinbutton).toHaveValue('42');

    // Press up arrow and verify it increments by 1.
    await spinbutton.press('ArrowUp');
    await expect(spinbutton).toHaveValue('43');
    await spinbutton.press('ArrowDown');
    await expect(spinbutton).toHaveValue('42');

    // Set it to a decimal.
    let textbox = popover.getByRole('spinbutton');
    await textbox.fill('42.5');
    await textbox.press('Enter');
    await expect(firstRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );

    await canvas.testInPreviewFrame('#number-list li', async (numberList) => {
      await expect(numberList).toHaveCount(3);
      await expect(numberList.nth(0)).toHaveText('42.5');
      await expect(numberList.nth(1)).toHaveText('100');
      // Asserts that the 0 is not rendered as "Empty".
      await expect(numberList.nth(2)).toHaveText('0');
    });

    // Assert that the zero-value row in the Settings pane shows '0', not 'Empty'.
    const thirdRow = numberField.locator('tr.draggable').nth(2);
    await expect(
      thirdRow.locator('[data-canvas-multivalue-label="true"]'),
    ).toHaveText('0');
    await expect(
      thirdRow.locator('[data-canvas-multivalue-label="true"]'),
    ).not.toHaveText('Empty');

    // Assert that opening the popover for the zero-value row shows '0' in the
    // input, not a blank/empty field.
    await thirdRow.getByRole('button', { name: /^Edit Number/ }).click();
    const thirdRowPopover = thirdRow.getByRole('dialog');
    await expect(thirdRowPopover.getByRole('spinbutton')).toHaveValue('0');
    await thirdRowPopover
      .getByRole('button', { name: 'Close' })
      .dispatchEvent('click');
    await expect(thirdRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );

    // Integer type rejects decimal values.
    const integerField = page.locator('.field--type-integer').filter({
      has: page.getByRole('heading', {
        name: 'Integer (Unlimited)',
        exact: true,
      }),
    });
    firstRow = integerField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Integer/ }).click();
    popover = firstRow.getByRole('dialog');
    spinbutton = popover.getByRole('spinbutton');

    // Verify it contains an integer value.
    await expect(spinbutton).toHaveValue('7');
    textbox = popover.getByRole('spinbutton');
    await textbox.fill('42.5');
    await expect(popover.locator('[data-prop-message="true"]')).toHaveText(
      '❌ data/0 must be integer',
    );

    // Change it to a valid value, and it should again propagate.
    await textbox.fill('222');
    await expect(popover.locator('[data-prop-message="true"]')).toHaveCount(0);
    await expect(
      firstRow.locator('[data-canvas-multivalue-label="true"]'),
    ).toHaveText('222');
    await textbox.press('Enter');

    // Now the popover is permitted to close.
    await expect(firstRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );
  });

  test('Datetime and Date', async ({ page, canvas }) => {
    // ===== DATETIME (UNLIMITED) =====
    const dateTimeUnlimitedField = page
      .locator('.field--type-datetime')
      .filter({
        has: page.getByRole('heading', {
          name: 'DateTime (Unlimited)',
          exact: true,
        }),
      });
    await expect(dateTimeUnlimitedField).toBeVisible();
    await expect(
      dateTimeUnlimitedField.getByRole('button', { name: '+ Add new' }),
    ).toBeVisible();

    // Edit first and second rows.
    await canvas.editMultiValueDatetimeProp(
      'DateTime (Unlimited)',
      '2025-12-24',
      '08:00:00',
      0,
    );
    await canvas.testInPreviewFrame('#datetime-list li', async (items) => {
      await expect(items.nth(0)).toContainText('2025-12-24T08:00:00.000Z');
    });

    await dateTimeUnlimitedField
      .getByRole('button', { name: '+ Add new' })
      .click();
    await expect(dateTimeUnlimitedField.locator('tr.draggable')).toHaveCount(2);

    await canvas.editMultiValueDatetimeProp(
      'DateTime (Unlimited)',
      '2025-12-25',
      '14:30:00',
      1,
    );
    await canvas.testInPreviewFrame('#datetime-list li', async (items) => {
      await items.nth(0).scrollIntoViewIfNeeded();
      await expect(items).toHaveCount(2);
      await expect(items.nth(0)).toContainText('2025-12-24T08:00:00.000Z');
      await expect(items.nth(1)).toContainText('2025-12-25T14:30:00.000Z');
    });

    // Verify popover header label.
    let firstRow = dateTimeUnlimitedField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit DateTime/ }).click();
    let popover = firstRow.getByRole('dialog');
    await expect(
      popover.getByText('DateTime (Unlimited)', { exact: true }),
    ).toBeVisible();
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');

    // Remove first item.
    await canvas.removeMultiValueProp('DateTime (Unlimited)', 0);
    await canvas.testInPreviewFrame('#datetime-list li', async (items) => {
      await expect(items).toHaveCount(1);
      await expect(items.nth(0)).toContainText('2025-12-25T14:30:00.000Z');
    });

    // Add a new item and reorder.
    await dateTimeUnlimitedField
      .getByRole('button', { name: '+ Add new' })
      .click();
    await canvas.editMultiValueDatetimeProp(
      'DateTime (Unlimited)',
      '2025-12-26',
      '10:00:00',
      1,
    );

    // Drag row 1 (2025-12-26) before row 0 (2025-12-25).
    await canvas.reorderMultiValueProp('DateTime (Unlimited)', 1, 0);
    await canvas.testInPreviewFrame('#datetime-list li', async (items) => {
      await expect(items.nth(0)).toContainText('2025-12-26T10:00:00.000Z');
      await expect(items.nth(1)).toContainText('2025-12-24T08:00:00.000Z');
    });

    // Verify no ghost rows appear when deleting from bottom to top.
    // Add one more row to reach 3, then delete the last two in sequence.
    await dateTimeUnlimitedField
      .getByRole('button', { name: '+ Add new' })
      .click();
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');
    const dtRows = dateTimeUnlimitedField.locator('tr.draggable');
    await expect(dtRows).toHaveCount(3);

    await dtRows
      .last()
      .getByRole('button', { name: /^Edit DateTime/ })
      .click();
    let removeButton = dtRows
      .last()
      .getByRole('dialog')
      .getByRole('button', { name: 'Remove' });
    await expect(removeButton).toBeEnabled();
    await removeButton.click();
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');
    await expect(dtRows).toHaveCount(2);

    await dtRows
      .last()
      .getByRole('button', { name: /^Edit DateTime/ })
      .click();
    removeButton = dtRows
      .last()
      .getByRole('dialog')
      .getByRole('button', { name: 'Remove' });
    await expect(removeButton).toBeEnabled();
    await removeButton.click();
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');
    await expect(dtRows).toHaveCount(1);

    // ===== DATETIME (LIMITED) =====
    const dateTimeLimitedField = page.locator('.field--type-datetime').filter({
      has: page.getByRole('heading', {
        name: 'DateTime (Limited)',
        exact: true,
      }),
    });
    await expect(dateTimeLimitedField).toBeVisible();
    await expect(
      dateTimeLimitedField.getByRole('button', { name: '+ Add new' }),
    ).toBeHidden();

    // Remove button is disabled.
    firstRow = dateTimeLimitedField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit DateTime/ }).click();
    popover = firstRow.getByRole('dialog');
    await expect(
      popover.getByRole('button', { name: 'Remove' }),
    ).toBeDisabled();
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');

    // Can still edit values.
    await canvas.editMultiValueDatetimeProp(
      'DateTime (Limited)',
      '2025-11-15',
      '16:45:00',
      0,
    );

    // ===== DATE (UNLIMITED) =====
    const dateUnlimitedField = page.locator('.field--type-datetime').filter({
      has: page.getByRole('heading', { name: 'Date (Unlimited)', exact: true }),
    });
    await expect(dateUnlimitedField).toBeVisible();
    await expect(
      dateUnlimitedField.getByRole('button', { name: '+ Add new' }),
    ).toBeVisible();

    // Verify time input is NOT visible (date-only field).
    firstRow = dateUnlimitedField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Date/ }).click();
    popover = firstRow.getByRole('dialog');
    await expect(popover.locator('input[type="date"]')).toBeVisible();
    await expect(popover.locator('input[type="time"]')).toBeHidden();
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');

    // Edit first and second rows.
    await canvas.editMultiValueDateProp('Date (Unlimited)', '2026-04-27', 0);
    await dateUnlimitedField.getByRole('button', { name: '+ Add new' }).click();
    await expect(dateUnlimitedField.locator('tr.draggable')).toHaveCount(2);
    await canvas.editMultiValueDateProp('Date (Unlimited)', '2026-04-28', 1);
    await canvas.testInPreviewFrame('#date-list li', async (items) => {
      await expect(items).toHaveCount(2);
      await expect(items.nth(0)).toContainText('2026-04-27');
      await expect(items.nth(1)).toContainText('2026-04-28');
    });

    // Verify popover header label.
    firstRow = dateUnlimitedField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Date/ }).click();
    popover = firstRow.getByRole('dialog');
    await expect(
      popover.getByText('Date (Unlimited)', { exact: true }),
    ).toBeVisible();
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');

    // Remove first item.
    await canvas.removeMultiValueProp('Date (Unlimited)', 0);
    await canvas.testInPreviewFrame('#date-list li', async (items) => {
      await items.nth(0).scrollIntoViewIfNeeded();
      await expect(items).toHaveCount(1);
      await expect(items.nth(0)).toContainText('2026-04-28');
    });

    // Add a new item and reorder.
    await dateUnlimitedField.getByRole('button', { name: '+ Add new' }).click();
    await canvas.editMultiValueDateProp('Date (Unlimited)', '2026-05-01', 1);

    // Drag row 1 (2026-05-01) before row 0 (2026-04-28).
    await canvas.reorderMultiValueProp('Date (Unlimited)', 1, 0);
    await canvas.testInPreviewFrame('#date-list li', async (items) => {
      await items.nth(0).scrollIntoViewIfNeeded();
      await expect(items.nth(0)).toContainText('2026-05-01');
      await expect(items.nth(1)).toContainText('2026-04-27');
    });

    // ===== DATE (LIMITED) =====
    const dateLimitedField = page.locator('.field--type-datetime').filter({
      has: page.getByRole('heading', { name: 'Date (Limited)', exact: true }),
    });
    await expect(dateLimitedField).toBeVisible();
    await expect(
      dateLimitedField.getByRole('button', { name: '+ Add new' }),
    ).toBeHidden();

    // Remove button is disabled.
    firstRow = dateLimitedField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Date/ }).click();
    popover = firstRow.getByRole('dialog');
    await expect(
      popover.getByRole('button', { name: 'Remove' }),
    ).toBeDisabled();
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');

    // Can still edit values.
    await canvas.editMultiValueDateProp('Date (Limited)', '2026-06-15', 0);
  });

  test('List text', async ({ page, canvas }) => {
    // Test unlimited text list - basic rendering and operations.
    const textField = page.locator('.form-item').filter({
      has: page.locator('label', { hasText: 'List Text (Unlimited)' }),
    });
    await expect(textField).toBeVisible();
    const textSelectControl = textField.locator(
      '[class*="canvas-select__control"]',
    );
    await expect(textSelectControl).toBeVisible();

    // Verify initial values.
    const textChips = textField.locator('[class*="multiValue"]');
    await expect(textChips).toHaveCount(2);
    await expect(textChips.nth(0)).toContainText('Option One');
    await expect(textChips.nth(1)).toContainText('Option Two');

    // Add a value.
    await textSelectControl.click();
    await page
      .locator('[class*="canvas-select__option"]', { hasText: 'Option Three' })
      .click();
    await expect(textChips).toHaveCount(3);
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    // Verify in preview.
    await canvas.testInPreviewFrame(
      '#list-text-list li',
      async (textListItems) => {
        await expect(textListItems).toHaveCount(3);
        await expect(textListItems.nth(2)).toContainText('option_three');
      },
    );

    // Remove a value.
    await textField
      .locator('[class*="multiValue"]')
      .first()
      .locator('[class*="multi-value__remove"]')
      .click();
    await expect(textChips).toHaveCount(2);
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    // Clear all values.
    await textField
      .locator('[class*="canvas-select__clear-indicator"]')
      .click();
    await expect(textChips).toHaveCount(0);
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');
    await canvas.testInPreviewFrame(
      '#list-text-list',
      async (listContainer) => {
        await expect(listContainer).toBeHidden();
      },
    );

    // Close the dropdown by pressing Escape.
    await page.keyboard.press('Escape');

    // Test limited text list - cardinality enforcement.
    const limitedTextField = page.locator('.form-item').filter({
      has: page.locator('label', { hasText: 'List Text (Limited)' }),
    });
    await expect(limitedTextField).toBeVisible();
    const limitedTextChips = limitedTextField.locator('[class*="multiValue"]');
    await expect(limitedTextChips).toHaveCount(2);

    // Reach cardinality limit and verify remaining options are disabled.
    const limitedTextControl = limitedTextField.locator(
      '[class*="canvas-select__control"]',
    );
    await limitedTextControl.click();
    await page
      .locator('[class*="canvas-select__option"]', { hasText: 'Option Three' })
      .click();
    const optionFour = page.locator('[class*="canvas-select__option"]', {
      hasText: 'Option Four',
    });
    await expect(optionFour).toHaveClass(/option--is-disabled/);
  });

  test('List integer', async ({ page, canvas }) => {
    // Test unlimited integer list - basic rendering.
    const intField = page.locator('.form-item').filter({
      has: page.locator('label', { hasText: 'List Integer (Unlimited)' }),
    });
    await expect(intField).toBeVisible();
    const intSelectControl = intField.locator(
      '[class*="canvas-select__control"]',
    );
    await expect(intSelectControl).toBeVisible();

    // Verify initial values.
    const intChips = intField.locator('[class*="multiValue"]');
    await expect(intChips).toHaveCount(2);
    await expect(intChips.nth(0)).toContainText('Ten');
    await expect(intChips.nth(1)).toContainText('Twenty');

    // Add a value.
    await intSelectControl.click();
    await page
      .locator('[class*="canvas-select__option"]', { hasText: 'Thirty' })
      .click();
    await expect(intChips).toHaveCount(3);
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    // Verify in preview.
    await canvas.testInPreviewFrame(
      '#list-int-list li',
      async (intListItems) => {
        await expect(intListItems).toHaveCount(3);
        await expect(intListItems.nth(2)).toContainText('30');
      },
    );

    // Remove a value.
    await intField
      .locator('[class*="multiValue"]')
      .first()
      .locator('[class*="multi-value__remove"]')
      .click();
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');
    await expect(intChips).toHaveCount(2);

    // Close the dropdown by pressing Escape.
    await page.keyboard.press('Escape');

    // Test limited integer list - cardinality enforcement.
    const limitedIntField = page.locator('.form-item').filter({
      has: page.locator('label', { hasText: 'List Integer (Limited)' }),
    });
    await expect(limitedIntField).toBeVisible();
    const limitedIntChips = limitedIntField.locator('[class*="multiValue"]');
    await expect(limitedIntChips).toHaveCount(2);

    // Reach cardinality limit and verify remaining options are disabled.
    const limitedIntControl = limitedIntField.locator(
      '[class*="canvas-select__control"]',
    );
    await limitedIntControl.click();
    await page
      .locator('[class*="canvas-select__option"]', { hasText: 'Thirty' })
      .click();
    const optionForty = page.locator('[class*="canvas-select__option"]', {
      hasText: 'Forty',
    });
    await expect(optionForty).toHaveClass(/option--is-disabled/);

    // Test persistence after page reload.
    await page.reload();
    await canvas.waitForEditorUi();
    const intFieldAfterReload = page.locator('.form-item').filter({
      has: page.locator('label', { hasText: 'List Integer (Unlimited)' }),
    });
    intFieldAfterReload.scrollIntoViewIfNeeded();
    const intChipsAfterReload = intFieldAfterReload.locator(
      '[class*="multiValue"]',
    );
    await expect(intChipsAfterReload).toHaveCount(2);
    await canvas.testInPreviewFrame(
      '#list-int-list li',
      async (intListAfterReload) => {
        await expect(intListAfterReload).toHaveCount(2);
      },
    );
  });

  test.describe('Datetime (Unlimited)', () => {
    test('no ghost row appears when deleting from bottom to top', async ({
      page,
      canvas,
    }) => {
      // Find the DateTime (Unlimited) field - only appears with unlimited cardinality.
      const dateTimeUnlimitedField = page
        .locator('.field--type-datetime')
        .filter({
          has: page.getByRole('heading', {
            name: 'DateTime (Unlimited)',
            exact: true,
          }),
        });

      // Rows locator is defined once — Playwright Locators are lazy and always
      // reflect the current DOM, so there is no need to reassign it.
      const rows = dateTimeUnlimitedField.locator('tr.draggable');

      // Ensure we have at least 3 rows so we can delete 2 and verify no ghost
      // rows remain.
      while ((await rows.count()) < 3) {
        await dateTimeUnlimitedField
          .getByRole('button', { name: '+ Add new' })
          .click();
        // eslint-disable-next-line playwright/no-networkidle
        await page.waitForLoadState('networkidle');
      }

      const initialRowCount = await rows.count();
      await canvas.editMultiValueDatetimeProp(
        'DateTime (Unlimited)',
        '2025-12-24',
        '08:00:00',
        0,
      );
      await canvas.editMultiValueDatetimeProp(
        'DateTime (Unlimited)',
        '2025-12-25',
        '08:30:00',
        1,
      );
      await canvas.editMultiValueDatetimeProp(
        'DateTime (Unlimited)',
        '2025-12-26',
        '10:00:00',
        2,
      );
      // Review the changes and publish them before deleting the row.
      await page.getByTestId('canvas-publish-review').click();
      await expect(
        page
          .getByTestId('canvas-publish-reviews-content')
          .filter({ hasText: 'Unpublished changes' }),
      ).toBeVisible();
      await page.getByTestId('canvas-publish-review-select-all').click();
      await page.getByRole('button', { name: 'Publish 1 selected' }).click();
      await page.reload();

      // Review changes: verify all values appear in preview frame.
      await canvas.testInPreviewFrame('#datetime-list li', async (items) => {
        await expect(items).toHaveCount(initialRowCount);
        await expect(items.nth(0)).toContainText('2025-12-24');
        await expect(items.nth(1)).toContainText('2025-12-25');
        await expect(items.nth(2)).toContainText('2025-12-26');
      });

      // Now proceed with deletion: Remove the last row (delete from bottom to top).
      await rows
        .last()
        .getByRole('button', { name: /^Edit DateTime/ })
        .click();
      let popover = rows.last().getByRole('dialog');
      const firstRemoveButton = popover.getByRole('button', { name: 'Remove' });
      await expect(firstRemoveButton).toBeEnabled();
      await firstRemoveButton.click();
      // eslint-disable-next-line playwright/no-networkidle
      await page.waitForLoadState('networkidle');

      // After the first deletion the count must drop by exactly 1.
      // If a ghost row appeared instead, the count would remain at initialRowCount
      // and this assertion would fail — catching the bug.
      await expect(rows).toHaveCount(initialRowCount - 1);

      // Verify the deleted row is no longer in preview
      await canvas.testInPreviewFrame('#datetime-list li', async (items) => {
        await expect(items).toHaveCount(initialRowCount - 1);
        await expect(items.nth(0)).toContainText('2025-12-24');
        await expect(items.nth(1)).toContainText('2025-12-25');
      });

      // Remove the new last row (continuing bottom-to-top).
      await rows
        .last()
        .getByRole('button', { name: /^Edit DateTime/ })
        .click();
      popover = rows.last().getByRole('dialog');
      const secondRemoveButton = popover.getByRole('button', {
        name: 'Remove',
      });
      await expect(secondRemoveButton).toBeEnabled();
      await secondRemoveButton.click();
      // eslint-disable-next-line playwright/no-networkidle
      await page.waitForLoadState('networkidle');

      // After the second deletion the count must drop by exactly 2 from the
      // original. A ghost row bug would leave the count at initialRowCount - 1
      // or higher, failing this assertion.
      await expect(rows).toHaveCount(initialRowCount - 2);

      // Verify final state in preview
      await canvas.testInPreviewFrame('#datetime-list li', async (items) => {
        await expect(items).toHaveCount(initialRowCount - 2);
        await expect(items.nth(0)).toContainText('2025-12-24');
      });
    });
  });
});
