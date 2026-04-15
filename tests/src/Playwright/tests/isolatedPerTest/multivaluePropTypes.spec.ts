import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';
import {
  clickAddNew,
  closePopoverViaCloseButton,
  COMPONENT_ID,
  dragRow,
  formatDateForDisplay,
  formatDatetimeForDisplay,
  getAllRowTexts,
  getField,
  getForm,
  openPopoverForRow,
  PROP_NAMES,
  typeDatetimeInPopover,
  typeInPopover,
  typeInPopoverWithoutCommit,
  typeRelativeLinkViaAutocomplete,
  verifyRowText,
} from './multivaluePropTypes.helpers.js';

interface NumericTypeTestConfig {
  propName: string;
  propNameLimited: string;
  listId: string;
  unlimitedLabel: string;
  typeName: string;
  val1: string;
  val2: string;
  addVal: string;
  persistVal: string;
}

function registerNumericTypeTests(config: NumericTypeTestConfig): void {
  const {
    propName,
    propNameLimited,
    listId,
    unlimitedLabel,
    typeName,
    val1,
    val2,
    addVal,
    persistVal,
  } = config;

  test.describe(`unlimited variant ${typeName}`, () => {
    test(`renders with ${typeName} field and "+ Add new" button`, async ({
      page,
    }) => {
      const field = getField(page, propName);
      await expect(getForm(page)).toBeVisible();
      await expect(field).toBeAttached();
      await expect(
        field.getByRole('button', { name: '+ Add new' }),
      ).toBeVisible();
    });

    test(`can add and edit ${typeName} values via popover`, async ({
      page,
      canvas,
    }) => {
      const field = getField(page, propName);
      await clickAddNew(field);
      await expect(field.locator('tbody tr')).toHaveCount(4);
      await openPopoverForRow(page, field, 2);
      await typeInPopover(page, 'input[type="number"]', addVal);
      await verifyRowText(field, 2, addVal);

      const previewFrame = await canvas.getActivePreviewFrame();
      const listItems = previewFrame.locator(`${listId} li`);
      await expect(listItems.nth(0)).toContainText(val1);
      await expect(listItems.nth(1)).toContainText(val2);
      await expect(listItems.nth(2)).toContainText(addVal);
      expect(await getAllRowTexts(field)).toEqual([val1, val2, addVal, '']);
    });

    test('can remove items using the popover Remove button', async ({
      page,
      canvas,
    }) => {
      const field = getField(page, propName);
      await expect(field.locator('tbody tr')).toHaveCount(3);
      await openPopoverForRow(page, field, 0);
      await page
        .locator('[role="dialog"]')
        .getByRole('button', { name: /Remove/i })
        .click();
      await expect(page.locator('[role="dialog"]')).not.toBeVisible();
      await expect(field.locator('tbody tr')).toHaveCount(2);
      expect(await getAllRowTexts(field)).toEqual([val2, '']);

      // Assert that page is also updated.
      const previewFrame = await canvas.getActivePreviewFrame();
      const listItems = previewFrame.locator(`${listId} li`);
      await expect(listItems).toHaveCount(1);
      await expect(listItems.nth(0)).toContainText(val2);
      await expect(previewFrame.locator(listId)).not.toContainText(val1);
    });

    test('popover opens and closes correctly', async ({ page }) => {
      const field = getField(page, propName);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      await expect(dialog.locator('input[type="number"]')).toHaveValue(val1);
      await expect(dialog.locator('[aria-label="Close"]')).toBeVisible();
      await dialog.locator('[aria-label="Close"]').click();
      await expect(dialog).not.toBeVisible();
    });

    test(`popover header shows the correct field label for unlimited ${typeName}`, async ({
      page,
    }) => {
      const field = getField(page, propName);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
        unlimitedLabel,
      );
      await dialog.locator('[aria-label="Close"]').click();
    });

    test('can reorder items using drag and drop', async ({ page, canvas }) => {
      const field = getField(page, propName);
      const previewFrame1 = await canvas.getActivePreviewFrame();
      const listItems1 = previewFrame1.locator(`${listId} li`);
      await expect(listItems1.nth(0)).toContainText(val1);
      await expect(listItems1.nth(1)).toContainText(val2);
      await dragRow(field, 0, 1);
      // Verify the preview reflects the new order.
      const previewFrame2 = await canvas.getActivePreviewFrame();
      const listItems2 = previewFrame2.locator(`${listId} li`);
      await expect(listItems2.nth(0)).toContainText(val2);
      await expect(listItems2.nth(1)).toContainText(val1);
      expect(await getAllRowTexts(field)).toEqual([val2, val1, '']);
      await page.reload();

      // Assert that the order of dragging is also maintained after page refresh.
      const previewFrame3 = await canvas.getActivePreviewFrame();
      const listItems3 = previewFrame3.locator(`${listId} li`);
      await expect(listItems3.nth(0)).toContainText(val2);
      await expect(listItems3.nth(1)).toContainText(val1);
      expect(await getAllRowTexts(field)).toEqual([val2, val1, '']);
    });

    test('new value added via "+ Add new" is retained after page refresh', async ({
      page,
      canvas,
    }) => {
      const field = getField(page, propName);
      await clickAddNew(field);
      await expect(field.locator('tbody tr')).toHaveCount(4);
      await openPopoverForRow(page, field, 2);
      await typeInPopover(page, 'input[type="number"]', persistVal);
      await verifyRowText(field, 2, persistVal);
      const previewFrame = await canvas.getActivePreviewFrame();
      const listItems = previewFrame.locator(`${listId} li`);
      // Asserting that the values are updated on the page instance as well.
      await expect(listItems.nth(0)).toContainText(val1);
      await expect(listItems.nth(1)).toContainText(val2);
      await expect(listItems.nth(2)).toContainText(persistVal);
      expect(await getAllRowTexts(field)).toEqual([val1, val2, persistVal, '']);

      await page.reload();
      const previewFrame2 = await canvas.getActivePreviewFrame();
      const listItems2 = previewFrame2.locator(`${listId} li`);
      // Asserting that the values are same on the page instance after refresh.
      await expect(listItems2.nth(0)).toContainText(val1);
      await expect(listItems2.nth(1)).toContainText(val2);
      await expect(listItems2.nth(2)).toContainText(persistVal);
      expect(await getAllRowTexts(field)).toEqual([val1, val2, persistVal, '']);
    });

    test(`popover discards uncommitted ${typeName} changes when closed via × button`, async ({
      page,
    }) => {
      const field = getField(page, propName);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      const input = dialog.locator('input[type="number"]');
      await expect(input).toHaveValue(val1);

      // Make an uncommitted change by filling the input without pressing Enter.
      const uncommittedVal = '999';
      await typeInPopoverWithoutCommit(
        page,
        'input[type="number"]',
        uncommittedVal,
      );
      await expect(input).toHaveValue(uncommittedVal);

      // Close the popover via the × button
      await closePopoverViaCloseButton(page);

      // Reopen the same row and verify the original value is still there.
      await openPopoverForRow(page, field, 0);
      const dialog2 = page.locator('[role="dialog"]');
      const input2 = dialog2.locator('input[type="number"]');
      await expect(input2).toHaveValue(val1);
      await closePopoverViaCloseButton(page);
    });
  });

  test.describe(`limited variant ${typeName}`, () => {
    test(`renders with ${typeName} field and "+ Add new" button is not visible`, async ({
      page,
      canvas,
    }) => {
      const field = getField(page, propNameLimited);
      await expect(getForm(page)).toBeVisible();
      await expect(field).toBeAttached();
      await expect(
        field.getByRole('button', { name: '+ Add new' }),
      ).not.toBeVisible();

      // Coverage for editing values in limited variant
      await openPopoverForRow(page, field, 0);
      await typeInPopover(page, 'input[type="number"]', addVal);
      await verifyRowText(field, 0, addVal);

      const previewFrame = await canvas.getActivePreviewFrame();
      const listItems = previewFrame.locator(
        `${listId.replace('-list', '-limited-list')} li`,
      );
      await expect(listItems.nth(0)).toContainText(addVal);
      await expect(listItems.nth(1)).toContainText(val2);
      expect(await getAllRowTexts(field)).toEqual([addVal, val2, '']);
    });

    test(`popover has disabled Remove button for ${typeName} limited variant`, async ({
      page,
    }) => {
      const field = getField(page, propNameLimited);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      const removeButton = dialog.getByRole('button', { name: /Remove/i });
      await expect(removeButton).toBeVisible();
      await expect(removeButton).toBeDisabled();
      await expect(removeButton).toHaveAttribute('data-disabled', 'true');
      await dialog.locator('[aria-label="Close"]').click();
    });

    test('can reorder items using drag and drop', async ({ page, canvas }) => {
      const field = getField(page, propNameLimited);
      const previewFrame1 = await canvas.getActivePreviewFrame();
      const listItems1 = previewFrame1.locator(
        `${listId.replace('-list', '-limited-list')} li`,
      );
      await expect(listItems1.nth(0)).toContainText(val1);
      await expect(listItems1.nth(1)).toContainText(val2);
      await dragRow(field, 0, 1);
      // Verify the preview reflects the new order.
      const previewFrame2 = await canvas.getActivePreviewFrame();
      const listItems2 = previewFrame2.locator(
        `${listId.replace('-list', '-limited-list')} li`,
      );
      await expect(listItems2.nth(0)).toContainText(val2);
      await expect(listItems2.nth(1)).toContainText(val1);
      expect(await getAllRowTexts(field)).toEqual([val2, val1, '']);
      await page.reload();

      // Assert that the order of dragging is also maintained after page refresh.
      const previewFrame3 = await canvas.getActivePreviewFrame();
      const listItems3 = previewFrame3.locator(
        `${listId.replace('-list', '-limited-list')} li`,
      );
      await expect(listItems3.nth(0)).toContainText(val2);
      await expect(listItems3.nth(1)).toContainText(val1);
      expect(await getAllRowTexts(field)).toEqual([val2, val1, '']);
    });
  });
}

