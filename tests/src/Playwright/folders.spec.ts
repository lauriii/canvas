import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

/**
 * Tests folder management in Drupal Canvas.
 */
test.describe('Folder Management', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.drush('cr');

      await drupal.installModules([
        'canvas',
        'canvas_test_folders',
        'canvas_dev_mode',
      ]);

      // @todo remove the cache clear once https://www.drupal.org/project/drupal/issues/3534825
      // is fixed.
      await drupal.drush('cr');
      await page.close();
    },
  );

  test('Folder display and creation', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await canvasEditor.goToCanvasRoot();
    await canvasEditor.openLibraryPanel();

    await page.waitForLoadState('networkidle');
    await page
      .getByTestId('canvas-page-list-new-button')
      .waitFor({ state: 'visible' });
    await page.getByTestId('canvas-page-list-new-button').click();

    await expect(
      page.getByTestId('canvas-library-new-folder-button'),
    ).toBeVisible();

    // Close the dropdown menu
    await page
      .getByTestId('canvas-page-list-new-button')
      .click({ force: true });

    // We begin on the Components tab.
    await expect(
      page.locator(
        '[data-testid="canvas-library-components-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await expect(
      page.locator('[data-testid="canvas-library-components-tab-content"]'),
    ).toBeVisible();

    // Confirm the Components tab contents.
    await expect(
      page.locator('[data-testid="canvas-library-components-tab-content"]'),
    ).toMatchAriaSnapshot({
      name: 'Folder-Management-Folder-display-and-creation-1.aria.yml',
    });
    await page
      .locator('[data-testid="canvas-library-patterns-tab-select"]')
      .click();

    // Move to the Patterns tab.
    await expect(
      page.locator(
        '[data-testid="canvas-library-patterns-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await expect(
      page.locator('[data-testid="canvas-library-patterns-tab-content"]'),
    ).toBeVisible();

    // Confirm the Patterns tab contents.
    await expect(
      page.locator('[data-testid="canvas-library-patterns-tab-content"]'),
    ).toMatchAriaSnapshot({
      name: 'Folder-Management-Folder-display-and-creation-2.aria.yml',
    });

    // Move to the Code panel.
    await canvasEditor.openCodePanel();

    await expect(
      page.locator('[data-testid="canvas-code-panel-content"]'),
    ).toBeVisible();

    // Confirm the Code tab contents.
    await expect(
      page.locator('[data-testid="canvas-code-panel-content"]'),
    ).toMatchAriaSnapshot({
      name: 'Folder-Management-Folder-display-and-creation-3.aria.yml',
    });

    // Helper to add folders and confirm they appear.
    const testAddFolder = async (
      foldersToAdd: string[],
      allExpectedFolders: string[],
    ) => {
      for (const folderName of foldersToAdd) {
        // Close any open dropdown first by pressing Escape
        await page.keyboard.press('Escape');
        await page.waitForTimeout(500);

        // Wait for button to be in closed state
        await page
          .getByTestId('canvas-page-list-new-button')
          .waitFor({ state: 'visible', timeout: 10000 });

        // Open the New dropdown
        await page.getByTestId('canvas-page-list-new-button').click({
          force: true,
          timeout: 10000,
        });

        // Wait for dropdown to be visible
        await page
          .getByTestId('canvas-library-new-folder-button')
          .waitFor({ state: 'visible', timeout: 10000 });

        // Click Add folder option
        await page.getByTestId('canvas-library-new-folder-button').click({
          timeout: 10000,
        });

        // Wait for the folder input to appear
        const folderInput = page.getByTestId(
          'canvas-manage-library-new-folder-name',
        );
        await expect(folderInput).toBeVisible({ timeout: 10000 });

        await folderInput.clear();
        await page
          .getByTestId('canvas-manage-library-new-folder-name')
          .fill('');
        await folderInput.fill(folderName);

        // Submit by pressing Enter
        await page
          .getByTestId('canvas-manage-library-new-folder-name')
          .press('Enter');

        // Wait for folder creation to complete (input should disappear)
        await expect(
          page.getByTestId('canvas-manage-library-new-folder-name'),
        ).not.toBeVisible({ timeout: 10000 });

        // Verify the folder was created
        await page
          .locator(`[data-canvas-folder-name="${folderName}"]`)
          .waitFor({ state: 'attached', timeout: 10000 });
      }

      const folderElements = await page
        .locator('[data-canvas-folder-name]')
        .all();
      const actualFolderNames = await Promise.all(
        folderElements.map(async (element) => {
          return await element.getAttribute('data-canvas-folder-name');
        }),
      );
      expect(actualFolderNames).toEqual(allExpectedFolders);
    };

    // Test adding a folder to the Code panel.
    await testAddFolder(
      ['Awesome New Folder', 'Is a Code Folder', 'Very Nice New Folder'],
      [
        'Very Nice New Folder',
        'Is a Code Folder',
        'Awesome New Folder',
        'Active Users of Using',
        'Empty Code',
        'Proclaimers of With',
      ],
    );

    // Test adding a folder to the Patterns tab.
    await canvasEditor.openLibraryPanel();
    await page
      .locator('[data-testid="canvas-library-patterns-tab-select"]')
      .click();
    await expect(
      page.locator(
        '[data-testid="canvas-library-patterns-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await testAddFolder(
      ['Awesome New Folder', 'Is a Pattern Folder', 'Very Nice New Folder'],
      [
        'Very Nice New Folder',
        'Is a Pattern Folder',
        'Awesome New Folder',
        'Animal Pats',
        'Color Patterns',
        'Empty Patterns',
      ],
    );

    // Test adding a folder to the Components tab.
    await page
      .locator('[data-testid="canvas-library-components-tab-select"]')
      .click();
    await expect(
      page.locator(
        '[data-testid="canvas-library-components-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await testAddFolder(
      ['Awesome New Folder', 'Is a Component Folder', 'Very Nice New Folder'],
      [
        'Very Nice New Folder',
        'Is a Component Folder',
        'Awesome New Folder',
        'Atom/Media',
        'Atom/Tabs',
        'Atom/Text',
        'Container',
        'Container/Special',
        'Empty Components',
        'Menus',
        'Other',
        'Status',
        'System',
      ],
    );
  });
});
