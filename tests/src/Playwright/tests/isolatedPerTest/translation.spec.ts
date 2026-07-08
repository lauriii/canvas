import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

/**
 * Tests Canvas translation behavior.
 */

test.use({
  modules: ['canvas_test_sdc', 'language', 'content_translation'],
});

test.describe('Canvas page language enforcement', () => {
  async function openCanvasPageSettings(page: Page) {
    const canvasPageSettings = page.locator('#edit-settings-canvas-page');
    await expect(canvasPageSettings).toBeAttached();

    const isOpen = (await canvasPageSettings.getAttribute('open')) !== null;
    if (!isOpen) {
      await canvasPageSettings.locator('summary').click();
    }
  }

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

  test('Canvas page language settings force site default and hide unsupported checkboxes', async ({
    page,
  }) => {
    await page.goto('/admin/config/regional/content-language');

    const entityTypeCheckbox = page.locator(
      'input[name="entity_types[canvas_page]"]',
    );
    await expect(entityTypeCheckbox).toBeVisible();
    await entityTypeCheckbox.check();
    await openCanvasPageSettings(page);

    const bundleTranslatableCheckbox = page.locator(
      'input[name="settings[canvas_page][canvas_page][translatable]"]',
    );
    await expect(bundleTranslatableCheckbox).toBeVisible();
    await bundleTranslatableCheckbox.check();

    const langcodeSelect = page.locator(
      'select[name="settings[canvas_page][canvas_page][settings][language][langcode]"]',
    );
    await expect(langcodeSelect).toBeAttached();
    await expect(langcodeSelect).toBeDisabled();
    await expect(langcodeSelect).toHaveValue('site_default');

    const languageAlterableCheckbox = page.locator(
      'input[name="settings[canvas_page][canvas_page][settings][language][language_alterable]"]',
    );
    await expect(languageAlterableCheckbox).not.toBeAttached();

    const untranslatableFieldsHideCheckbox = page.locator(
      'input[name="settings[canvas_page][canvas_page][settings][content_translation][untranslatable_fields_hide]"]',
    );
    await expect(untranslatableFieldsHideCheckbox).not.toBeAttached();

    await page.locator('[data-drupal-selector="edit-submit"]').click();
    await page.waitForURL('**/admin/config/regional/content-language', {
      timeout: 10000,
    });

    // Reload the page to confirm persisted values are coming from saved config,
    // not merely from post-submit form state.
    await page.goto('/admin/config/regional/content-language');

    await expect(bundleTranslatableCheckbox).toBeChecked();

    await expect(langcodeSelect).toBeAttached();
    await expect(langcodeSelect).toBeDisabled();
    await expect(langcodeSelect).toHaveValue('site_default');
    await expect(languageAlterableCheckbox).not.toBeAttached();
    await expect(untranslatableFieldsHideCheckbox).not.toBeAttached();
  });

  test('No language selector is shown in the Canvas page sidebar form', async ({
    page,
    canvas,
  }) => {
    await page.goto('/admin/config/regional/content-language');
    await page.locator('input[name="entity_types[canvas_page]"]').check();
    await openCanvasPageSettings(page);
    await page
      .locator('input[name="settings[canvas_page][canvas_page][translatable]"]')
      .check();
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
