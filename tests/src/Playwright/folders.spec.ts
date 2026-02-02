import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

import type { Page } from '@playwright/test';

/**
 * Tests folder management in Drupal Canvas.
 */
test.describe('Folder Management', () => {
  // Helper to add folders and confirm they appear.
  const testAddFolder = async (
    page: Page,
    foldersToAdd: string[],
    allExpectedFolders?: string[],
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
      await page.getByTestId('canvas-manage-library-new-folder-name').fill('');
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

    // Only validate all folders if allExpectedFolders is provided
    if (allExpectedFolders) {
      const folderElements = await page
        .locator('[data-canvas-folder-name]')
        .all();
      const actualFolderNames = await Promise.all(
        folderElements.map(async (element) => {
          return await element.getAttribute('data-canvas-folder-name');
        }),
      );
      expect(actualFolderNames).toEqual(allExpectedFolders);
    }
  };

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

    // Test adding a folder to the Code panel.
    await testAddFolder(
      page,
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
      page,
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
      page,
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

  test('Folder renaming', async ({ page, drupal, canvasEditor }) => {
    await drupal.loginAsAdmin();
    await canvasEditor.goToCanvasRoot();
    await canvasEditor.openLibraryPanel();

    // Navigate to Components tab
    await page
      .locator('[data-testid="canvas-library-components-tab-select"]')
      .click();
    await expect(
      page.locator(
        '[data-testid="canvas-library-components-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();

    // Create two test folders
    await testAddFolder(page, ['Test Folder to Rename', 'Existing Folder']);

    // Test: Double-click to enter rename mode
    await page
      .locator('[data-canvas-folder-name="Test Folder to Rename"]')
      .dblclick();

    // Verify TextField is visible and focused after double-click
    const textFieldDoubleClick = page.getByTestId('canvas-folder-rename-input');
    await textFieldDoubleClick.waitFor({ state: 'visible', timeout: 5000 });
    await expect(textFieldDoubleClick).toBeFocused();
    await expect(textFieldDoubleClick).toHaveValue('Test Folder to Rename');

    // Test successful rename via double-click with Enter key
    await textFieldDoubleClick.fill('Renamed via Double Click');
    await textFieldDoubleClick.press('Enter');
    await page
      .locator('[data-canvas-folder-name="Renamed via Double Click"]')
      .waitFor({ state: 'attached', timeout: 10000 });
    await expect(
      page.locator('[data-canvas-folder-name="Test Folder to Rename"]'),
    ).not.toBeAttached();

    // Wait for it to fully stabilize after rename
    await page.waitForTimeout(500);

    // Test: Double-click rename cancellation with Escape
    await page
      .locator('[data-canvas-folder-name="Renamed via Double Click"]')
      .dblclick();
    const textFieldDoubleClickCancel = page.getByTestId(
      'canvas-folder-rename-input',
    );
    await textFieldDoubleClickCancel.waitFor({
      state: 'visible',
      timeout: 5000,
    });
    await textFieldDoubleClickCancel.fill('Should Be Cancelled');
    await textFieldDoubleClickCancel.press('Escape');
    await expect(
      page.locator('[data-canvas-folder-name="Renamed via Double Click"]'),
    ).toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Should Be Cancelled"]'),
    ).not.toBeAttached();

    // Test: Open folder menu and click Rename (traditional method)
    await page
      .locator('[data-canvas-folder-name="Renamed via Double Click"]')
      .hover();
    await page
      .locator('[data-canvas-folder-name="Renamed via Double Click"]')
      .getByRole('button', { name: 'Menu' })
      .click();
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    await expect(page.getByRole('menu')).not.toBeVisible();

    const textField = page.getByTestId('canvas-folder-rename-input');
    await textField.waitFor({ state: 'visible', timeout: 5000 });
    await expect(textField).toBeFocused();
    await expect(textField).toHaveValue('Renamed via Double Click');

    await textField.fill('Renamed Folder');
    await textField.press('Enter');
    await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .waitFor({ state: 'attached', timeout: 10000 });
    await expect(
      page.locator('[data-canvas-folder-name="Renamed via Double Click"]'),
    ).not.toBeAttached();

    // Test rename cancellation on blur without changes
    await page.locator('[data-canvas-folder-name="Renamed Folder"]').hover();
    await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .getByRole('button', { name: 'Menu' })
      .click();
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    const textField3 = page.getByTestId('canvas-folder-rename-input');
    await textField3.waitFor({ state: 'visible', timeout: 5000 });
    await textField3.blur();
    await expect(
      page.locator('[data-canvas-folder-name="Renamed Folder"]'),
    ).toBeAttached();

    // Test validation error for duplicate folder name
    await page.locator('[data-canvas-folder-name="Renamed Folder"]').hover();
    await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .getByRole('button', { name: 'Menu' })
      .click();
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    const textField4 = page.getByTestId('canvas-folder-rename-input');
    await textField4.waitFor({ state: 'visible', timeout: 5000 });
    await textField4.fill('Existing Folder');
    await textField4.press('Enter');
    await page.waitForTimeout(1000);
    // The error message is in a span with data-accent-color="red" and contains "is not unique"
    const errorSpan = page.locator('span[data-accent-color="red"]');
    await expect(errorSpan).toBeVisible({ timeout: 10000 });
    await expect(errorSpan).toContainText('is not unique');

    await textField4.press('Escape');

    // Verify folder was not renamed (still has original name)
    await expect(
      page.locator('[data-canvas-folder-name="Renamed Folder"]'),
    ).toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Existing Folder"]'),
    ).toHaveCount(1);

    // Test that folder state (open/closed) is preserved during rename
    await page.locator('[data-canvas-folder-name="Renamed Folder"]').click();
    const isFolderClosed = await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .getAttribute('aria-expanded');

    await page.locator('[data-canvas-folder-name="Renamed Folder"]').hover();
    await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .getByRole('button', { name: 'Menu' })
      .click();
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    const textField5 = page.getByTestId('canvas-folder-rename-input');
    await textField5.waitFor({ state: 'visible', timeout: 5000 });
    await textField5.press('Escape');

    const isFolderStillClosed = await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .getAttribute('aria-expanded');
    expect(isFolderClosed).toBe(isFolderStillClosed);
  });

  test('Folder deletion', async ({ page, drupal, canvasEditor }) => {
    await drupal.loginAsAdmin();
    await canvasEditor.goToCanvasRoot();
    await canvasEditor.openLibraryPanel();

    await page
      .locator('[data-testid="canvas-library-components-tab-select"]')
      .click();
    await expect(
      page.locator(
        '[data-testid="canvas-library-components-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();

    // Create an empty test folder for deletion in Library tab.
    await testAddFolder(page, ['Empty Folder To Delete']);

    // Verify the folder was created.
    await expect(
      page.locator('[data-canvas-folder-name="Empty Folder To Delete"]'),
    ).toBeAttached();

    // Successfully delete an empty folder.
    await page
      .locator('[data-canvas-folder-name="Empty Folder To Delete"]')
      .hover();
    await page
      .locator('[data-canvas-folder-name="Empty Folder To Delete"]')
      .getByRole('button', { name: 'Menu' })
      .click();

    // Click Delete folder option.
    await page.getByRole('menuitem', { name: 'Delete folder' }).click();

    // Wait for folder to be deleted.
    await expect(
      page.locator('[data-canvas-folder-name="Empty Folder To Delete"]'),
    ).not.toBeAttached({ timeout: 10000 });

    // Attempt to delete a folder containing components and
    // verify deletion is disabled.
    // Find a folder that contains components.
    const folderWithComponents = page.locator(
      '[data-canvas-folder-name="Atom/Text"]',
    );
    if ((await folderWithComponents.count()) > 0) {
      await folderWithComponents.hover();
      await folderWithComponents.getByRole('button', { name: 'Menu' }).click();
      // The delete folder menu item should be present and disabled.
      const deleteMenuItem = page.getByRole('menuitem', {
        name: 'Delete folder',
      });
      await expect(deleteMenuItem).toBeVisible();
      await expect(deleteMenuItem).toBeDisabled();
      // Close the menu by pressing Escape.
      await page.keyboard.press('Escape');
    }
  });
});