test.describe('Multivalue Prop Types', () => {
  let canvasPage: { entity_type: string; entity_id: number };

  test.beforeEach(async ({ drupal, canvas }) => {
    await drupal.loginAsAdmin();
    await drupal.installModules([
      'canvas_test_sdc',
      'datetime',
      'datetime_range',
      // @todo remove once https://drupal.org/i/3577946 is fixed.
      'canvas_dev_mode',
    ]);
    await drupal.logout();
    await drupal.login({ username: 'editor', password: 'editor' });
    canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: COMPONENT_ID });
  });

  test.describe('Text Component', () => {
    test.describe('unlimited variant', () => {
      test('renders with text field and "+ Add new" button', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT);
        await expect(getForm(page)).toBeVisible();
        await expect(field).toBeAttached();
        await expect(
          field.getByRole('button', { name: '+ Add new' }),
        ).toBeVisible();
      });

      test('can add and edit text values via popover', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(4);
        await openPopoverForRow(page, field, 2);
        await typeInPopover(page, '.form-text', 'Marshmallow Coast');
        await verifyRowText(field, 2, 'Marshmallow Coast');

        const previewFrame = await canvas.getActivePreviewFrame();
        const textListItems = previewFrame.locator('#text-list li');
        // Asserting that the values are updated on the page instance as well.
        await expect(textListItems.nth(0)).toContainText('Hello World');
        await expect(textListItems.nth(1)).toContainText('Sample Text');
        await expect(textListItems.nth(2)).toContainText('Marshmallow Coast');
        expect(await getAllRowTexts(field)).toEqual([
          'Hello World',
          'Sample Text',
          'Marshmallow Coast',
          '',
        ]);
      });

      test('can remove items using the popover Remove button', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT);
        await expect(field.locator('tbody tr')).toHaveCount(3);
        await openPopoverForRow(page, field, 0);
        await page
          .locator('[role="dialog"]')
          .getByRole('button', { name: /Remove/i })
          .click();
        await expect(page.locator('[role="dialog"]')).not.toBeVisible();
        await expect(field.locator('tbody tr')).toHaveCount(2);
        expect(await getAllRowTexts(field)).toEqual(['Sample Text', '']);

        // Assert that page is also updated.
        const previewFrame = await canvas.getActivePreviewFrame();
        const textListItems = previewFrame.locator('#text-list li');
        await expect(textListItems).toHaveCount(1);
        await expect(textListItems.nth(0)).toContainText('Sample Text');
        await expect(previewFrame.locator('#text-list')).not.toContainText(
          'Hello World',
        );
      });

      test('popover opens and closes correctly', async ({ page }) => {
        const field = getField(page, PROP_NAMES.TEXT);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('input[type="text"]')).toHaveValue(
          'Hello World',
        );
        await expect(dialog.locator('[aria-label="Close"]')).toBeVisible();
        await dialog.locator('[aria-label="Close"]').click();
        await expect(dialog).not.toBeVisible();
      });

      test('popover header shows the correct field label for unlimited text', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
          'Text (Unlimited)',
        );
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('can reorder items using drag and drop', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT);
        const previewFrame1 = await canvas.getActivePreviewFrame();
        const textListItems1 = previewFrame1.locator('#text-list li');
        await expect(textListItems1.nth(0)).toContainText('Hello World');
        await expect(textListItems1.nth(1)).toContainText('Sample Text');
        await dragRow(field, 0, 1);
        // Verify the preview reflects the new order.
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const textListItems2 = previewFrame2.locator('#text-list li');
        await expect(textListItems2.nth(0)).toContainText('Sample Text');
        await expect(textListItems2.nth(1)).toContainText('Hello World');
        expect(await getAllRowTexts(field)).toEqual([
          'Sample Text',
          'Hello World',
          '',
        ]);
        await page.reload();

        // Assert that the order of dragging is also maintained after page refresh.
        const previewFrame3 = await canvas.getActivePreviewFrame();
        const textListItems3 = previewFrame3.locator('#text-list li');
        // Asserting that the values are same on the page instance after refresh.
        await expect(textListItems3.nth(0)).toContainText('Sample Text');
        await expect(textListItems3.nth(1)).toContainText('Hello World');
        expect(await getAllRowTexts(field)).toEqual([
          'Sample Text',
          'Hello World',
          '',
        ]);
      });

      test('new value added via "+ Add new" is retained after page refresh', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(4);
        await openPopoverForRow(page, field, 2);
        await typeInPopover(page, '.form-text', 'Persisted Value');
        await verifyRowText(field, 2, 'Persisted Value');
        const previewFrame = await canvas.getActivePreviewFrame();
        const textListItems = previewFrame.locator('#text-list li');
        // Asserting that the values are updated on the page instance as well.
        await expect(textListItems.nth(0)).toContainText('Hello World');
        await expect(textListItems.nth(1)).toContainText('Sample Text');
        await expect(textListItems.nth(2)).toContainText('Persisted Value');
        expect(await getAllRowTexts(field)).toEqual([
          'Hello World',
          'Sample Text',
          'Persisted Value',
          '',
        ]);
        await page.reload();

        const previewFrame2 = await canvas.getActivePreviewFrame();
        const textListItems2 = previewFrame2.locator('#text-list li');
        // Asserting that the values are same on the page instance after refresh.
        await expect(textListItems2.nth(0)).toContainText('Hello World');
        await expect(textListItems2.nth(1)).toContainText('Sample Text');
        await expect(textListItems2.nth(2)).toContainText('Persisted Value');
        expect(await getAllRowTexts(field)).toEqual([
          'Hello World',
          'Sample Text',
          'Persisted Value',
          '',
        ]);
      });

      test('popover discards uncommitted text changes when closed via × button', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const input = dialog.locator('input[type="text"]');
        await expect(input).toHaveValue('Hello World');

        // Make an uncommitted change by filling the input without pressing Enter.
        const uncommittedText = 'Uncommitted changes';
        await typeInPopoverWithoutCommit(
          page,
          'input[type="text"]',
          uncommittedText,
        );
        await expect(input).toHaveValue(uncommittedText);

        // Close the popover via the × button.
        await closePopoverViaCloseButton(page);

        // Reopen the same row and verify the original value is still there.
        await openPopoverForRow(page, field, 0);
        const dialog2 = page.locator('[role="dialog"]');
        const input2 = dialog2.locator('input[type="text"]');
        await expect(input2).toHaveValue('Hello World');
        await closePopoverViaCloseButton(page);
      });
    });

    test.describe('limited variant', () => {
      test('renders with text field and "+ Add new" button is not visible', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT_LIMITED);
        await expect(getForm(page)).toBeVisible();
        await expect(field).toBeAttached();
        await expect(
          field.getByRole('button', { name: '+ Add new' }),
        ).not.toBeVisible();

        // Coverage for editing values in limited variant
        await openPopoverForRow(page, field, 0);
        await typeInPopover(page, '.form-text', 'Edited Text');
        await verifyRowText(field, 0, 'Edited Text');

        const previewFrame = await canvas.getActivePreviewFrame();
        const textListItems = previewFrame.locator('#text-limited-list li');
        await expect(textListItems.nth(0)).toContainText('Edited Text');
        await expect(textListItems.nth(1)).toContainText('Sample Text');
        expect(await getAllRowTexts(field)).toEqual([
          'Edited Text',
          'Sample Text',
          '',
        ]);
      });

      test('popover has disabled Remove button for text limited variant', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT_LIMITED);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const removeButton = dialog.getByRole('button', { name: /Remove/i });
        await expect(removeButton).toBeVisible();
        await expect(removeButton).toBeDisabled();
        await expect(removeButton).toHaveAttribute('data-disabled', 'true');
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('popover header shows the correct field label for limited text', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT_LIMITED);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
          'Text (Limited)',
        );
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('can reorder items using drag and drop', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT_LIMITED);
        const previewFrame1 = await canvas.getActivePreviewFrame();
        const textListItems1 = previewFrame1.locator('#text-limited-list li');
        await expect(textListItems1.nth(0)).toContainText('Hello World');
        await expect(textListItems1.nth(1)).toContainText('Sample Text');
        await dragRow(field, 0, 1);
        // Verify the preview reflects the new order.
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const textListItems2 = previewFrame2.locator('#text-limited-list li');
        await expect(textListItems2.nth(0)).toContainText('Sample Text');
        await expect(textListItems2.nth(1)).toContainText('Hello World');
        expect(await getAllRowTexts(field)).toEqual([
          'Sample Text',
          'Hello World',
          '',
        ]);
        await page.reload();

        // Assert that the order of dragging is also maintained after page refresh.
        const previewFrame3 = await canvas.getActivePreviewFrame();
        const textListItems3 = previewFrame3.locator('#text-limited-list li');
        await expect(textListItems3.nth(0)).toContainText('Sample Text');
        await expect(textListItems3.nth(1)).toContainText('Hello World');
        expect(await getAllRowTexts(field)).toEqual([
          'Sample Text',
          'Hello World',
          '',
        ]);
      });
    });
  });

  test.describe('Text Required Component', () => {
    test.describe('unlimited variant', () => {
      test('remove button is disabled with single value and enabled when second item is added', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.TEXT_REQUIRED);
        // Remove the first item.
        await openPopoverForRow(page, field, 0);
        await page
          .locator('[role="dialog"]')
          .getByRole('button', { name: /Remove/i })
          .click();
        await expect(page.locator('[role="dialog"]')).not.toBeVisible();

        // Now there should be 2 rows (1 value + 1 empty).
        await expect(field.locator('tbody tr')).toHaveCount(2);
        // Remove the second item.
        await openPopoverForRow(page, field, 1);
        await page
          .locator('[role="dialog"]')
          .getByRole('button', { name: /Remove/i })
          .click();
        await expect(page.locator('[role="dialog"]')).not.toBeVisible();
        // Now there should be 1 empty row.
        await expect(field.locator('tbody tr')).toHaveCount(1);

        // Open the popover for the remaining single value row — remove should be disabled.
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const removeButton = dialog.getByRole('button', { name: /Remove/i });
        await expect(removeButton).toBeVisible();
        await expect(removeButton).toBeDisabled();
        await expect(removeButton).toHaveAttribute('data-disabled', 'true');
        await dialog.locator('[aria-label="Close"]').click();

        // Add a second item.
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(2);
        await openPopoverForRow(page, field, 1);
        await typeInPopover(page, '.form-text', 'New Item');
        await verifyRowText(field, 1, 'New Item');

        // Open the popover for the first row — remove should now be enabled.
        await openPopoverForRow(page, field, 0);
        const dialog2 = page.locator('[role="dialog"]');
        const removeButton2 = dialog2.getByRole('button', { name: /Remove/i });
        await expect(removeButton2).toBeVisible();
        await expect(removeButton2).toBeEnabled();
        await dialog2.locator('[aria-label="Close"]').click();
      });
    });
  });

  test.describe('Link Component', () => {
    test.describe('unlimited variant', () => {
      test('renders with link field and "+ Add new" button', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.LINK);
        await expect(getForm(page)).toBeVisible();
        await expect(field).toBeAttached();
        await expect(
          field.getByRole('button', { name: '+ Add new' }),
        ).toBeVisible();
      });

      test('can add and edit link values via popover', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.LINK);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(4);
        await openPopoverForRow(page, field, 2);
        await typeInPopover(
          page,
          'input[type="url"]',
          'https://examplenew.com',
        );
        await verifyRowText(field, 2, 'https://examplenew.com');
        const previewFrame = await canvas.getActivePreviewFrame();
        const linkListItems = previewFrame.locator('#link-list li');
        // Asserting that the values are updated on the page instance as well.
        await expect(linkListItems.nth(0)).toContainText('https://drupal.org');
        await expect(linkListItems.nth(1)).toContainText('https://example.com');
        await expect(linkListItems.nth(2)).toContainText(
          'https://examplenew.com',
        );
        expect(await getAllRowTexts(field)).toEqual([
          'https://drupal.org',
          'https://example.com',
          'https://examplenew.com',
          '',
        ]);
      });

      test('can remove items using the popover Remove button', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.LINK);
        await expect(field.locator('tbody tr')).toHaveCount(3);
        await openPopoverForRow(page, field, 0);
        await page
          .locator('[role="dialog"]')
          .getByRole('button', { name: /Remove/i })
          .click();
        await expect(page.locator('[role="dialog"]')).not.toBeVisible();
        await expect(field.locator('tbody tr')).toHaveCount(2);
        expect(await getAllRowTexts(field)).toEqual([
          'https://example.com',
          '',
        ]);

        // Assert that page is also updated.
        const previewFrame = await canvas.getActivePreviewFrame();
        const linkListItems = previewFrame.locator('#link-list li');
        await expect(linkListItems).toHaveCount(1);
        await expect(linkListItems.nth(0)).toContainText('https://example.com');
        await expect(previewFrame.locator('#link-list')).not.toContainText(
          'https://drupal.org',
        );
      });

      test('popover opens and closes correctly', async ({ page }) => {
        const field = getField(page, PROP_NAMES.LINK);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('input[type="url"]')).toHaveValue(
          'https://drupal.org',
        );
        await expect(dialog.locator('[aria-label="Close"]')).toBeVisible();
        await dialog.locator('[aria-label="Close"]').click();
        await expect(dialog).not.toBeVisible();
      });

      test('popover header shows the correct field label for unlimited link', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.LINK);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
          'Link (Unlimited)',
        );
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('can reorder items using drag and drop', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.LINK);
        const previewFrame1 = await canvas.getActivePreviewFrame();
        const linkListItems1 = previewFrame1.locator('#link-list li');
        await expect(linkListItems1.nth(0)).toContainText('https://drupal.org');
        await expect(linkListItems1.nth(1)).toContainText(
          'https://example.com',
        );
        expect(await getAllRowTexts(field)).toEqual([
          'https://drupal.org',
          'https://example.com',
          '',
        ]);
        await dragRow(field, 0, 1);
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const linkListItems2 = previewFrame2.locator('#link-list li');
        await expect(linkListItems2.nth(0)).toContainText(
          'https://example.com',
        );
        await expect(linkListItems2.nth(1)).toContainText('https://drupal.org');
        expect(await getAllRowTexts(field)).toEqual([
          'https://example.com',
          'https://drupal.org',
          '',
        ]);

        // Verify the preview reflects the new order.
        const previewFrame = await canvas.getActivePreviewFrame();
        const linkListItems = previewFrame.locator('#link-list li');
        await expect(linkListItems.nth(0)).toContainText('https://example.com');
        await expect(linkListItems.nth(1)).toContainText('https://drupal.org');
        expect(await getAllRowTexts(field)).toEqual([
          'https://example.com',
          'https://drupal.org',
          '',
        ]);
        await page.reload();

        // Assert that the order of dragging is also maintained after page refresh.
        const previewFrame3 = await canvas.getActivePreviewFrame();
        const linkListItems3 = previewFrame3.locator('#link-list li');
        await expect(linkListItems3.nth(0)).toContainText(
          'https://example.com',
        );
        await expect(linkListItems3.nth(1)).toContainText('https://drupal.org');
        expect(await getAllRowTexts(field)).toEqual([
          'https://example.com',
          'https://drupal.org',
          '',
        ]);
      });

      test('new value added via "+ Add new" is retained after page refresh', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.LINK);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(4);
        await openPopoverForRow(page, field, 2);
        await typeInPopover(page, 'input[type="url"]', 'https://persisted.com');
        await verifyRowText(field, 2, 'https://persisted.com');
        const previewFrame = await canvas.getActivePreviewFrame();
        const linkListItems = previewFrame.locator('#link-list li');
        // Asserting that the values are updated on the page instance as well.
        await expect(linkListItems.nth(0)).toContainText('https://drupal.org');
        await expect(linkListItems.nth(1)).toContainText('https://example.com');
        await expect(linkListItems.nth(2)).toContainText(
          'https://persisted.com',
        );
        expect(await getAllRowTexts(field)).toEqual([
          'https://drupal.org',
          'https://example.com',
          'https://persisted.com',
          '',
        ]);

        await page.reload();
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const linkListItems2 = previewFrame2.locator('#link-list li');
        // Asserting that the values are same on the page instance after refresh.
        await expect(linkListItems2.nth(0)).toContainText('https://drupal.org');
        await expect(linkListItems2.nth(1)).toContainText(
          'https://example.com',
        );
        await expect(linkListItems2.nth(2)).toContainText(
          'https://persisted.com',
        );
        expect(await getAllRowTexts(field)).toEqual([
          'https://drupal.org',
          'https://example.com',
          'https://persisted.com',
          '',
        ]);
      });

      test('popover discards uncommitted link changes when closed via × button', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.LINK);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const input = dialog.locator('input[type="url"]');
        await expect(input).toHaveValue('https://drupal.org');

        // Make an uncommitted change by filling the input without pressing Enter.
        const uncommittedUrl = 'https://uncommitted.com';
        await typeInPopoverWithoutCommit(
          page,
          'input[type="url"]',
          uncommittedUrl,
        );
        await expect(input).toHaveValue(uncommittedUrl);

        // Close the popover via the × button.
        await closePopoverViaCloseButton(page);

        // Reopen the same row and verify the original value is still there.
        await openPopoverForRow(page, field, 0);
        const dialog2 = page.locator('[role="dialog"]');
        const input2 = dialog2.locator('input[type="url"]');
        await expect(input2).toHaveValue('https://drupal.org');
        await closePopoverViaCloseButton(page);
      });
    });

    test.describe('limited variant', () => {
      test('renders with link field and "+ Add new" button is not visible', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.LINK_LIMITED);
        await expect(getForm(page)).toBeVisible();
        await expect(field).toBeAttached();
        await expect(
          field.getByRole('button', { name: '+ Add new' }),
        ).not.toBeVisible();

        // Coverage for editing values in limited variant
        await openPopoverForRow(page, field, 0);
        await typeInPopover(page, 'input[type="url"]', 'https://edited.com');
        await verifyRowText(field, 0, 'https://edited.com');

        const previewFrame = await canvas.getActivePreviewFrame();
        const linkListItems = previewFrame.locator('#link-limited-list li');
        await expect(linkListItems.nth(0)).toContainText('https://edited.com');
        await expect(linkListItems.nth(1)).toContainText('https://example.com');
        expect(await getAllRowTexts(field)).toEqual([
          'https://edited.com',
          'https://example.com',
          '',
        ]);
      });

      test('popover has disabled Remove button for link limited variant', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.LINK_LIMITED);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const removeButton = dialog.getByRole('button', { name: /Remove/i });
        await expect(removeButton).toBeVisible();
        await expect(removeButton).toBeDisabled();
        await expect(removeButton).toHaveAttribute('data-disabled', 'true');
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('popover header shows the correct field label for limited link', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.LINK_LIMITED);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
          'Link (Limited)',
        );
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('can reorder items using drag and drop', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.LINK_LIMITED);
        const previewFrame1 = await canvas.getActivePreviewFrame();
        const linkListItems1 = previewFrame1.locator('#link-limited-list li');
        await expect(linkListItems1.nth(0)).toContainText('https://drupal.org');
        await expect(linkListItems1.nth(1)).toContainText(
          'https://example.com',
        );
        await dragRow(field, 0, 1);
        // Verify the preview reflects the new order.
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const linkListItems2 = previewFrame2.locator('#link-limited-list li');
        await expect(linkListItems2.nth(0)).toContainText(
          'https://example.com',
        );
        await expect(linkListItems2.nth(1)).toContainText('https://drupal.org');
        expect(await getAllRowTexts(field)).toEqual([
          'https://example.com',
          'https://drupal.org',
          '',
        ]);
        await page.reload();

        // Assert that the order of dragging is also maintained after page refresh.
        const previewFrame3 = await canvas.getActivePreviewFrame();
        const linkListItems3 = previewFrame3.locator('#link-limited-list li');
        await expect(linkListItems3.nth(0)).toContainText(
          'https://example.com',
        );
        await expect(linkListItems3.nth(1)).toContainText('https://drupal.org');
        expect(await getAllRowTexts(field)).toEqual([
          'https://example.com',
          'https://drupal.org',
          '',
        ]);
      });
    });
  });

  test.describe('Number Component', () => {
    registerNumericTypeTests({
      propName: PROP_NAMES.NUMBER,
      propNameLimited: PROP_NAMES.NUMBER_LIMITED,
      listId: '#number-list',
      unlimitedLabel: 'Number (Unlimited)',
      typeName: 'number',
      val1: '42',
      val2: '100',
      addVal: '150',
      persistVal: '999',
    });
  });

  test.describe('Integer Component', () => {
    registerNumericTypeTests({
      propName: PROP_NAMES.INTEGER,
      propNameLimited: PROP_NAMES.INTEGER_LIMITED,
      listId: '#integer-list',
      unlimitedLabel: 'Integer (Unlimited)',
      typeName: 'integer',
      val1: '7',
      val2: '14',
      addVal: '21',
      persistVal: '35',
    });
  });

  test.describe('Decimal Values Support', () => {
    test('number type accepts decimal values', async ({ page, canvas }) => {
      const field = getField(page, PROP_NAMES.NUMBER);
      await clickAddNew(field);
      await expect(field.locator('tbody tr')).toHaveCount(4);
      await openPopoverForRow(page, field, 2);
      const decimalValue = '123.45';
      await typeInPopover(page, 'input[type="number"]', decimalValue);
      await verifyRowText(field, 2, decimalValue);

      const previewFrame = await canvas.getActivePreviewFrame();
      const listItems = previewFrame.locator('#number-list li');
      await expect(listItems.nth(2)).toContainText(decimalValue);
      expect(await getAllRowTexts(field)).toEqual([
        '42',
        '100',
        decimalValue,
        '',
      ]);
    });

    test('integer type rejects decimal values', async ({ page }) => {
      const field = getField(page, PROP_NAMES.INTEGER);
      await clickAddNew(field);
      await expect(field.locator('tbody tr')).toHaveCount(4);
      await openPopoverForRow(page, field, 2);
      const decimalValue = '123.45';
      const input = page
        .locator('[role="dialog"]')
        .locator('input[type="number"]');
      await input.fill(decimalValue);
      // Attempt to submit the decimal value by pressing Enter.
      await input.press('Enter');
      // The popover should remain open because the integer input rejects decimal values.
      await expect(page.locator('[role="dialog"]')).toBeVisible();
      // Verify that a validation error message appears with suggestions for nearest valid values.
      const validationMessage = await input.evaluate(
        (el: HTMLInputElement) => el.validationMessage,
      );
      expect(validationMessage).toMatch(/Please enter a valid value/);
      // Close the popover without saving
      await page
        .locator('[role="dialog"]')
        .locator('[aria-label="Close"]')
        .click();
      // Verify that the new row is still empty since the decimal was rejected.
      const newRowText = await getAllRowTexts(field);
      expect(newRowText[2]).toBe('');
    });
  });

  test.describe('DateTime Component', () => {
    test.describe('unlimited variant', () => {
      test('renders with datetime field and "+ Add new" button', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME);
        await expect(getForm(page)).toBeVisible();
        await expect(field).toBeAttached();
        await expect(
          field.getByRole('button', { name: '+ Add new' }),
        ).toBeVisible();
      });

      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('can add and edit datetime values via popover', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(3);
        await openPopoverForRow(page, field, 0);
        await typeDatetimeInPopover(page, '2025-12-24', '08:00');
        await verifyRowText(
          field,
          0,
          formatDatetimeForDisplay('2025-12-24', '08:00'),
        );
        await openPopoverForRow(page, field, 1);
        await typeDatetimeInPopover(page, '2025-12-25', '09:00');
        await verifyRowText(
          field,
          1,
          formatDatetimeForDisplay('2025-12-25', '09:00'),
        );
        await openPopoverForRow(page, field, 2);
        await typeDatetimeInPopover(page, '2025-12-26', '10:00');
        await verifyRowText(
          field,
          2,
          formatDatetimeForDisplay('2025-12-26', '10:00'),
        );

        const previewFrame = await canvas.getActivePreviewFrame();
        const listItems = previewFrame.locator('#datetime-list li');
        await expect(listItems.nth(0)).toContainText('2025-12-24');
        await expect(listItems.nth(1)).toContainText('2025-12-25');
        await expect(listItems.nth(2)).toContainText('2025-12-26');
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-12-24', '08:00'),
          formatDatetimeForDisplay('2025-12-25', '09:00'),
          formatDatetimeForDisplay('2025-12-26', '10:00'),
        ]);
      });

      test('can remove items using the popover Remove button', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME);
        await expect(field.locator('tbody tr')).toHaveCount(2);
        // Adding new values to see if the page instances are updated correctly.
        await openPopoverForRow(page, field, 0);
        await typeDatetimeInPopover(page, '2025-12-24', '08:00');
        await verifyRowText(
          field,
          0,
          formatDatetimeForDisplay('2025-12-24', '08:00'),
        );
        await openPopoverForRow(page, field, 1);
        await typeDatetimeInPopover(page, '2025-12-25', '09:00');
        await verifyRowText(
          field,
          1,
          formatDatetimeForDisplay('2025-12-25', '09:00'),
        );
        await openPopoverForRow(page, field, 0);
        await page
          .locator('[role="dialog"]')
          .getByRole('button', { name: /Remove/i })
          .click();
        await expect(page.locator('[role="dialog"]')).not.toBeVisible();
        await expect(field.locator('tbody tr')).toHaveCount(1);
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-12-25', '09:00'),
        ]);

        // Assert that page is also updated.
        const previewFrame = await canvas.getActivePreviewFrame();
        const listItems = previewFrame.locator('#datetime-list li');
        await expect(listItems).toHaveCount(1);
        await expect(listItems.nth(0)).toContainText('2025-12-25');
      });

      test('popover opens and closes correctly', async ({ page }) => {
        const field = getField(page, PROP_NAMES.DATETIME);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('input[type="date"]')).toHaveValue('');
        await expect(dialog.locator('input[type="time"]')).toBeVisible();
        await expect(dialog.locator('[aria-label="Close"]')).toBeVisible();
        await dialog.locator('[aria-label="Close"]').click();
        await expect(dialog).not.toBeVisible();
      });

      test('popover header shows the correct field label for unlimited datetime', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
          'DateTime (Unlimited)',
        );
        await dialog.locator('[aria-label="Close"]').click();
      });
      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('can reorder items using drag and drop', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME);
        // Adding new values to see if the page instances are updated correctly.
        await openPopoverForRow(page, field, 0);
        await typeDatetimeInPopover(page, '2025-12-24', '08:00');
        await verifyRowText(
          field,
          0,
          formatDatetimeForDisplay('2025-12-24', '08:00'),
        );
        await openPopoverForRow(page, field, 1);
        await typeDatetimeInPopover(page, '2025-12-25', '09:00');
        await verifyRowText(
          field,
          1,
          formatDatetimeForDisplay('2025-12-25', '09:00'),
        );
        const previewFrame1 = await canvas.getActivePreviewFrame();
        const listItems1 = previewFrame1.locator('#datetime-list li');
        await expect(listItems1.nth(0)).toContainText('2025-12-24');
        await expect(listItems1.nth(1)).toContainText('2025-12-25');
        await dragRow(field, 0, 1);
        // Verify the preview reflects the new order.
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const listItems2 = previewFrame2.locator('#datetime-list li');
        await expect(listItems2.nth(0)).toContainText('2025-12-25');
        await expect(listItems2.nth(1)).toContainText('2025-12-24');
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-12-25', '09:00'),
          formatDatetimeForDisplay('2025-12-24', '08:00'),
        ]);

        await page.reload();

        // Assert that the order of dragging is also maintained after page refresh.
        const previewFrame3 = await canvas.getActivePreviewFrame();
        const listItems3 = previewFrame3.locator('#datetime-list li');
        await expect(listItems3.nth(0)).toContainText('2025-12-25');
        await expect(listItems3.nth(1)).toContainText('2025-12-24');
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-12-25', '09:00'),
          formatDatetimeForDisplay('2025-12-24', '08:00'),
          '',
        ]);
      });

      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('new value added via "+ Add new" is retained after page refresh', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(3);
        // Adding new values to see if the page instances are updated correctly.
        await openPopoverForRow(page, field, 0);
        await typeDatetimeInPopover(page, '2025-12-24', '08:00');
        await verifyRowText(
          field,
          0,
          formatDatetimeForDisplay('2025-12-24', '08:00'),
        );
        await openPopoverForRow(page, field, 1);
        await typeDatetimeInPopover(page, '2025-12-25', '09:00');
        await verifyRowText(
          field,
          1,
          formatDatetimeForDisplay('2025-12-25', '09:00'),
        );
        await openPopoverForRow(page, field, 2);
        await typeDatetimeInPopover(page, '2026-03-01', '12:00');
        await verifyRowText(
          field,
          2,
          formatDatetimeForDisplay('2026-03-01', '12:00'),
        );
        const previewFrame = await canvas.getActivePreviewFrame();
        const listItems = previewFrame.locator('#datetime-list li');
        await expect(listItems.nth(0)).toContainText('2025-12-24');
        await expect(listItems.nth(1)).toContainText('2025-12-25');
        await expect(listItems.nth(2)).toContainText('2026-03-01');
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-12-24', '08:00'),
          formatDatetimeForDisplay('2025-12-25', '09:00'),
          formatDatetimeForDisplay('2026-03-01', '12:00'),
        ]);

        await page.reload();
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const listItems2 = previewFrame2.locator('#datetime-list li');
        await expect(listItems2.nth(0)).toContainText('2025-12-24');
        await expect(listItems2.nth(1)).toContainText('2025-12-25');
        await expect(listItems2.nth(2)).toContainText('2026-03-01');
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-12-24', '08:00'),
          formatDatetimeForDisplay('2025-12-25', '09:00'),
          formatDatetimeForDisplay('2026-03-01', '12:00'),
          '',
        ]);
      });

      test('popover discards uncommitted datetime changes when closed via × button', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(3);
        await openPopoverForRow(page, field, 0);
        await typeDatetimeInPopover(page, '2025-12-24', '08:00');
        await verifyRowText(
          field,
          0,
          formatDatetimeForDisplay('2025-12-24', '08:00'),
        );
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const dateInput = dialog.locator('input[type="date"]');
        const timeInput = dialog.locator('input[type="time"]');
        await expect(dateInput).toHaveValue('2025-12-24');
        await expect(timeInput).toHaveValue('08:00');

        // Make uncommitted changes by filling the inputs without pressing Enter.
        await dateInput.fill('2026-01-15');
        await timeInput.fill('14:30');
        await expect(dateInput).toHaveValue('2026-01-15');
        await expect(timeInput).toHaveValue('14:30');

        // Close the popover via the × button.
        await closePopoverViaCloseButton(page);

        // Reopen the same row and verify the original values are still there.
        await openPopoverForRow(page, field, 0);
        const dialog2 = page.locator('[role="dialog"]');
        const dateInput2 = dialog2.locator('input[type="date"]');
        const timeInput2 = dialog2.locator('input[type="time"]');
        await expect(dateInput2).toHaveValue('2025-12-24');
        await expect(timeInput2).toHaveValue('08:00');
        await closePopoverViaCloseButton(page);
      });
    });

    test.describe('limited variant', () => {
      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('renders with datetime field and "+ Add new" button is not visible', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME_LIMITED);
        await expect(getForm(page)).toBeVisible();
        await expect(field).toBeAttached();
        await expect(
          field.getByRole('button', { name: '+ Add new' }),
        ).not.toBeVisible();

        // Coverage for editing values in limited variant
        await openPopoverForRow(page, field, 0);
        await typeDatetimeInPopover(page, '2025-12-24', '08:00');
        await verifyRowText(
          field,
          0,
          formatDatetimeForDisplay('2025-12-24', '08:00'),
        );
        await openPopoverForRow(page, field, 1);
        await typeDatetimeInPopover(page, '2025-12-25', '09:00');
        await verifyRowText(
          field,
          1,
          formatDatetimeForDisplay('2025-12-25', '09:00'),
        );
        const previewFrame = await canvas.getActivePreviewFrame();
        const listItems = previewFrame.locator('#datetime-limited-list li');
        await expect(listItems.nth(0)).toContainText(
          formatDatetimeForDisplay('2025-12-24', '08:00'),
        );
        await expect(listItems.nth(1)).toContainText(
          formatDatetimeForDisplay('2025-12-25', '09:00'),
        );
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-12-24', '08:00'),
          formatDatetimeForDisplay('2025-12-25', '09:00'),
          '',
        ]);
      });

      test('popover has disabled Remove button for datetime limited variant', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME_LIMITED);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const removeButton = dialog.getByRole('button', { name: /Remove/i });
        await expect(removeButton).toBeVisible();
        await expect(removeButton).toBeDisabled();
        await expect(removeButton).toHaveAttribute('data-disabled', 'true');
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('popover header shows the correct field label for limited datetime', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME_LIMITED);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
          'DateTime (Limited)',
        );
        await dialog.locator('[aria-label="Close"]').click();
      });

      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('can reorder items using drag and drop', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATETIME_LIMITED);
        const previewFrame1 = await canvas.getActivePreviewFrame();
        const listItems1 = previewFrame1.locator('#datetime-limited-list li');
        await expect(listItems1.nth(0)).toContainText(
          formatDatetimeForDisplay('2025-06-15', '10:30'),
        );
        await expect(listItems1.nth(1)).toContainText(
          formatDatetimeForDisplay('2025-07-20', '14:45'),
        );
        await dragRow(field, 0, 1);
        // Verify the preview reflects the new order.
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const listItems2 = previewFrame2.locator('#datetime-limited-list li');
        await expect(listItems2.nth(0)).toContainText(
          formatDatetimeForDisplay('2025-07-20', '14:45'),
        );
        await expect(listItems2.nth(1)).toContainText(
          formatDatetimeForDisplay('2025-06-15', '10:30'),
        );
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-07-20', '14:45'),
          formatDatetimeForDisplay('2025-06-15', '10:30'),
          '',
        ]);
        await page.reload();

        // Assert that the order of dragging is also maintained after page refresh.
        const previewFrame3 = await canvas.getActivePreviewFrame();
        const listItems3 = previewFrame3.locator('#datetime-limited-list li');
        await expect(listItems3.nth(0)).toContainText(
          formatDatetimeForDisplay('2025-07-20', '14:45'),
        );
        await expect(listItems3.nth(1)).toContainText(
          formatDatetimeForDisplay('2025-06-15', '10:30'),
        );
        expect(await getAllRowTexts(field)).toEqual([
          formatDatetimeForDisplay('2025-07-20', '14:45'),
          formatDatetimeForDisplay('2025-06-15', '10:30'),
          '',
        ]);
      });
    });
  });

  test.describe('Date Component', () => {
    test.describe('unlimited variant', () => {
      test('renders with date field and "+ Add new" button', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATE);
        await expect(getForm(page)).toBeVisible();
        await expect(field).toBeAttached();
        await expect(
          field.getByRole('button', { name: '+ Add new' }),
        ).toBeVisible();
      });

      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('can add and edit date values via popover', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATE);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(3);
        await openPopoverForRow(page, field, 0);
        await typeInPopover(page, 'input[type="date"]', '2026-04-27');
        await verifyRowText(field, 0, formatDateForDisplay('2026-04-27'));
        await openPopoverForRow(page, field, 1);
        await typeInPopover(page, 'input[type="date"]', '2026-04-28');
        await verifyRowText(field, 1, formatDateForDisplay('2026-04-28'));
        await openPopoverForRow(page, field, 2);
        await typeInPopover(page, 'input[type="date"]', '2025-12-25');
        await verifyRowText(field, 2, formatDateForDisplay('2025-12-25'));

        const previewFrame = await canvas.getActivePreviewFrame();
        const listItems = previewFrame.locator('#date-list li');
        await expect(listItems.nth(0)).toContainText('2026-04-27');
        await expect(listItems.nth(1)).toContainText('2026-04-28');
        await expect(listItems.nth(2)).toContainText('2025-12-25');
        expect(await getAllRowTexts(field)).toEqual([
          formatDateForDisplay('2026-04-27'),
          formatDateForDisplay('2026-04-28'),
          formatDateForDisplay('2025-12-25'),
        ]);
      });

      test('can remove items using the popover Remove button', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATE);
        await expect(field.locator('tbody tr')).toHaveCount(2);
        await openPopoverForRow(page, field, 0);
        await typeInPopover(page, 'input[type="date"]', '2026-04-27');
        await verifyRowText(field, 0, formatDateForDisplay('2026-04-27'));
        await openPopoverForRow(page, field, 1);
        await typeInPopover(page, 'input[type="date"]', '2026-04-28');
        await verifyRowText(field, 1, formatDateForDisplay('2026-04-28'));
        await openPopoverForRow(page, field, 0);
        await page
          .locator('[role="dialog"]')
          .getByRole('button', { name: /Remove/i })
          .click();
        await expect(page.locator('[role="dialog"]')).not.toBeVisible();
        await expect(field.locator('tbody tr')).toHaveCount(1);
        expect(await getAllRowTexts(field)).toEqual([
          formatDateForDisplay('2026-04-28'),
        ]);

        // Assert that page is also updated.
        const previewFrame = await canvas.getActivePreviewFrame();
        const listItems = previewFrame.locator('#date-list li');
        await expect(listItems).toHaveCount(1);
        await expect(listItems.nth(0)).toContainText('2026-04-28');
      });

      test('popover opens and closes correctly', async ({ page }) => {
        const field = getField(page, PROP_NAMES.DATE);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('input[type="date"]')).toHaveValue('');
        await expect(dialog.locator('[aria-label="Close"]')).toBeVisible();
        await dialog.locator('[aria-label="Close"]').click();
        await expect(dialog).not.toBeVisible();
      });

      test('popover header shows the correct field label for unlimited date', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATE);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
          'Date (Unlimited)',
        );
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('popover discards uncommitted date changes when closed via × button', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATE);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(3);
        await openPopoverForRow(page, field, 0);
        await typeInPopover(page, 'input[type="date"]', '2026-04-27');
        await verifyRowText(field, 0, formatDateForDisplay('2026-04-27'));
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const input = dialog.locator('input[type="date"]');
        await expect(input).toHaveValue('2026-04-27');

        // Make an uncommitted change by filling the input without pressing Enter.
        const uncommittedDate = '2026-06-15';
        await typeInPopoverWithoutCommit(
          page,
          'input[type="date"]',
          uncommittedDate,
        );
        await expect(input).toHaveValue(uncommittedDate);

        // Close the popover via the × button.
        await closePopoverViaCloseButton(page);

        // Reopen the same row and verify the original value is still there.
        await openPopoverForRow(page, field, 0);
        const dialog2 = page.locator('[role="dialog"]');
        const input2 = dialog2.locator('input[type="date"]');
        await expect(input2).toHaveValue('2026-04-27');
        await closePopoverViaCloseButton(page);
      });
      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('can reorder items using drag and drop', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATE);
        const previewFrame1 = await canvas.getActivePreviewFrame();
        const listItems1 = previewFrame1.locator('#date-list li');
        await openPopoverForRow(page, field, 0);
        await typeInPopover(page, 'input[type="date"]', '2026-04-27');
        await verifyRowText(field, 0, formatDateForDisplay('2026-04-27'));
        await openPopoverForRow(page, field, 1);
        await typeInPopover(page, 'input[type="date"]', '2026-04-28');
        await verifyRowText(field, 1, formatDateForDisplay('2026-04-28'));
        await expect(listItems1.nth(0)).toContainText('2026-04-27');
        await expect(listItems1.nth(1)).toContainText('2026-04-28');
        await dragRow(field, 0, 1);
        // Verify the preview reflects the new order.
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const listItems2 = previewFrame2.locator('#date-list li');
        await expect(listItems2.nth(0)).toContainText('2026-04-28');
        await expect(listItems2.nth(1)).toContainText('2026-04-27');
        expect(await getAllRowTexts(field)).toEqual([
          formatDateForDisplay('2026-04-28'),
          formatDateForDisplay('2026-04-27'),
        ]);

        await page.reload();

        // Assert that the order of dragging is also maintained after page refresh.
        const previewFrame3 = await canvas.getActivePreviewFrame();
        const listItems3 = previewFrame3.locator('#date-list li');
        await expect(listItems3.nth(0)).toContainText('2026-04-28');
        await expect(listItems3.nth(1)).toContainText('2026-04-27');
        expect(await getAllRowTexts(field)).toEqual([
          formatDateForDisplay('2026-04-28'),
          formatDateForDisplay('2026-04-27'),
          '',
        ]);
      });

      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('new value added via "+ Add new" is retained after page refresh', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATE);
        await clickAddNew(field);
        await expect(field.locator('tbody tr')).toHaveCount(3);
        await openPopoverForRow(page, field, 0);
        await typeInPopover(page, 'input[type="date"]', '2026-04-27');
        await verifyRowText(field, 0, formatDateForDisplay('2026-04-27'));
        await openPopoverForRow(page, field, 1);
        await typeInPopover(page, 'input[type="date"]', '2026-04-28');
        await verifyRowText(field, 1, formatDateForDisplay('2026-04-28'));
        await openPopoverForRow(page, field, 2);
        await typeInPopover(page, 'input[type="date"]', '2026-03-01');
        await verifyRowText(field, 2, formatDateForDisplay('2026-03-01'));
        const previewFrame = await canvas.getActivePreviewFrame();
        const listItems = previewFrame.locator('#date-list li');
        await expect(listItems.nth(0)).toContainText('2026-04-27');
        await expect(listItems.nth(1)).toContainText('2026-04-28');
        await expect(listItems.nth(2)).toContainText('2026-03-01');
        expect(await getAllRowTexts(field)).toEqual([
          formatDateForDisplay('2026-04-27'),
          formatDateForDisplay('2026-04-28'),
          formatDateForDisplay('2026-03-01'),
        ]);

        await page.reload();
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const listItems2 = previewFrame2.locator('#date-list li');
        await expect(listItems2.nth(0)).toContainText('2026-04-27');
        await expect(listItems2.nth(1)).toContainText('2026-04-28');
        await expect(listItems2.nth(2)).toContainText('2026-03-01');
        expect(await getAllRowTexts(field)).toEqual([
          formatDateForDisplay('2026-04-27'),
          formatDateForDisplay('2026-04-28'),
          formatDateForDisplay('2026-03-01'),
          '',
        ]);
      });
    });

    test.describe('limited variant', () => {
      test('renders with date field and "+ Add new" button is not visible', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATE_LIMITED);
        await expect(getForm(page)).toBeVisible();
        await expect(field).toBeAttached();
        await expect(
          field.getByRole('button', { name: '+ Add new' }),
        ).not.toBeVisible();

        // Coverage for editing values in limited variant
        await openPopoverForRow(page, field, 0);
        await typeInPopover(page, 'input[type="date"]', '2026-04-27');
        await verifyRowText(field, 0, formatDateForDisplay('2026-04-27'));

        const previewFrame = await canvas.getActivePreviewFrame();
        const listItems = previewFrame.locator('#date-limited-list li');
        await expect(listItems.nth(0)).toContainText('2026-04-27');
        expect(await getAllRowTexts(field)).toContain(
          formatDateForDisplay('2026-04-27'),
        );
      });

      test('popover has disabled Remove button for date limited variant', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATE_LIMITED);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        const removeButton = dialog.getByRole('button', { name: /Remove/i });
        await expect(removeButton).toBeVisible();
        await expect(removeButton).toBeDisabled();
        await expect(removeButton).toHaveAttribute('data-disabled', 'true');
        await dialog.locator('[aria-label="Close"]').click();
      });

      test('popover header shows the correct field label for limited date', async ({
        page,
      }) => {
        const field = getField(page, PROP_NAMES.DATE_LIMITED);
        await openPopoverForRow(page, field, 0);
        const dialog = page.locator('[role="dialog"]');
        await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
          'Date (Limited)',
        );
        await dialog.locator('[aria-label="Close"]').click();
      });

      // @todo: Remove Skip from test once https://www.drupal.org/i/3582883 lands in as
      // this fixes the issue.
      test.skip('can reorder items using drag and drop', async ({
        page,
        canvas,
      }) => {
        const field = getField(page, PROP_NAMES.DATE_LIMITED);
        const previewFrame1 = await canvas.getActivePreviewFrame();
        const listItems1 = previewFrame1.locator('#date-limited-list li');
        await expect(listItems1.nth(0)).toContainText(
          formatDateForDisplay('2025-09-10'),
        );
        await expect(listItems1.nth(1)).toContainText(
          formatDateForDisplay('2025-10-15'),
        );
        await dragRow(field, 0, 1);
        // Verify the preview reflects the new order.
        const previewFrame2 = await canvas.getActivePreviewFrame();
        const listItems2 = previewFrame2.locator('#date-limited-list li');
        await expect(listItems2.nth(0)).toContainText(
          formatDateForDisplay('2025-10-15'),
        );
        await expect(listItems2.nth(1)).toContainText(
          formatDateForDisplay('2025-09-10'),
        );
        expect(await getAllRowTexts(field)).toEqual([
          formatDateForDisplay('2025-10-15'),
          formatDateForDisplay('2025-09-10'),
          '',
        ]);
        await page.reload();

        // Assert that the order of dragging is also maintained after page refresh.
        const previewFrame3 = await canvas.getActivePreviewFrame();
        const listItems3 = previewFrame3.locator('#date-limited-list li');
        await expect(listItems3.nth(0)).toContainText(
          formatDateForDisplay('2025-10-15'),
        );
        await expect(listItems3.nth(1)).toContainText(
          formatDateForDisplay('2025-09-10'),
        );
        expect(await getAllRowTexts(field)).toEqual([
          formatDateForDisplay('2025-10-15'),
          formatDateForDisplay('2025-09-10'),
          '',
        ]);
      });
    });
  });
});

