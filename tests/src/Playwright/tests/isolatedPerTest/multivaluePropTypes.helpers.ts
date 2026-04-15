import { expect } from '@playwright/test';

import type { Locator, Page } from '@playwright/test';

export const COMPONENT_FORM_SELECTOR =
  '[data-testid="canvas-contextual-panel"] form[data-form-id="component_instance_form"]';

export const COMPONENT_ID = 'sdc.canvas_test_sdc.multivalue-props';

/**
 * Prop field names used by getFieldWrapper().
 * Unlimited variants keep their original short names; limited variants have a
 * "_limited" suffix (rendered as "field--name-*-limited" in Drupal CSS classes).
 */
export const PROP_NAMES = {
  TEXT: 'text',
  TEXT_LIMITED: 'text-limited',
  TEXT_REQUIRED: 'text-required',
  LINK: 'link',
  LINK_LIMITED: 'link-limited',
  NUMBER: 'number',
  NUMBER_LIMITED: 'number-limited',
  INTEGER: 'integer',
  INTEGER_LIMITED: 'integer-limited',
  RELATIVE_LINK: 'relative-link',
  RELATIVE_LINK_LIMITED: 'relative-link-limited',
  DATETIME: 'datetime',
  DATETIME_LIMITED: 'datetime-limited',
  DATE: 'date',
  DATE_LIMITED: 'date-limited',
} as const;

export function getForm(page: Page): Locator {
  return page.locator(COMPONENT_FORM_SELECTOR);
}

/**
 * Returns the field wrapper for a given prop name.
 *
 * Drupal converts field machine names to CSS classes using dashes, e.g.
 * the prop `relative_link` becomes `.field--name-relative-link`.
 */
export function getFieldWrapper(form: Locator, propName: string): Locator {
  return form.locator(`.field--name-${propName}`);
}

export function getField(page: Page, propName: string): Locator {
  return getFieldWrapper(getForm(page), propName);
}

/** Clicks the "+ Add new" button inside a field wrapper to append a blank row. */
export async function clickAddNew(fieldWrapper: Locator): Promise<void> {
  await fieldWrapper.getByRole('button', { name: '+ Add new' }).click();
}

/**
 * Clicks the list item of a specific row to open the editing popover.
 * Waits until the popover dialog is visible before returning.
 */
export async function openPopoverForRow(
  page: Page,
  fieldWrapper: Locator,
  rowIndex: number,
): Promise<void> {
  await fieldWrapper
    .locator('tbody tr')
    .nth(rowIndex)
    .locator('[class*="_listItem_"]')
    .click();
  await expect(page.locator('[role="dialog"]')).toBeVisible();
}

/**
 * Generic helper to fill an input in the currently open popover, commit the value
 * with Enter, and wait for the popover to close.
 * @param page - The Playwright Page object
 * @param inputSelector - CSS selector for the input element (e.g., '.form-text', 'input[type="number"]')
 * @param value - The value to fill into the input
 */
export async function typeInPopover(
  page: Page,
  inputSelector: string,
  value: string,
): Promise<void> {
  const dialog = page.locator('[role="dialog"]');
  const input = dialog.locator(inputSelector);
  await input.fill(value);
  await input.press('Enter');
  await expect(page.locator('[role="dialog"]')).not.toBeVisible();
}

/**
 * Fills an input in the currently open popover WITHOUT committing the value
 * (does not press Enter). This allows testing that uncommitted changes are
 * discarded when the popover is closed via the × button.
 * @param page - The Playwright Page object
 * @param inputSelector - CSS selector for the input element
 * @param value - The value to fill into the input
 */
export async function typeInPopoverWithoutCommit(
  page: Page,
  inputSelector: string,
  value: string,
): Promise<void> {
  const dialog = page.locator('[role="dialog"]');
  const input = dialog.locator(inputSelector);
  await input.fill(value);
}

/**
 * Closes the currently open popover by clicking the × (Close) button.
 * Verifies the dialog closes successfully.
 * @param page - The Playwright Page object
 */
export async function closePopoverViaCloseButton(page: Page): Promise<void> {
  const dialog = page.locator('[role="dialog"]');
  await dialog.locator('[aria-label="Close"]').click();
  await expect(dialog).not.toBeVisible();
}

/**
 * Types a node title into the currently open relative-link popover, waits for
 * the jQuery UI entity-autocomplete dropdown to appear, clicks the matching
 * suggestion, and waits for the popover to close.
 */
