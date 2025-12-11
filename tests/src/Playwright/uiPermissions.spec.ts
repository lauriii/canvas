import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

/**
 * This test suite checks that the Drupal Canvas UI shows/hides UI interface based on the permissions of users
 * with different roles. It first ensures that a user with admin permissions can see all the buttons and options in the UI,
 * then it checks that a user with minimal permissions can still access the UI but with limited functionality.
 */
test.describe('Canvas UI Permissions', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['canvas', 'canvas_test_sdc']);
      await page.close();
    },
  );

  test('User with admin permissions can load Canvas UI and see lots of buttons', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage('UI Permissions 1', '/ui-perms-1');
    await page.goto('/ui-perms-1');
    await canvasEditor.goToEditor();

    await expect(page.getByText('No changes')).toBeAttached();

    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'sdc.canvas_test_sdc.two_column' });

    await canvasEditor.openLayersPanel();
    await page
      .getByTestId('canvas-primary-panel')
      .getByText('Two Column')
      .click({ button: 'right' });

    const menu = page.getByRole('menu', {
      name: 'Context menu for Two Column',
    });
    await expect(menu.getByText('Paste')).toHaveCount(1);
    await expect(menu.getByText('Create pattern')).toHaveCount(1);
    await expect(menu.getByText('Move to global region')).toHaveCount(1);
    await page.locator('body').click(); // Dismiss the context menu
    await expect(menu).not.toBeAttached();

    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();
    await expect(
      page.getByTestId('canvas-navigation-new-button'),
    ).toBeAttached();

    // Click the dropdown button with the aria-label
    const dropdownButton = page.getByLabel('Page options for UI Permissions 1');
    await dropdownButton.click({ force: true });

    // Verify the dropdown menu is visible
    const contextMenu = page.getByRole('menu', {
      name: 'Page options for UI Permissions 1',
    });
    await expect(contextMenu).toBeVisible();

    // Ensure "Duplicate page" and "Delete page" options appear in the context menu
    await expect(contextMenu.getByText('Duplicate page')).toBeVisible();
    await expect(contextMenu.getByText('Delete page')).toBeVisible();

    await page.locator('body').click(); // Dismiss the context menu
    await expect(contextMenu).not.toBeAttached();

    await canvasEditor.openLibraryPanel();
    // Open the "New" dropdown
    await page.getByTestId('canvas-page-list-new-button').click();

    // The add new code component button should be visible
    await expect(
      page.getByTestId('canvas-library-new-code-component-button'),
    ).toBeVisible();

    // Close the dropdown
    await page
      .getByTestId('canvas-page-list-new-button')
      .click({ force: true });

    // Make a change to the page
    await canvasEditor.addComponent({ name: 'Hero' });

    await expect(page.getByLabel('Sub-heading')).toBeAttached();
    await page.getByLabel('Sub-heading').fill('New Heading');
    await expect(page.getByLabel('Sub-heading')).toHaveValue('New Heading');
    await page.getByText('Review 1 change').click();
    await page.getByTestId('canvas-publish-review-select-all').click();
    await expect(page.getByText('Publish 1 selected')).toBeAttached();
  });

  test('User with no Canvas permissions can load Canvas UI', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage('UI Permissions 2', '/ui-perms-2');
    // Create a role with no (well, minimal) Canvas permissions
    await drupal.createRole({ name: 'canvas_no_permissions' });
    await drupal.addPermissions({
      role: 'canvas_no_permissions',
      permissions: ['view the administration theme', 'edit canvas_page'],
    });

    // Create a user with that role
    const user = {
      email: 'noperms@example.com',
      // cspell:disable-next-line
      username: 'noperms',
      password: 'superstrongpassword1337',
      roles: ['canvas_no_permissions'],
    };
    await drupal.createUser(user);
    await drupal.logout();
    await drupal.login(user);
    await page.goto('/ui-perms-2');
    await canvasEditor.goToEditor();

    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'sdc.canvas_test_sdc.two_column' });

    await canvasEditor.openLayersPanel();

    await page
      .getByTestId('canvas-primary-panel')
      .getByText('Two Column')
      .click({ button: 'right' });
    const menu = page.getByRole('menu', {
      name: 'Context menu for Two Column',
    });
    await expect(menu.getByText('Paste')).toHaveCount(1);
    await expect(menu.getByText('Create pattern')).toHaveCount(0);
    await expect(menu.getByText('Move to global region')).toHaveCount(0);
    await page.locator('body').click(); // Dismiss the context menu
    await expect(menu).not.toBeAttached();

    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();
    await expect(
      page.getByTestId('canvas-navigation-new-button'),
    ).not.toBeAttached();

    // Click the dropdown button with the aria-label
    const dropdownButton = page.getByLabel('Page options for UI Permissions 2');
    await dropdownButton.click({ force: true });

    // Verify the dropdown menu is visible
    const contextMenu = page.getByRole('menu', {
      name: 'Page options for UI Permissions 2',
    });
    await expect(contextMenu).toBeVisible();

    // Ensure the "Delete page" option does not appear in the context menu
    await expect(contextMenu.getByText('Delete page')).not.toBeAttached();

    // @todo https://drupal.org/i/3533728 Update this test when the "Duplicate page" option is hidden by permissions.
    // await expect(contextMenu.getByText('Duplicate page')).not.toBeAttached();

    await page.locator('body').click(); // Dismiss the context menu
    await expect(contextMenu).not.toBeAttached();

    // Open the library panel
    await canvasEditor.openLibraryPanel();

    // The "New" dropdown should not be visible
    await expect(
      page.getByTestId('canvas-page-list-new-button'),
    ).not.toBeAttached();

    const primaryPanel = page.getByTestId('canvas-primary-panel');
    await expect(
      primaryPanel.getByRole('button', { name: 'Code' }),
    ).not.toBeAttached();

    // Ensure the "Patterns" button is visible - users with no permissions should still be able to use patterns.
    await expect(
      primaryPanel.getByTestId('canvas-library-patterns-tab-select'),
    ).toBeAttached();

    await canvasEditor.addComponent({ name: 'Hero' });
    await page.getByTestId('canvas-publish-review').click();
    await page
      .getByTestId('canvas-publish-reviews-content')
      .filter({ hasText: 'Unpublished changes' })
      .waitFor({ state: 'visible' });
    await page.getByTestId('canvas-publish-review-select-all').click();
    // but the user should not be able to publish changes.
    await expect(page.getByText('Publish 1 selected')).not.toBeAttached();

    // User without "administer components" should not be able to access the code editor.
    await page.goto('/canvas/code-editor/component/foobar');
    await expect(
      page.getByText('You do not have permission to access the code editor.'),
    ).toBeVisible();
  });
});
