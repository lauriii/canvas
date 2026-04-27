import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({
  modules: ['canvas_test_sdc', 'datetime', 'datetime_range', 'canvas_dev_mode'],
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

    let previewFrame = await canvas.getActivePreviewFrame();
    let textList = previewFrame
      .getByTestId('text-component')
      .locator('#text-list li');
    await expect(textList).toHaveCount(2);
    await expect(textList.nth(0)).toHaveText('Catbro');
    await expect(textList.nth(1)).toHaveText('Sample Text');

    await canvas.reorderMultiValueProp('Text (Unlimited)', 1, 0);

    previewFrame = await canvas.getActivePreviewFrame();
    textList = previewFrame
      .getByTestId('text-component')
      .locator('#text-list li');
    await expect(textList).toHaveCount(2);
    await expect(textList.nth(0)).toHaveText('Sample Text');
    await expect(textList.nth(1)).toHaveText('Catbro');

    await canvas.addMultiValueProp('Text (Unlimited)', 'Minibro', 'string');

    // @todo This will fail because of race conditions.
    // https://www.drupal.org/project/canvas/issues/3586848
    //previewFrame = await canvas.getActivePreviewFrame();
    //textList = previewFrame
    //  .getByTestId('text-component')
    //  .locator('#text-list li');
    //await expect(textList).toHaveCount(3);
    //await expect(textList.nth(0)).toHaveText('Sample Text');
    //await expect(textList.nth(1)).toHaveText('Catbro');
    //await expect(textList.nth(2)).toHaveText('Minibro');

    //await canvas.removeMultiValueProp('Text (Unlimited)', 1);

    //previewFrame = await canvas.getActivePreviewFrame();
    //textList = previewFrame
    //  .getByTestId('text-component')
    //  .locator('#text-list li');
    //await expect(textList).toHaveCount(2);
    //await expect(textList.nth(0)).toHaveText('Sample Text');
    //await expect(textList.nth(1)).toHaveText('Minibro');
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

    // Make a change to the text that will be discarded.
    let textbox = popover.getByRole('textbox');
    await textbox.fill('Minibro');

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
    await expect(textField).not.toContainText('Minibro');

    // Close popover with keyboard shortcut.
    // This only works if the edit field has focus.
    await firstRow.getByRole('button', { name: /^Edit Text/ }).click();
    const popoverTextbox = popover.getByRole('textbox');
    await expect(popoverTextbox).not.toContainText('Minibro');
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
    await expect(textbox).toHaveValue('Hello World');
    await textbox.fill('Marshmallow Coast');
    await textbox.press('Enter');
    await page.waitForLoadState('networkidle');

    // Verify text in the Settings pane is updated.
    await expect(
      textField
        .locator('tr.draggable')
        .first()
        .locator('[data-canvas-multivalue-label="true"]'),
    ).toHaveText('Marshmallow Coast');

    // Verify text in the Preview pane is updated.
    let previewFrame = await canvas.getActivePreviewFrame();
    let textList = previewFrame
      .getByTestId('text-component')
      .locator('#text-list li');
    await expect(textList).toHaveCount(2);
    await expect(textList.nth(0)).toHaveText('Marshmallow Coast');
    await expect(textList.nth(1)).toHaveText('Sample Text');

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
    await expect(secondRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );
    await page.waitForLoadState('networkidle');

    // Check removed from Settings pane.
    await expect(textField.locator('tr.draggable')).toHaveCount(1);
    await expect(textField).not.toContainText('Sample Text');

    // Check removed from preview pane.
    // Uncomment when https://www.drupal.org/project/canvas/issues/3586289 is fixed.
    //previewFrame = await canvas.getActivePreviewFrame();
    //textList = previewFrame
    //    .getByTestId('text-component')
    //    .locator('#text-list li');
    //await expect(textList).toHaveCount(1);
    //await expect(textList).not.toHaveText('Sample Text');

    // Add a new row and populate all values.
    await textField.getByRole('button', { name: '+ Add new' }).click();
    await expect(textField.locator('tr.draggable')).toHaveCount(2);

    await canvas.editMultiValueProp(
      'Text (Unlimited)',
      'Hello, world!',
      0,
      'string',
    );
    await canvas.editMultiValueProp('Text (Unlimited)', 'Catbro', 1, 'string');

    // Verify text in the Settings pane is updated.
    await expect(textField).toContainText('Hello, world!');
    await expect(textField).toContainText('Catbro');

    // Verify text in the Preview pane is updated.
    previewFrame = await canvas.getActivePreviewFrame();
    textList = previewFrame
      .getByTestId('text-component')
      .locator('#text-list li');
    await expect(textList).toHaveCount(2);
    await expect(textList.nth(0)).toHaveText('Hello, world!');
    await expect(textList.nth(1)).toHaveText('Catbro');

    let labels = textField.locator('[data-canvas-multivalue-label="true"]');

    // Drag row 2 (Catbro) to before row 1 (Hello, world).
    await canvas.reorderMultiValueProp('Text (Unlimited)', 1, 0);
    await expect(labels.nth(0)).toHaveText('Catbro');
    await expect(labels.nth(1)).toHaveText('Hello, world!');

    // Verify final order: Catbro, Hello, world!.
    await expect(labels.nth(0)).toHaveText('Catbro');
    await expect(labels.nth(1)).toHaveText('Hello, world!');

    // Verify text in the Preview pane is updated.
    previewFrame = await canvas.getActivePreviewFrame();
    textList = previewFrame
      .getByTestId('text-component')
      .locator('#text-list li');
    await expect(textList).toHaveCount(2);
    await expect(textList.nth(0)).toHaveText('Catbro');
    await expect(textList.nth(1)).toHaveText('Hello, world!');

    // Reload the page and verify the order is still the same.
    await page.reload();
    await canvas.waitForEditorUi();
    textField = page.locator('.field--type-string').filter({
      has: page.getByRole('heading', { name: 'Text (Unlimited)' }),
    });
    await expect(textField).toBeVisible();
    // An extra "Empty" item is added.
    await expect(textField.locator('tr.draggable')).toHaveCount(2);
    labels = textField.locator('[data-canvas-multivalue-label="true"]');
    await expect(labels.nth(0)).toHaveText('Catbro');
    await expect(labels.nth(1)).toHaveText('Hello, world!');
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
    ).not.toBeVisible();

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

    // Add another item and verify that it can now be removed.
    await canvas.addMultiValueProp('Text (Required Unlimited)*');
  });

  test('Link items', async ({ page, canvas }) => {
    // Absolute links.
    const previewFrame = await canvas.getActivePreviewFrame();
    const absoluteLinkList = previewFrame
      .getByTestId('link-component')
      .locator('#link-list li');
    await expect(absoluteLinkList).toHaveCount(2);
    await expect(
      absoluteLinkList.locator('a[href="https://drupal.org"]'),
    ).toBeVisible();
    await expect(
      absoluteLinkList.locator('a[href="https://example.com"]'),
    ).toBeVisible();

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
    await popover.getByRole('button', { name: 'Close' }).dispatchEvent('click');
    await expect(firstRow.getByRole('dialog')).toHaveAttribute(
      'data-state',
      'closed',
    );

    // Relative links.
    const relativeLinkList = previewFrame
      .getByTestId('relative_link-component')
      .locator('#relative-link-list li');
    await expect(relativeLinkList).toHaveCount(2);
    await expect(relativeLinkList.locator('a[href="/about"]')).toBeVisible();
    await expect(relativeLinkList.locator('a[href="/contact"]')).toBeVisible();
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

    const previewFrame = await canvas.getActivePreviewFrame();
    const numberList = previewFrame
      .getByTestId('number-component')
      .locator('#number-list li');

    await expect(numberList).toHaveCount(2);
    await expect(numberList.nth(0)).toHaveText('42.5');
    await expect(numberList.nth(1)).toHaveText('100');

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
  });

  test('Datetime', async ({ page, canvas }) => {
    // Date and time.
    const dateTimeField = page.locator('.field--type-datetime').filter({
      has: page.getByRole('heading', {
        name: 'DateTime (Limited)',
        exact: true,
      }),
    });

    let firstRow = dateTimeField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit DateTime/ }).click();
    let popover = firstRow.getByRole('dialog');
    await expect(
      popover.getByText('DateTime (Limited)', { exact: true }),
    ).toBeVisible();
    await expect(popover.locator('input[type="date"]')).toBeVisible();
    await expect(popover.locator('input[type="time"]')).toBeVisible();
    await expect(popover.getByRole('textbox', { name: 'Date' })).toHaveValue(
      '',
    );
    await expect(popover.getByRole('textbox', { name: 'Time' })).toHaveValue(
      '',
    );
    await popover.locator('input[type="date"]').fill('2000-01-14');
    await popover.locator('input[type="time"]').fill('12:42:01');

    // @todo the value isn't rendering in the preview.
    // verify it has rendered correctly.
    // https://www.drupal.org/project/canvas/issues/3586357

    // Just date.
    const dateField = page.locator('.field--type-datetime').filter({
      has: page.getByRole('heading', {
        name: 'Date (Limited)',
        exact: true,
      }),
    });
    firstRow = dateField.locator('tr.draggable').first();
    await firstRow.getByRole('button', { name: /^Edit Date/ }).click();
    popover = firstRow.getByRole('dialog');
    await expect(
      popover.getByText('Date (Limited)', { exact: true }),
    ).toBeVisible();
    await expect(popover.locator('input[type="date"]')).toBeVisible();
    await expect(popover.locator('input[type="time"]')).not.toBeVisible();
    await expect(popover.getByRole('textbox', { name: 'Date' })).toHaveValue(
      '',
    );

    // @todo the value isn't rendering in the preview.
    // verify it has rendered correctly.
    // https://www.drupal.org/project/canvas/issues/3586357

    // @todo we should be able to remove/clear a datetime value in the limited
    // context, but the removal button is currently greyed out.
    // https://www.drupal.org/project/canvas/issues/3586358
  });
});
