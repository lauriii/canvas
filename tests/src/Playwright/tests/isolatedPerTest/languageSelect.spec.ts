import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore région languageswitcher
/**
 * Tests language switching functionality and URL query parameters.
 */

test.use({
  modules: ['canvas_test_sdc', 'canvas_test_recipe'],
  enableTestExtensions: true,
});

// Temporary workaround for Drupal.logout() failing when the default content
// lacks a 'h1' tag.
// @todo remove after https://git.drupalcode.org/project/playwright/-/work_items/3581273
const logout = async (drupal) => {
  await drupal.page.goto('/user/logout/confirm');
  await drupal.page
    .locator(
      'form[data-drupal-selector="user-logout-confirm"] [data-drupal-selector="edit-submit"]',
    )
    .click();
  await expect(
    drupal.page.locator(
      'form[data-drupal-selector="user-logout-confirm"] [data-drupal-selector="edit-submit"]',
    ),
  ).not.toBeAttached();
  let cookies = await drupal.page.context().cookies();
  cookies = cookies.filter(
    (cookie) =>
      cookie.name.startsWith('SESS') || cookie.name.startsWith('SSESS'),
  );
  expect(cookies).toHaveLength(0);
  const userId = await drupal.getUserId();
  expect(userId).toBe(0);
};

// Temporary workaround for Drupal.logout() failing if the page appearing
// after login does not have the current username.
// @todo remove after https://git.drupalcode.org/project/playwright/-/work_items/3581273
const login = async ({ username, password, drupal }) => {
  await drupal.page.goto('/user/login');
  await drupal.page
    .locator(
      'form[data-drupal-selector="user-login-form"] [data-drupal-selector="edit-name"]',
    )
    .fill(username);
  await drupal.page
    .locator(
      'form[data-drupal-selector="user-login-form"] [data-drupal-selector="edit-pass"]',
    )
    .fill(password);
  await drupal.page
    .locator(
      'form[data-drupal-selector="user-login-form"] [data-drupal-selector="edit-submit"]',
    )
    .click();
  await expect(
    drupal.page.locator(
      'form[data-drupal-selector="user-login-form"] [data-drupal-selector="edit-submit"]',
    ),
  ).not.toBeAttached();
  const isLoggedIn = await drupal.isLoggedIn();
  expect(isLoggedIn).toBe(true);
  const userId = await drupal.getUserId();
  expect(userId).toBeGreaterThan(1);
};