// Separate test suite for Relative Link Component because it requires
// entity_autocomplete setup with content types and nodes.
test.describe('Relative Link Component', () => {
  test.beforeEach(async ({ drupal, canvas, page }) => {
    await drupal.loginAsAdmin();
    await drupal.installModules([
      'canvas_test_sdc',
      'datetime',
      'datetime_range',
      // @todo remove once https://drupal.org/i/3577946 is fixed.
      'canvas_dev_mode',
    ]);
    // This is needed to test the entity_autocomplete functionality.
    await page.goto('/admin/structure/types/add');
    await page.getByRole('textbox', { name: 'name' }).fill('Article');
    await page.getByRole('button', { name: 'Save' }).click();
    await page.goto('/node/add/article');
    await page.getByLabel('Title').fill('Article One');
    await page.getByRole('button', { name: 'Save' }).click();
    await drupal.logout();
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: COMPONENT_ID });
  });

  test.describe('unlimited variant', () => {
    test('renders with relative_link field and "+ Add new" button', async ({
      page,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK);
      await expect(getForm(page)).toBeVisible();
      await expect(field).toBeAttached();
      await expect(
        field.getByRole('button', { name: '+ Add new' }),
      ).toBeVisible();
    });

    test('can add and edit relative link values via popover', async ({
      page,
      canvas,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK);
      await clickAddNew(field);
      await expect(field.locator('tbody tr')).toHaveCount(4);
      await openPopoverForRow(page, field, 2);
      await typeRelativeLinkViaAutocomplete(page, 'article');
      await verifyRowText(field, 2, 'Article One (1)');
      const previewFrame = await canvas.getActivePreviewFrame();
      const relativeLinkListItems = previewFrame.locator(
        '#relative-link-list li',
      );
      await expect(relativeLinkListItems.nth(0)).toContainText('/about');
      await expect(relativeLinkListItems.nth(1)).toContainText('/contact');
      await expect(relativeLinkListItems.nth(2)).toContainText('/node/1');
      expect(await getAllRowTexts(field)).toEqual([
        '/about',
        '/contact',
        'Article One (1)',
        '',
      ]);
    });

    test('can remove items using the popover Remove button', async ({
      page,
      canvas,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK);
      await expect(field.locator('tbody tr')).toHaveCount(3);
      await openPopoverForRow(page, field, 0);
      await page
        .locator('[role="dialog"]')
        .getByRole('button', { name: /Remove/i })
        .click();
      await expect(page.locator('[role="dialog"]')).not.toBeVisible();
      await expect(field.locator('tbody tr')).toHaveCount(2);
      expect(await getAllRowTexts(field)).toEqual(['/contact', '']);

      // Assert that page is also updated.
      const previewFrame = await canvas.getActivePreviewFrame();
      const relativeLinkListItems = previewFrame.locator(
        '#relative-link-list li',
      );
      await expect(relativeLinkListItems).toHaveCount(1);
      await expect(relativeLinkListItems.nth(0)).toContainText('/contact');
      await expect(
        previewFrame.locator('#relative-link-list'),
      ).not.toContainText('/about');
    });

    test('popover opens and closes correctly', async ({ page }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      await expect(dialog.locator('input[type="text"]')).toHaveValue('/about');
      await expect(dialog.locator('[aria-label="Close"]')).toBeVisible();
      await dialog.locator('[aria-label="Close"]').click();
      await expect(dialog).not.toBeVisible();
    });

    test('popover header shows the correct field label for unlimited relative_link', async ({
      page,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
        'Relative Link (Unlimited)',
      );
      await dialog.locator('[aria-label="Close"]').click();
    });

    test('can reorder items using drag and drop', async ({ page, canvas }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK);
      const previewFrame1 = await canvas.getActivePreviewFrame();
      const textListItems1 = previewFrame1.locator('#relative-link-list li');
      await expect(textListItems1.nth(0)).toContainText('/about');
      await expect(textListItems1.nth(1)).toContainText('/contact');
      expect(await getAllRowTexts(field)).toEqual(['/about', '/contact', '']);
      await dragRow(field, 0, 1);
      // Verify the preview reflects the new order.
      const previewFrame2 = await canvas.getActivePreviewFrame();
      const textListItems2 = previewFrame2.locator('#relative-link-list li');
      await expect(textListItems2.nth(0)).toContainText('/contact');
      await expect(textListItems2.nth(1)).toContainText('/about');
      expect(await getAllRowTexts(field)).toEqual(['/contact', '/about', '']);

      await page.reload();

      // Asserting that the values are same on the page instance after refresh.
      const previewFrame3 = await canvas.getActivePreviewFrame();
      const textListItems3 = previewFrame3.locator('#relative-link-list li');
      await expect(textListItems3.nth(0)).toContainText('/contact');
      await expect(textListItems3.nth(1)).toContainText('/about');
      expect(await getAllRowTexts(field)).toEqual(['/contact', '/about', '']);
    });

    test('new value added via "+ Add new" is retained after page refresh', async ({
      page,
      canvas,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK);
      await clickAddNew(field);
      await expect(field.locator('tbody tr')).toHaveCount(4);
      await openPopoverForRow(page, field, 2);
      await typeRelativeLinkViaAutocomplete(page, 'article');
      await verifyRowText(field, 2, 'Article One (1)');
      const previewFrame1 = await canvas.getActivePreviewFrame();
      const textListItems1 = previewFrame1.locator('#relative-link-list li');
      await expect(textListItems1.nth(0)).toContainText('/about');
      await expect(textListItems1.nth(1)).toContainText('/contact');
      await expect(textListItems1.nth(2)).toContainText('/node/1');
      expect(await getAllRowTexts(field)).toEqual([
        '/about',
        '/contact',
        'Article One (1)',
        '',
      ]);
      await page.reload();

      const previewFrame2 = await canvas.getActivePreviewFrame();
      const textListItems2 = previewFrame2.locator('#relative-link-list li');
      await expect(textListItems2.nth(0)).toContainText('/about');
      await expect(textListItems2.nth(1)).toContainText('/contact');
      await expect(textListItems2.nth(2)).toContainText('/node/1');
      expect(await getAllRowTexts(field)).toEqual([
        '/about',
        '/contact',
        'Article One (1)',
        '',
      ]);
    });

    test('popover discards uncommitted relative_link changes when closed via × button', async ({
      page,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      const input = dialog.locator('input[type="text"]');
      await expect(input).toHaveValue('/about');

      // Make an uncommitted change by filling the input without pressing Enter.
      const uncommittedPath = '/uncommitted';
      await typeInPopoverWithoutCommit(
        page,
        'input[type="text"]',
        uncommittedPath,
      );
      await expect(input).toHaveValue(uncommittedPath);

      // Close the popover via the × button
      await closePopoverViaCloseButton(page);

      // Reopen the same row and verify the original value is still there.
      await openPopoverForRow(page, field, 0);
      const dialog2 = page.locator('[role="dialog"]');
      const input2 = dialog2.locator('input[type="text"]');
      await expect(input2).toHaveValue('/about');
      await closePopoverViaCloseButton(page);
    });
  });

  test.describe('limited variant', () => {
    test('renders with relative_link field and "+ Add new" button is not visible', async ({
      page,
      canvas,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK_LIMITED);
      await expect(getForm(page)).toBeVisible();
      await expect(field).toBeAttached();
      await expect(
        field.getByRole('button', { name: '+ Add new' }),
      ).not.toBeVisible();

      // Coverage for editing values in limited variant
      await openPopoverForRow(page, field, 0);
      await typeRelativeLinkViaAutocomplete(page, 'article');
      await verifyRowText(field, 0, 'Article One (1)');

      const previewFrame = await canvas.getActivePreviewFrame();
      const relativeLinkListItems = previewFrame.locator(
        '#relative-link-limited-list li',
      );
      await expect(relativeLinkListItems.nth(0)).toContainText('/node/1');
      await expect(relativeLinkListItems.nth(1)).toContainText('/contact');
      expect(await getAllRowTexts(field)).toEqual([
        'Article One (1)',
        '/contact',
        '',
      ]);
    });

    test('popover has disabled Remove button for relative_link limited variant', async ({
      page,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK_LIMITED);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      const removeButton = dialog.getByRole('button', { name: /Remove/i });
      await expect(removeButton).toBeVisible();
      await expect(removeButton).toBeDisabled();
      await expect(removeButton).toHaveAttribute('data-disabled', 'true');
      await dialog.locator('[aria-label="Close"]').click();
    });

    test('popover header shows the correct field label for limited relative_link', async ({
      page,
    }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK_LIMITED);
      await openPopoverForRow(page, field, 0);
      const dialog = page.locator('[role="dialog"]');
      await expect(dialog.locator('[class*="_popoverLabel_"]')).toHaveText(
        'Relative Link (Limited)',
      );
      await dialog.locator('[aria-label="Close"]').click();
    });

    test('can reorder items using drag and drop', async ({ page, canvas }) => {
      const field = getField(page, PROP_NAMES.RELATIVE_LINK_LIMITED);
      const previewFrame1 = await canvas.getActivePreviewFrame();
      const relativeLinkListItems1 = previewFrame1.locator(
        '#relative-link-limited-list li',
      );
      await expect(relativeLinkListItems1.nth(0)).toContainText('/about');
      await expect(relativeLinkListItems1.nth(1)).toContainText('/contact');
      await dragRow(field, 0, 1);
      // Verify the preview reflects the new order.
      const previewFrame2 = await canvas.getActivePreviewFrame();
      const relativeLinkListItems2 = previewFrame2.locator(
        '#relative-link-limited-list li',
      );
      await expect(relativeLinkListItems2.nth(0)).toContainText('/contact');
      await expect(relativeLinkListItems2.nth(1)).toContainText('/about');
      expect(await getAllRowTexts(field)).toEqual(['/contact', '/about', '']);
      await page.reload();

      // Assert that the order of dragging is also maintained after page refresh.
      const previewFrame3 = await canvas.getActivePreviewFrame();
      const relativeLinkListItems3 = previewFrame3.locator(
        '#relative-link-limited-list li',
      );
      await expect(relativeLinkListItems3.nth(0)).toContainText('/contact');
      await expect(relativeLinkListItems3.nth(1)).toContainText('/about');
      expect(await getAllRowTexts(field)).toEqual(['/contact', '/about', '']);
    });
  });
});
