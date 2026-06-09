import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests Canvas translation behavior.
 */

test.use({
  modules: ['canvas_test_sdc', 'language', 'content_translation'],
});

test.describe('Canvas page language enforcement', () => {
  test.beforeEach(async ({ drupal, page }) => {
    await drupal.loginAsAdmin();

    // Add French so the content language settings form shows the language
    // configuration section for the Canvas page bundle.
    await page.goto('/admin/config/regional/language/add');
    await page
      .locator('[data-drupal-selector="edit-predefined-langcode"]')
      .selectOption('fr');
    await page
      .locator('[data-drupal-selector="edit-predefined-submit"]')
      .click();
    await page.waitForURL('**/admin/config/regional/language', {
      timeout: 10000,
    });
  });

  test('"Show language selector..." checkbox is disabled and unchecked when translation is enabled for Canvas pages', async ({
    page,
  }) => {
    await page.goto('/admin/config/regional/content-language');

    const translatableCheckbox = page.locator(
      'input[name="entity_types[canvas_page]"]',
    );
    await expect(translatableCheckbox).toBeVisible();
    await translatableCheckbox.check();

    const languageAlterableCheckbox = page.locator(
      'input[name="settings[canvas_page][canvas_page][settings][language][language_alterable]"]',
    );
    await expect(languageAlterableCheckbox).toBeAttached();
    await expect(languageAlterableCheckbox).toBeDisabled();
    await expect(languageAlterableCheckbox).not.toBeChecked();

    await page.locator('[data-drupal-selector="edit-submit"]').click();
    await page.waitForURL('**/admin/config/regional/content-language', {
      timeout: 10000,
    });

    await expect(languageAlterableCheckbox).toBeAttached();
    await expect(languageAlterableCheckbox).toBeDisabled();
    await expect(languageAlterableCheckbox).not.toBeChecked();
  });

  test('No language selector is shown in the Canvas page sidebar form', async ({
    page,
    canvas,
  }) => {
    await page.goto('/admin/config/regional/content-language');
    await page.locator('input[name="entity_types[canvas_page]"]').check();
    await page.locator('[data-drupal-selector="edit-submit"]').click();
    await page.waitForURL('**/admin/config/regional/content-language', {
      timeout: 10000,
    });

    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    const pageDataPanel = page.getByTestId(
      'canvas-contextual-panel--page-data',
    );
    await expect(pageDataPanel).toBeVisible();

    await expect(
      pageDataPanel.locator('[data-drupal-selector="edit-langcode-0-value"]'),
    ).not.toBeAttached();
  });
});