test.describe('Language Select', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/test_translation`,
    );
    await logout(drupal);
  });

  test('Selecting a non-default language navigates to preview and switching back to default returns to editor', async ({
    page,
    canvas,
    drupal,
  }) => {
    await login({ username: 'editor', password: 'editor', drupal });
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
    drupal,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.addPermissions({
      role: 'editor',
      permissions: ['administer languages'],
    });
    await logout(drupal);
    await login({ username: 'editor', password: 'editor', drupal });
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

    // The editor does NOT have 'delete content translations', so the backend
    // omits the delete-form link entirely. Without any links for French, the
    // dots (⋮) options trigger is not rendered at all.
    await expect(
      page.locator('[data-testid="language-options-popover-trigger"]'),
    ).toHaveCount(0);
    await expect(
      page.locator('[aria-label="More options for French"]'),
    ).not.toBeAttached();
    await expect(
      page.locator('[data-testid="language-options-delete"]'),
    ).not.toBeAttached();
    // The current permissions include 'administer languages' so the configure
    // button should be present.
    await expect(
      page.locator('[data-testid="language-configure-button"]'),
    ).toBeAttached();

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

  test('A language switcher code component renders working translation links on the live site', async ({
    page,
    canvas,
    drupal,
  }) => {
    await login({ username: 'editor', password: 'editor', drupal });
    await page.goto('/canvas');

    // Open the pre-created translated page in the editor.
    await canvas.openContentNavigation();
    const translationPageLink = page
      .locator('[data-testid="canvas-navigation-results"]')
      .locator('text=Canvas Translation Test Page');
    await expect(translationPageLink).toBeVisible();
    await translationPageLink.click();
    await canvas.waitForEditorUi();

    // Create the language switcher code component documented in
    // docs/user/src/content/docs/code-components/data-fetching.mdx, publish
    // it, and place it on the page.
    const code = await readFile(
      'tests/fixtures/code_components/page-elements/LanguageSwitcher.jsx',
      'utf-8',
    );
    // The code editor preview has no main entity, so the switcher renders
    // nothing there; wait for the debounced auto-save request instead before
    // publishing to avoid a save conflict.
    const autoSaved = page.waitForResponse(
      (response) =>
        response
          .url()
          .includes('/canvas/api/v0/config/auto-save/js_component/') &&
        response.request().method() === 'PATCH',
    );
    await canvas.createCodeComponent('LanguageSwitcher', code);
    await autoSaved;
    await canvas.publishAllChanges(['LanguageSwitcher']);
    await canvas.saveCodeComponent('js.languageswitcher');
    await canvas.addComponent(
      { id: 'js.languageswitcher' },
      { hasInputs: false },
    );
    await canvas.publishAllChanges(['Canvas Translation Test Page']);

    // On the default-language URL, English is marked current, translated
    // French and untranslated Spanish are links carrying the language prefix,
    // and no fallback message is shown.
    await page.goto('/canvas-translation-test');
    const switcher = page.locator('canvas-island nav');
    await expect(switcher.locator('[aria-current="true"]')).toHaveText(
      'English',
    );
    const frenchLink = switcher.locator('a[hreflang="fr"]');
    await expect(frenchLink).toHaveText('Français');
    await expect(frenchLink).toHaveAttribute(
      'href',
      /\/fr\/canvas-translation-test$/,
    );
    const spanishLink = switcher.locator('a[hreflang="es"]');
    await expect(spanishLink).toHaveText('Español (not translated)');
    // Path aliases are per language and no Spanish one exists, so the Spanish
    // URL is the language-prefixed system path.
    await expect(spanishLink).toHaveAttribute('href', /\/es\/page\/\d+$/);
    await expect(
      switcher.getByText('Not available in your language'),
    ).toBeHidden();

    // The untranslated Spanish URL renders the default translation as a
    // fallback, so the switcher (part of the default tree) is still present:
    // it marks Spanish current and reports the fallback via requestedLanguage
    // vs renderedLanguage.
    await spanishLink.click();
    await page.waitForURL(/\/es\/page\/\d+$/);
    await expect(
      page.getByRole('heading', { name: 'Hello, Canvas!' }),
    ).toBeVisible();
    await expect(switcher.locator('[aria-current="true"]')).toHaveText(
      'Español',
    );
    await expect(
      switcher.getByText('Not available in your language; showing en.'),
    ).toBeVisible();

    // Following the French link from the fallback page serves the French
    // translation. Without a translation_sync setting each translation keeps
    // its own component tree, so the pre-existing French tree does not
    // contain the switcher; the translated content proves the link resolved
    // to the right translation.
    await frenchLink.click();
    await page.waitForURL('**/fr/canvas-translation-test');
    await expect(
      page.getByRole('heading', { name: 'Bonjour, Canvas!' }),
    ).toBeVisible();
  });

  test('Language context popover deletes an existing translation in-app and shows no actions for missing translations', async ({
    page,
    canvas,
    drupal,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.addPermissions({
      role: 'editor',
      permissions: [
        // The delete-translation route gates on update access
        // (canvas_page.update), so editing access is what enables the link.
        // @see canvas.api.content.translation.delete in canvas.routing.yml
        'edit canvas page',
        'translate canvas page',
        'delete canvas page',
        'delete content translations',
      ],
    });
    await logout(drupal);
    await login({ username: 'editor', password: 'editor', drupal });
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

    const languageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"][data-state="closed"]',
    );

    // Opens a language's options popover from its dots trigger.
    const openPopover = async (language = 'French') => {
      const popoverTrigger = page
        .locator('[data-state="open"][role="menu"]')
        .locator(`[aria-label="More options for ${language}"]`)
        .first();
      await expect(popoverTrigger).toBeAttached();
      await popoverTrigger.click();
    };
    await languageButton.click();

    // Confirm a user without the 'administer languages' permission will
    // not see the language configure button.
    await expect(
      page.locator('[data-testid="language-configure-button"]'),
    ).not.toBeAttached();

    await openPopover();

    // French has a translation: the dots button is present and the popover
    // shows only "Delete translation".
    const frenchPopover = page
      .locator('[data-testid="language-options-popover"]')
      .first();
    await expect(frenchPopover).toBeAttached();
    await expect(
      page.locator('[data-testid="language-options-popover-title"]').first(),
    ).toContainText('French');
    await expect(
      page.locator('[data-testid="language-options-delete"]').first(),
    ).toBeAttached();

    // Deleting the French translation happens in-app: no new browser tab
    // opens, and the dropdown updates without a page reload.
    let popupOpened = false;
    page.on('popup', () => {
      popupOpened = true;
    });
    await frenchPopover
      .locator('[data-testid="language-options-delete"]')
      .click();
    expect(popupOpened).toBe(false);

    // Clicking delete opens a confirmation dialog instead of deleting immediately.
    const deleteDialog = page
      .locator('[role="dialog"][data-state="open"]')
      .filter({
        has: page.locator('h1', { hasText: 'Delete translation' }),
      })
      .first();
    await expect(deleteDialog).toBeVisible();

    const cancelButton = deleteDialog.getByRole('button', { name: 'Cancel' });
    await expect(cancelButton).toBeAttached();
    // Clicking Cancel closes the dialog without performing the deletion.
    await cancelButton.click();
    await expect(deleteDialog).toBeHidden();

    // The dropdown must still be open and the French translation still intact.
    await expect(
      page.locator('[data-state="open"][role="menu"]'),
    ).toBeAttached();
    await expect(
      page.locator('[aria-label="More options for French"]'),
    ).toBeVisible();
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toBeVisible();

    // Proceed with actual deletion.
    await openPopover();
    await frenchPopover
      .locator('[data-testid="language-options-delete"]')
      .click();
    await expect(deleteDialog).toBeVisible();

    // The "Delete Translation" button must be disabled until the user types
    // the exact confirmation word.
    const confirmButton = deleteDialog.getByRole('button', {
      name: 'Delete Translation',
    });
    await expect(confirmButton).toBeDisabled();

    // Typing the confirmation word in lowercase must NOT enable the button
    // (the match is case-sensitive).
    await page
      .locator('[data-testid="delete-translation-confirm-input"]')
      .fill('delete');
    await expect(confirmButton).toBeDisabled();

    // Typing the exact required word (uppercase) enables the confirm button.
    await page
      .locator('[data-testid="delete-translation-confirm-input"]')
      .fill('DELETE');
    await expect(confirmButton).toBeEnabled();

    // Confirming the dialog triggers the actual in-app deletion.
    await confirmButton.click();
    await expect(deleteDialog).toBeHidden();

    // The in-app delete drops French's options trigger (and with it the
    // popover) once the request resolves. Wait for that before reopening so
    // the dropdown - not a still-open popover - receives the Escape key.
    await expect(
      page.locator('[aria-label="More options for French"]'),
    ).toHaveCount(0);

    // Reopen the dropdown: French is still listed but, with its translation
    // gone, it no longer shows a check mark or an options trigger - confirming
    // the list refreshed without a page reload.
    await expect(async () => {
      // Click an element outside the language select to ensure it fully closes,
      // regardless of submenus.
      await page.locator('[data-testid="scale-to-fit"]').click();
      await expect(
        page.locator(
          '[data-state="open"][data-testid="language-select-trigger"]',
        ),
      ).not.toBeAttached();
    }).toPass({ timeout: 10000 });
    await languageButton.click();
    await expect(
      page.locator('[data-testid="language-option-fr"]'),
    ).toBeVisible();
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);
    await expect(
      page.locator('[aria-label="More options for French"]'),
    ).toHaveCount(0);

    // Spanish never had a translation: the dots button is not rendered at all.
    await expect(
      page.locator('[aria-label="More options for Spanish"]'),
    ).toHaveCount(0);
  });
});
