import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests that the canvasFormatDate and canvasFormatDateTime Twig filters
 * produce locale-formatted output rather than the raw ISO strings that Canvas
 * passes to templates.
 */

test.use({
  modules: ['canvas_test_date_formatting'],
  enableTestExtensions: true,
});

test.describe('Date formatting Twig filters', () => {
  test('date and date-time props are rendered through locale formatting filters', async ({
    drupal,
    canvas,
    page,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();

    await canvas.addComponent({
      id: 'sdc.canvas_test_date_formatting.date-formatted',
    });

    // Set a known date value.
    const dateInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date input[type="date"]',
    );
    await expect(dateInput).toBeVisible();
    await dateInput.fill('2026-01-15');
    await dateInput.press('Tab');

    // Wait for the date prop's save round-trip to complete before filling
    // the next date/date-time field.
    await expect(page.locator('body')).not.toHaveAttribute(
      'data-canvas-layout-request-in-progress',
    );

    // Set a known datetime value.
    const dateTimeInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date-time input[type="date"]',
    );
    const timeInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date-time input[type="time"]',
    );
    await expect(dateTimeInput).toBeVisible();
    await expect(timeInput).toBeVisible();
    await dateTimeInput.fill('2026-01-15');
    await timeInput.fill('14:30:00');
    await timeInput.press('Tab');

    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    // Verify raw ISO date value is rendered correctly.
    await canvas.testInPreviewFrame('#date-raw', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toBe('2026-01-15');
    });

    // The date filter must produce the en-US short date for Jan 15 2026.
    await canvas.testInPreviewFrame('#date-formatted-date', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toBe('1/15/26');
    });

    // Verify raw ISO datetime value is rendered correctly.
    // Accept both formats: with and without milliseconds.
    await canvas.testInPreviewFrame('#date-time-raw', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toMatch(/^2026-01-15T14:30:00(?:\.\d{3})?Z$/);
    });

    // The datetime filter must produce the en-US short datetime for Jan 15
    // 2026 at 14:30 UTC. U+202F before AM/PM is normalized to a regular space.
    await canvas.testInPreviewFrame('#date-formatted-date-time', async (el) => {
      const text =
        (await el.textContent())?.trim().replaceAll('\u202f', ' ') ?? '';
      expect(text).toBe('1/15/26, 2:30 PM');
    });

    // Reload the whole editor and verify all values persisted.
    await page.reload();
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    await canvas.testInPreviewFrame('#date-raw', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toBe('2026-01-15');
    });

    await canvas.testInPreviewFrame('#date-formatted-date', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toBe('1/15/26');
    });

    await canvas.testInPreviewFrame('#date-time-raw', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toMatch(/^2026-01-15T14:30:00(?:\.\d{3})?Z$/);
    });

    await canvas.testInPreviewFrame('#date-formatted-date-time', async (el) => {
      const text =
        (await el.textContent())?.trim().replaceAll('\u202f', ' ') ?? '';
      expect(text).toBe('1/15/26, 2:30 PM');
    });
  });
});
