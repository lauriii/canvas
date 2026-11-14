import { Drupal } from '@drupal/playwright';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

// @cspell:ignore Fcanvas Feditor
/**
 * Tests that Canvas blocks the UI while the site is in maintenance mode.
 */
const setMaintenanceMode = async (adminPage: Page, enabled: boolean) => {
  await adminPage.goto('/admin/config/development/maintenance');
  await adminPage
    .getByLabel('Put site into maintenance mode')
    .setChecked(enabled);
  await adminPage.getByRole('button', { name: 'Save configuration' }).click();
  await expect(
    adminPage.getByText('The configuration options have been saved.'),
  ).toBeVisible();
};

test.describe('Maintenance mode', () => {
  test('Blocks the UI, then sends the user to log in', async ({
    page,
    drupal,
    canvas,
    browser,
  }) => {
    // The admin is exempt from maintenance mode, so a separate browser context
    // can toggle it while the editor is logged out.
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const adminDrupal = new Drupal({
      page: adminPage,
      drupalSite: drupal.drupalSite,
    });
    await adminDrupal.setTestCookie();
    await adminDrupal.loginAsAdmin();

    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);

    await setMaintenanceMode(adminPage, true);

    // Canvas polls for unpublished changes every 10 seconds, without any user
    // action, and that request now gets an HTTP 503.
    const dialog = page.getByRole('alertdialog', {
      name: 'Site is currently under maintenance',
    });
    await expect(dialog).toBeVisible({ timeout: 20000 });
    await expect(dialog).toBeFocused();
    await expect(page.getByTestId('canvas-error-alert')).not.toBeAttached();

    await setMaintenanceMode(adminPage, false);

    // The overlay polls every 10 seconds.
    await expect(page).toHaveURL(
      /\/user\/login\?destination=%2Fcanvas%2Feditor%2Fcanvas_page%2F\d+/,
      { timeout: 20000 },
    );

    await adminContext.close();
  });
});
