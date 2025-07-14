import { test } from './fixtures/DrupalSite';
import { expect } from '@playwright/test';

/**
 * This test suite checks that the Experience Builder UI shows/hides UI interface based on the permissions of users
 * with different roles. It first ensures that a user with admin permissions can see all the buttons and options in the UI,
 * then it checks that a user with minimal permissions can still access the UI but with limited functionality.
 */
test.describe('XB UI Permissions', () => {
  test('User with admin permissions can load XB UI and see lots of buttons', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    // Create a role with no permissions
    await drupal.setupXBTestSite();
    await drupal.loginAsAdmin();
    await page.goto('/first');
    await xBEditor.goToEditor();
    await page.getByText('Two Column').click({ button: 'right' });

    const menu = page.getByRole('menu', {
      name: 'Context menu for Two Column',
    });
    await expect(menu.getByText('Paste')).toHaveCount(1);
    await expect(menu.getByText('Create pattern')).toHaveCount(1);
    await expect(menu.getByText('Move to global region')).toHaveCount(1);
    await page.locator('body').click(); // Dismiss the context menu
    await expect(menu).not.toBeAttached();

    await page.getByTestId('xb-navigation-button').click();
    await expect(page.locator('#xb-navigation-search')).toBeVisible();
    await expect(page.getByTestId('xb-navigation-new-button')).toBeAttached();

    // Click the dropdown button with the aria-label
    const dropdownButton = page.getByLabel(
      'Page options for Page without a path',
    );
    await dropdownButton.click({ force: true });

    // Verify the dropdown menu is visible
    const contextMenu = page.getByRole('menu', {
      name: 'Page options for Page without',
    });
    await expect(contextMenu).toBeVisible();

    // Ensure "Duplicate page" and "Delete page" options appear in the context menu
    await expect(contextMenu.getByText('Duplicate page')).toBeVisible();
    await expect(contextMenu.getByText('Delete page')).toBeVisible();

    await page.locator('body').click(); // Dismiss the context menu
    await expect(contextMenu).not.toBeAttached();

    await xBEditor.openLibraryPanel();
    // Check that a button with the text "Add new" exists inside xb-primary-panel
    const primaryPanel = page.getByTestId('xb-primary-panel');
    await expect(
      primaryPanel.getByRole('button', { name: 'Add new' }),
    ).toBeVisible();
    await expect(
      primaryPanel.getByRole('button', { name: 'Code' }),
    ).toBeAttached();

    // Make a change to the page
    await expect(page.getByText('No changes')).toBeAttached();
    await page.getByText('Hero').first().click();
    await expect(page.getByLabel('Sub-heading')).toBeAttached();
    await page.getByLabel('Sub-heading').fill('New Heading');
    await page.getByText('Review 1 change').click();
    await page.getByTestId('xb-publish-review-select-all').click();
    await expect(page.getByText('Publish 1 selected')).toBeAttached();
  });
  test('User with no XB permissions can load XB UI', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    // Create a role with no (well, minimal) XB permissions
    await drupal.createRole({ name: 'xb_no_permissions' });
    await drupal.addPermissions({
      role: 'xb_no_permissions',
      permissions: [
        'view the administration theme',
        'edit xb_page',
        'edit any page content',
        'edit any article content',
      ],
    });

    // Create a user with that role
    const user = {
      email: 'noperms@example.com',
      // cspell:disable-next-line
      username: 'noperms',
      password: 'superstrongpassword1337',
      roles: ['xb_no_permissions'],
    };
    await drupal.createUser(user);
    await drupal.login(user);
    await page.goto('/first');
    await xBEditor.goToEditor();

    await page.getByText('Two Column').click({ button: 'right' });
    const menu = page.getByRole('menu', {
      name: 'Context menu for Two Column',
    });
    await expect(menu.getByText('Paste')).toHaveCount(1);
    await expect(menu.getByText('Create pattern')).toHaveCount(0);
    await expect(menu.getByText('Move to global region')).toHaveCount(0);
    await page.locator('body').click(); // Dismiss the context menu
    await expect(menu).not.toBeAttached();

    await page.getByTestId('xb-navigation-button').click();
    await expect(page.locator('#xb-navigation-search')).toBeVisible();
    await expect(
      page.getByTestId('xb-navigation-new-button'),
    ).not.toBeAttached();

    // Click the dropdown button with the aria-label
    const dropdownButton = page.getByLabel(
      'Page options for Page without a path',
    );
    await dropdownButton.click({ force: true });

    // Verify the dropdown menu is visible
    const contextMenu = page.getByRole('menu', {
      name: 'Page options for Page without',
    });
    await expect(contextMenu).toBeVisible();

    // Ensure the "Delete page" option does not appear in the context menu
    await expect(contextMenu.getByText('Delete page')).not.toBeAttached();

    // @todo https://drupal.org/i/3533728 Update this test when the "Duplicate page" option is hidden by permissions.
    // await expect(contextMenu.getByText('Duplicate page')).not.toBeAttached();

    await page.locator('body').click(); // Dismiss the context menu
    await expect(contextMenu).not.toBeAttached();

    // Open the library panel
    await xBEditor.openLibraryPanel();

    // Check that a button with the text "Add new" does not exist inside xb-primary-panel
    const primaryPanel = page.getByTestId('xb-primary-panel');
    await expect(
      primaryPanel.getByRole('button', { name: 'Add new' }),
    ).not.toBeAttached();
    await expect(
      primaryPanel.getByRole('button', { name: 'Code' }),
    ).not.toBeAttached();

    // Ensure the "Patterns" button is visible - users with no permissions should still be able to use patterns.
    await expect(
      primaryPanel.getByRole('button', { name: 'Patterns' }),
    ).toBeAttached();

    // A change to the page was made in the previous test, so it should be visible.
    await page.getByText('Review 1 change').click();
    await page.getByTestId('xb-publish-review-select-all').click();
    // but the user should not be able to publish changes.
    await expect(page.getByText('Publish 1 selected')).not.toBeAttached();

    // User without "administer components" should not be able to access the code editor.
    await page.goto('/xb/node/1/code-editor/code/foobar');
    await expect(
      page.getByText('You do not have permission to access the code editor.'),
    ).toBeVisible();
  });
});
