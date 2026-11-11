import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore région languageswitcher Brouillon
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

// The less common actions of the language bar are in its "More" menu.
const openLanguageBarMenu = async (page) => {
  await page.getByTestId('canvas-language-bar-more').click();
};

test.describe('Language Select', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/test_translation`,
    );
    await logout(drupal);
  });

  test('Selecting another language shows the page in it in the editor, and selecting its own language returns to editing it', async ({
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

    await page.waitForURL(/\/editor\/canvas_page\/\d+\?language=fr/, {
      timeout: 10000,
    });

    // Verify the URL contains the French language query parameter.
    expect(page.url()).toMatch(/\/editor\/canvas_page\/\d+\?language=fr/);
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

    await page.waitForURL(/\/editor\/canvas_page\/\d+$/, { timeout: 10000 });

    // Verify we're back in the editor view.
    await expect(page).not.toHaveURL(/language=/);
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

    // Each language states how the open page relates to it.
    await expect(page.getByTestId('language-status-en')).toHaveText('Original');
    await expect(page.getByTestId('language-status-fr')).toHaveText(
      'Translated',
    );
    await expect(page.getByTestId('language-status-es')).toHaveText(
      'Not translated',
    );
    // The current permissions include 'administer languages' so the configure
    // button should be present.
    await expect(
      page.locator('[data-testid="language-configure-button"]'),
    ).toBeAttached();

    const frenchOption = page.locator('[data-testid="language-option-fr"]');
    await expect(frenchOption).toBeVisible();
    await frenchOption.click();

    await page.waitForURL(/\/editor\/canvas_page\/\d+\?language=fr/, {
      timeout: 10000,
    });

    expect(page.url()).toMatch(/\/editor\/canvas_page\/\d+\?language=fr/);

    let previewFrame = page.frameLocator('iframe[title="Page preview"]');
    await expect(previewFrame.locator('body')).not.toBeEmpty();

    // The page is shown in French: the language bar says why. The editor
    // role has edit (update) access, so deleting the translation is offered.
    const languageBar = page.getByTestId('canvas-language-bar');
    await expect(languageBar).toContainText(
      'French translation. The original is in English.',
    );
    await expect(page.getByTestId('canvas-language-bar-edit')).toHaveText(
      'Switch to English to edit',
    );
    await openLanguageBarMenu(page);
    await expect(page.getByTestId('canvas-language-bar-delete')).toBeVisible();
    await page.keyboard.press('Escape');

    // The page in another language keeps the side menu and the top bar of the
    // editor, without the controls that need an editable page.
    const sideMenu = page.getByTestId('canvas-side-menu');
    await expect(sideMenu.getByRole('button', { name: 'Pages' })).toBeEnabled();
    await expect(
      sideMenu.getByRole('button', { name: 'Library' }),
    ).toBeDisabled();
    await expect(
      sideMenu.getByRole('button', { name: 'Layers' }),
    ).toBeDisabled();

    // The preview is a separate thing: it shows the page the way visitors see
    // it, in the same language, without the bar.
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await page.waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=fr/);
    await expect(languageBar).toHaveCount(0);
    await expect(sideMenu).toHaveCount(0);
    await page.getByRole('button', { name: 'Exit Preview' }).click();
    await page.waitForURL(/\/editor\/canvas_page\/\d+\?language=fr/);
    await expect(languageBar).toContainText('French translation.');

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

    // Simulate a browser refresh on the URL of the French view.
    await page.reload();

    // French content must still render after the reload.
    previewFrame = page.frameLocator('iframe[title="Page preview"]');
    await expect(previewFrame.locator('text=Bonjour, Canvas!')).toBeVisible({
      timeout: 10000,
    });

    // Open the language dropdown and verify translation indicators survived
    // the reload.
    languageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"]',
    );
    await expect(languageButton).toBeVisible();
    await languageButton.click();

    // French has a translation: its status and the language bar must still be
    // present after reload.
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveAttribute('data-canvas-has-translation', 'true');
    await page.keyboard.press('Escape');
    await openLanguageBarMenu(page);
    await expect(page.getByTestId('canvas-language-bar-delete')).toBeVisible();
    await page.keyboard.press('Escape');
    await languageButton.click();

    // Switch to Spanish language (which has no translation).
    const spanishOption = page.locator('[data-testid="language-option-es"]');
    await expect(spanishOption).toBeVisible();
    await spanishOption.click();

    await page.waitForURL(/\/editor\/canvas_page\/\d+\?language=es/, {
      timeout: 10000,
    });

    expect(page.url()).toMatch(/\/editor\/canvas_page\/\d+\?language=es/);

    previewFrame = page.frameLocator('iframe[title="Page preview"]');
    await expect(previewFrame.locator('body')).not.toBeEmpty();

    // There is no Spanish translation: the bar says so, and has none to delete.
    await expect(languageBar).toContainText(
      'Not translated into Spanish. Showing the English original.',
    );
    await expect(page.getByTestId('canvas-language-bar-more')).toHaveCount(0);

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

  test('The language bar deletes an existing translation in-app and offers no delete for missing translations', async ({
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
    await languageButton.click();

    // Confirm a user without the 'administer languages' permission will
    // not see the language configure button.
    await expect(
      page.locator('[data-testid="language-configure-button"]'),
    ).not.toBeAttached();

    // French has a translation: opening it shows the language bar, which
    // offers to delete the translation.
    await page.locator('[data-testid="language-option-fr"]').click();
    await page.waitForURL(/\/editor\/canvas_page\/\d+\?language=fr/, {
      timeout: 10000,
    });
    const deleteButton = page.getByTestId('canvas-language-bar-delete');
    await openLanguageBarMenu(page);
    await expect(deleteButton).toBeVisible();

    // Deleting the French translation happens in-app: no new browser tab
    // opens.
    let popupOpened = false;
    page.on('popup', () => {
      popupOpened = true;
    });
    await deleteButton.click();
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

    // The French translation is still intact.
    await expect(page.getByTestId('canvas-language-bar')).toContainText(
      'French translation.',
    );

    // Proceed with actual deletion.
    await openLanguageBarMenu(page);
    await deleteButton.click();
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

    // Confirming the dialog triggers the actual in-app deletion, and returns
    // to the page in the language it is written in.
    await confirmButton.click();
    await expect(deleteDialog).toBeHidden();
    await page.waitForURL(/\/editor\/canvas_page\/\d+$/, { timeout: 10000 });
    await canvas.waitForEditorUi();

    // French is still listed but, with its translation gone, it is no longer
    // marked as translated.
    await languageButton.click();
    await expect(page.getByTestId('language-status-fr')).toHaveText(
      'Not translated',
    );
    await expect(
      page.locator(
        '[data-testid="language-option-fr"] [data-canvas-has-translation="true"]',
      ),
    ).toHaveCount(0);

    // Spanish never had a translation: there is nothing to delete.
    await page.locator('[data-testid="language-option-es"]').click();
    await page.waitForURL(/\/editor\/canvas_page\/\d+\?language=es/, {
      timeout: 10000,
    });
    await expect(page.getByTestId('canvas-language-bar')).toBeVisible();
    await expect(page.getByTestId('canvas-language-bar-more')).toHaveCount(0);
  });

  test('Unpublishing a page and previewing a translation does not corrupt the default language title', async ({
    page,
    canvas,
    drupal,
  }) => {
    await login({ username: 'editor', password: 'editor', drupal });
    await page.goto('/canvas');

    // Navigate to the pre-created translation test page via the content navigation.
    await canvas.openContentNavigation();
    const translationPageLink = page
      .locator('[data-testid="canvas-navigation-results"]')
      .locator('text=Canvas Translation Test Page');
    await expect(translationPageLink).toBeVisible();
    await translationPageLink.click();
    await canvas.waitForEditorUi();

    // Verify the navigation button shows the English title.
    await expect(
      page.locator('[data-testid="canvas-navigation-button"]'),
    ).toContainText('Canvas Translation Test Page');

    // Unpublish the page from the page listing dropdown.
    await page.getByTestId('canvas-navigation-button').click();
    await page
      .getByTestId('canvas-navigation-content')
      .getByRole('listitem')
      .filter({ hasText: 'Canvas Translation Test Page' })
      .hover();

    const optionsButton = page.getByLabel(
      'Page options for Canvas Translation Test Page',
    );
    await optionsButton.click();
    const unpublishMenuItem = page.getByRole('menuitem', {
      name: 'Unpublish page',
    });
    await expect(unpublishMenuItem).toBeVisible();
    await unpublishMenuItem.click();

    await canvas.closeContentNavigation();

    // Publish the unpublish change so the page is actually unpublished.
    await canvas.publishAllChanges(['Canvas Translation Test Page']);

    // Switch to French via language select.
    const languageButton = page.locator(
      '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"]',
    );
    await expect(languageButton).toBeVisible();
    await languageButton.click();

    const frenchOption = page.locator('[data-testid="language-option-fr"]');
    await expect(frenchOption).toBeVisible();
    await frenchOption.click();

    await page.waitForURL(/\/editor\/canvas_page\/\d+\?language=fr/, {
      timeout: 10000,
    });

    // Verify French content is visible.
    const previewFrame = page.frameLocator('iframe[title="Page preview"]');
    await expect(previewFrame.locator('text=Bonjour, Canvas!')).toBeVisible({
      timeout: 5000,
    });

    // Return to editing by switching back to English (default language).
    await page
      .locator(
        '[data-testid="canvas-topbar"] [data-testid="language-select-trigger"]',
      )
      .click();
    const englishOption = page.locator('[data-testid="language-option-en"]');
    await expect(englishOption).toBeVisible();
    await englishOption.click();

    // Wait for navigation back to editor.
    await page.waitForURL(/\/editor\/canvas_page\/\d+/, { timeout: 10000 });
    await canvas.waitForEditorUi();

    await expect(
      page.locator('[data-testid="canvas-navigation-button"]'),
    ).toContainText('Canvas Translation Test Page');
  });

  test('Changing the language of a never-published draft keeps its content and is not offered after publishing', async ({
    page,
    canvas,
    drupal,
  }) => {
    await login({ username: 'editor', password: 'editor', drupal });
    const canvasPage = await canvas.createCanvas({ title: 'Brouillon' });
    const topbar = page.locator('[data-testid="canvas-topbar"]');
    await expect(topbar.getByText('Draft')).toBeVisible();

    const languageButton = topbar.locator(
      '[data-testid="language-select-trigger"]',
    );
    await expect(languageButton).toContainText('English');
    const changeButton = page.getByTestId('canvas-language-bar-change');

    // Opens the draft in another language, where it cannot be edited, and changes
    // the language of the draft to that language.
    const changeLanguageTo = async (langcode: string, name: string) => {
      await languageButton.click();
      await page.locator(`[data-testid="language-option-${langcode}"]`).click();
      await page.waitForURL(
        new RegExp(
          `/editor/canvas_page/${canvasPage.entity_id}\\?language=${langcode}$`,
        ),
        { timeout: 10000 },
      );
      // A never-published draft has no translation to delete.
      await openLanguageBarMenu(page);
      await expect(page.getByTestId('canvas-language-bar-delete')).toHaveCount(
        0,
      );
      await expect(changeButton).toHaveText(`Change page language to ${name}…`);
      await changeButton.click();
      await page.getByRole('button', { name: 'Change language' }).click();
      // The editor stays on the plain editor URL: the draft now *is* in that
      // language, it is not a translation.
      await page.waitForURL(
        new RegExp(`/editor/canvas_page/${canvasPage.entity_id}$`),
        { timeout: 10000 },
      );
      await canvas.waitForEditorUi();
      await expect(languageButton).toContainText(name);
      await expect(
        page.locator('[data-drupal-selector="edit-title-0-value"]'),
      ).toHaveValue('Brouillon');
    };

    await changeLanguageTo('fr', 'French');
    await expect(topbar.getByText('Draft')).toBeVisible();

    // Content typed before the change survives a reload.
    await page.reload();
    await canvas.waitForEditorUi();
    await expect(languageButton).toContainText('French');
    await expect(
      page.locator('[data-drupal-selector="edit-title-0-value"]'),
    ).toHaveValue('Brouillon');

    // Changing back to the site default language is offered too: the bar
    // gates on the draft's current language, not the site default.
    await changeLanguageTo('en', 'English');

    // And forward again, so the published page below is French.
    await changeLanguageTo('fr', 'French');

    // Once published, the page is no longer a draft: its language is fixed.
    await canvas.publishAllChanges(['Brouillon']);
    await expect(topbar.getByText('Draft')).toHaveCount(0);
    await languageButton.click();
    await page.locator('[data-testid="language-option-en"]').click();
    await page.waitForURL(
      new RegExp(`/editor/canvas_page/${canvasPage.entity_id}\\?language=en$`),
      { timeout: 10000 },
    );
    await expect(page.getByTestId('canvas-language-bar')).toContainText(
      'Not translated into English. Showing the French original.',
    );
    await expect(page.getByTestId('canvas-language-bar-more')).toHaveCount(0);

    // The bar leads back to the editor, in the language of the page.
    await page.getByTestId('canvas-language-bar-edit').click();
    await page.waitForURL(
      new RegExp(`/editor/canvas_page/${canvasPage.entity_id}$`),
      { timeout: 10000 },
    );
    await canvas.waitForEditorUi();
    await expect(languageButton).toContainText('French');

    // New pages are created in the working language.
    await canvas.openContentNavigation();
    await page.getByTestId('canvas-navigation-new-button').click();
    const newPageButton = page.getByTestId('canvas-navigation-new-page-button');
    await newPageButton.click();
    await page.waitForURL(
      (url) =>
        /\/editor\/canvas_page\/\d+$/.test(url.pathname) &&
        !url.pathname.endsWith(`/${canvasPage.entity_id}`),
      { timeout: 10000 },
    );
    await canvas.waitForEditorUi();
    await expect(languageButton).toContainText('French');

    // The page list marks the pages that do not exist in the working
    // language: no page exists in Spanish.
    await languageButton.click();
    await page.locator('[data-testid="language-option-es"]').click();
    await page.waitForURL(/\/editor\/canvas_page\/\d+\?language=es$/, {
      timeout: 10000,
    });
    await canvas.openContentNavigation();
    await expect(
      page
        .getByTestId('canvas-navigation-results')
        .getByText('Not in Spanish')
        .first(),
    ).toBeVisible();
  });

  test('Changing the language is not offered for a disabled page variant', async ({
    page,
    canvas,
    drupal,
  }) => {
    await login({ username: 'editor', password: 'editor', drupal });
    await canvas.createCanvas();

    // Create a page variant through the Templates panel, disable it and open
    // it. A disabled variant is reported as new by the layout API, but the
    // langcode endpoint only exists for canvas_page.
    await page
      .getByTestId('canvas-side-menu')
      .getByRole('button', { name: 'Templates' })
      .click();
    await page.getByTestId('canvas-page-variant-new-button').click();
    await page.getByTestId('canvas-page-variant-label-input').fill('Marketing');
    await page.getByRole('button', { name: 'Create template' }).click();
    const row = page.getByTestId('canvas-page-variant-marketing');
    await expect(row).toBeVisible();
    await row.hover();
    await row.getByLabel('Open contextual menu').click();
    const disableResponse = page.waitForResponse(
      (response) =>
        response
          .url()
          .includes('/canvas/api/v0/config/page_variant/marketing') &&
        response.request().method() === 'PATCH',
    );
    await page.getByRole('menuitem', { name: 'Disable' }).click();
    await disableResponse;
    await expect(row.getByText('Disabled')).toBeVisible();
    await row.click();
    await expect(page).toHaveURL(/\/canvas\/editor\/page_variant\/marketing/);
    await canvas.waitForEditorFrame();
    const layout = await page.evaluate(() =>
      fetch('/canvas/api/v0/layout/page_variant/marketing').then((response) =>
        response.json(),
      ),
    );
    expect(layout.isNew).toBe(true);

    const topbar = page.locator('[data-testid="canvas-topbar"]');
    await topbar.locator('[data-testid="language-select-trigger"]').click();
    await page.locator('[data-testid="language-option-fr"]').click();
    await page.waitForURL(/\/editor\/page_variant\/marketing\?language=fr/);
    await expect(page.getByTestId('canvas-language-bar')).toContainText(
      'The original is in English.',
    );
    await expect(page.getByTestId('canvas-language-bar-more')).toHaveCount(0);
  });
});
