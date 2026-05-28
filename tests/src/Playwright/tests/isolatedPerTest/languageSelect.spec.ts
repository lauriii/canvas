import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore région
/**
 * Tests language switching functionality and URL query parameters.
 */

test.use({
  modules: ['canvas_test_sdc', 'canvas_test_recipe'],
  enableTestExtensions: true,
});

test.describe('Language Select', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/test_translation`,
    );
  });

  test('Selecting a non-default language navigates to preview and switching back to default returns to editor', async ({
    page,
    canvas,
  }) => {
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    let languageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"]',
    );
    await expect(languageButton).toBeVisible();

    await languageButton.click();

    // Verify all available language options are shown.
    const languageOptions = page.locator('[data-testid^="language-option-"]');
    await expect(languageOptions).toHaveCount(3);

    const frenchOption = page.locator('[data-testid="language-option-fr"]');
    await expect(frenchOption).toBeVisible();
    await frenchOption.click();

    await page.waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=fr/, {
      timeout: 10000,
    });

    // Verify the URL contains the French language query parameter.
    expect(page.url()).toMatch(
      /\/preview\/canvas_page\/\d+\/full\?language=fr/,
    );
    const previewFrame = page.frameLocator('iframe[title="Page preview"]');
    // Preview text appearing in French confirms UI is in a state to proceed.
    await expect(previewFrame.locator('text=Bonjour de la')).toBeVisible({
      timeout: 5000,
    });

    languageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"]',
    );
    await expect(languageButton).toBeVisible();
    await languageButton.click();

    const defaultLanguageItem = page.locator(
      '[data-testid="language-option-en"]',
    );
    await expect(defaultLanguageItem).toBeVisible();
    await defaultLanguageItem.click();

    await page.waitForURL(/\/editor\/canvas_page\/\d+/, { timeout: 10000 });

    // Verify we're back in the editor view.
    expect(page.url()).not.toContain('?language=');
  });

  test('Preview renders translated content and falls back to default when no translation exists', async ({
    page,
    canvas,
  }) => {
    await page.goto('/canvas');

    // Navigate to the pre-created translation test page via the content navigation.
    await canvas.openContentNavigation();

    const navigationResults = page.locator(
      '[data-testid="canvas-navigation-results"]',
    );
    const translationPageLink = navigationResults.locator(
      'text=Canvas Translation Test Page',
    );
    await expect(translationPageLink).toBeVisible();
    await translationPageLink.click();

    await canvas.waitForEditorUi();

    let languageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"]',
    );
    await expect(languageButton).toBeVisible();
    await languageButton.click();

    // Verify translation indicators are present for translated languages.
    await expect(
      page.locator(
        '[data-testid="language-option-en"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveAttribute('data-canvas-has-translation', 'true');
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveAttribute('data-canvas-has-translation', 'true');
    await expect(
      page.locator(
        '[data-testid="language-option-es"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);

    const frenchOption = page.locator('[data-testid="language-option-fr"]');
    await expect(frenchOption).toBeVisible();
    await frenchOption.click();

    await page.waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=fr/, {
      timeout: 10000,
    });

    expect(page.url()).toMatch(
      /\/preview\/canvas_page\/\d+\/full\?language=fr/,
    );

    let previewFrame = page.frameLocator('iframe[title="Page preview"]');
    await expect(previewFrame.locator('body')).not.toBeEmpty();

    // Verify French page content "Bonjour, Canvas!" is displayed.
    await expect(previewFrame.locator('text=Bonjour, Canvas!')).toBeVisible({
      timeout: 5000,
    });

    // Verify French page region content "Bonjour de la région" is displayed.
    await expect(previewFrame.locator('text=Bonjour de la région')).toBeVisible(
      {
        timeout: 5000,
      },
    );

    // Verify English content is not displayed.
    await expect(previewFrame.locator('text=Hello, Canvas!')).toBeHidden();
    await expect(previewFrame.locator('text=Hello from region')).toBeHidden();

    // Verify page region is in French.
    await expect(previewFrame.locator('html')).toHaveAttribute('lang', /^fr/i);

    // Switch to Spanish language (which has no translation).
    languageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"]',
    );
    await expect(languageButton).toBeVisible();
    await languageButton.click();

    const spanishOption = page.locator('[data-testid="language-option-es"]');
    await expect(spanishOption).toBeVisible();
    await spanishOption.click();

    await page.waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=es/, {
      timeout: 10000,
    });

    expect(page.url()).toMatch(
      /\/preview\/canvas_page\/\d+\/full\?language=es/,
    );

    previewFrame = page.frameLocator('iframe[title="Page preview"]');
    await expect(previewFrame.locator('body')).not.toBeEmpty();

    // Verify English page content "Hello, Canvas!" is displayed (fallback).
    await expect(previewFrame.locator('text=Hello, Canvas!')).toBeVisible({
      timeout: 5000,
    });

    // Verify English page region content "Hello from region" is displayed (fallback).
    await expect(previewFrame.locator('text=Hello from region')).toBeVisible({
      timeout: 5000,
    });

    // Verify French content is not displayed.
    await expect(previewFrame.locator('text=Bonjour, Canvas!')).toBeHidden();
    await expect(
      previewFrame.locator('text=Bonjour de la région'),
    ).toBeHidden();

    // Verify page region is in Spanish.
    await expect(previewFrame.locator('html')).toHaveAttribute('lang', /^es/i);
  });
});