export async function typeRelativeLinkViaAutocomplete(
  page: Page,
  nodeTitle: string,
): Promise<void> {
  const dialog = page.locator('[role="dialog"]');
  const input = dialog.locator('input[type="text"]').first();
  await input.fill('');
  await input.pressSequentially(nodeTitle);
  const suggestion = page
    .locator('.ui-menu-item-wrapper')
    .filter({ hasText: nodeTitle })
    .first();
  await expect(suggestion).toBeVisible({ timeout: 10000 });
  await suggestion.click();
  await input.press('Enter');

  await expect(page.locator('[role="dialog"]')).not.toBeVisible();
}

/**
 * Asserts the visible (collapsed) text shown for a specific row.
 * An empty value is rendered as the string "Empty" by the widget.
 */
export async function verifyRowText(
  fieldWrapper: Locator,
  rowIndex: number,
  expectedText: string,
): Promise<void> {
  await expect(
    fieldWrapper
      .locator('tbody tr')
      .nth(rowIndex)
      .locator('[class*="_itemText_"]'),
  ).toHaveText(expectedText);
}

/**
 * Reads every row's collapsed text and returns them as an array.
 * The widget renders an empty value as "Empty"; this helper maps that back to ''.
 */
export async function getAllRowTexts(fieldWrapper: Locator): Promise<string[]> {
  const rows = await fieldWrapper.locator('tbody tr').all();
  const texts: string[] = [];
  for (const row of rows) {
    const itemText = row.locator('[class*="_itemText_"]');
    const text = await itemText.textContent();
    texts.push(text === 'Empty' ? '' : (text ?? ''));
  }
  return texts;
}

/**
 * Drags the tabledrag handle of a row at `sourceIndex` onto the row at
 * `targetIndex` within the field wrapper's tbody, simulating a reorder via
 * Drupal's native tabledrag ([title="Change order"]).
 */
export async function dragRow(
  fieldWrapper: Locator,
  sourceIndex: number,
  targetIndex: number,
): Promise<void> {
  const rows = fieldWrapper.locator('tbody tr');
  const sourceHandle = rows.nth(sourceIndex).locator('[title="Change order"]');
  const targetRow = rows.nth(targetIndex);
  await sourceHandle.dragTo(targetRow);
}

/**
 * Fills the date and time inputs in the currently open popover (datetime fields),
 * commits the values with Enter on the time input, and waits for the popover to close.
 */
export async function typeDatetimeInPopover(
  page: Page,
  dateValue: string,
  timeValue: string,
): Promise<void> {
  const dialog = page.locator('[role="dialog"]');
  const dateInput = dialog.locator('input[type="date"]');
  const timeInput = dialog.locator('input[type="time"]');
  await dateInput.fill(dateValue);
  await timeInput.fill(timeValue);
  await timeInput.press('Enter');
  await expect(page.locator('[role="dialog"]')).not.toBeVisible();
}

/**
 * Format a date string (YYYY-MM-DD) for display using Intl.DateTimeFormat.
 * This mirrors the logic in DrupalDatetimeMultivalueForm.
 */
export function formatDateForDisplay(value: string): string {
  if (!value) return '';
  try {
    const date = new Date(value + 'T00:00:00');
    return new Intl.DateTimeFormat().format(date);
  } catch {
    return value;
  }
}

/**
 * Format a time string (HH:MM or HH:MM:SS) for display using Intl.DateTimeFormat.
 * This mirrors the logic in DrupalDatetimeMultivalueForm.
 */
export function formatTimeForDisplay(value: string): string {
  if (!value) return '';
  try {
    const date = new Date(`2000-01-01T${value}`);
    const parts = value.split(':');
    const hasNonZeroSeconds =
      parts.length === 3 && parts[2] !== '00' && parts[2] !== '00.000';
    return new Intl.DateTimeFormat(undefined, {
      hour: 'numeric',
      minute: 'numeric',
      second: hasNonZeroSeconds ? 'numeric' : undefined,
      hour12: true,
    }).format(date);
  } catch {
    return value;
  }
}

/**
 * Format a combined date + time display string.
 * This mirrors the logic in DrupalDatetimeMultivalueForm's combinedDisplayValue.
 */
export function formatDatetimeForDisplay(
  dateStr: string,
  timeStr: string,
): string {
  const datePart = formatDateForDisplay(dateStr);
  const timePart = formatTimeForDisplay(timeStr);
  if (datePart && timePart) return `${datePart}, ${timePart}`;
  return datePart || timePart || '';
}
