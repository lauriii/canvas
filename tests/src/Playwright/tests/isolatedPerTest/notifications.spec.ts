import { Drupal } from '@drupal/playwright';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.describe('Activity Center notifications', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules([
      'canvas_dev_mode',
      'canvas_test_notifications',
    ]);
    await drupal.logout();
  });

  test('Full notification lifecycle', async ({
    page,
    drupal,
    canvas,
    browser,
  }) => {
    // Create a separate browser context for admin so we don't need to
    // log in/out repeatedly. Both windows share the same test database.
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const adminDrupal = new Drupal({
      page: adminPage,
      drupalSite: drupal.drupalSite,
    });
    await adminDrupal.setTestCookie();
    await adminDrupal.loginAsAdmin();

    // Log in as editor in the main window.
    await drupal.login({ username: 'editor', password: 'editor' });

    const bell = page.getByRole('button', { name: 'Notifications' });
    const badge = page.locator('[class*="badge"]');

    // Create one of each notification type via the admin window.
    await canvas.createNotification(
      {
        type: 'processing',
        title: 'Syncing content',
        message: 'Importing pages from source...',
        key: 'sync',
      },
      adminPage,
    );
    await canvas.createNotification(
      {
        type: 'error',
        title: 'Import failed',
        message: 'Could not connect to remote server.',
        key: 'import-fail',
      },
      adminPage,
    );
    await canvas.createNotification(
      {
        type: 'warning',
        title: 'Disk space low',
        message: 'Less than 10% disk space remaining.',
      },
      adminPage,
    );
    await canvas.createNotification(
      {
        type: 'success',
        title: 'Page published',
        message: 'Homepage was published successfully.',
      },
      adminPage,
    );
    await canvas.createNotification(
      {
        type: 'info',
        title: 'New version available',
        message: 'Canvas 2.1 is now available.',
      },
      adminPage,
    );

    // Open Canvas as editor and verify badge shows "3".
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);

    // Wait for polling to fetch notifications and badge to appear.
    await expect(async () => {
      await expect(badge).toHaveText('3');
    }).toPass({ intervals: [1_000, 2_000, 5_000], timeout: 30_000 });

    // Open bell - info is auto-read, badge becomes "2".
    await bell.click();
    await expect(page.getByText('Activity Center')).toBeVisible();
    await expect(async () => {
      await expect(badge).toHaveText('2');
    }).toPass({ intervals: [1_000, 2_000], timeout: 15_000 });

    // Click the read dot on the error notification, badge becomes "1".
    const errorCard = page.locator('[data-type="error"]').first();
    await errorCard.getByRole('button', { name: /mark as/i }).click();
    await expect(async () => {
      await expect(badge).toHaveText('1');
    }).toPass({ intervals: [500, 1_000], timeout: 10_000 });

    // Close the popover by clicking outside.
    await page.getByTestId('canvas-topbar').click({ position: { x: 5, y: 5 } });

    // Hard refresh, badge persists as "1".
    await page.reload();
    await expect(async () => {
      await expect(badge).toHaveText('1');
    }).toPass({ intervals: [1_000, 2_000, 5_000], timeout: 30_000 });

    // Open bell, mark warning as read, badge disappears.
    await bell.click();
    await expect(page.getByText('Activity Center')).toBeVisible();

    const warningCard = page.locator('[data-type="warning"]').first();
    await warningCard.getByRole('button', { name: /mark as/i }).click();
    await expect(badge).toBeHidden();

    // Close the popover.
    await page.getByTestId('canvas-topbar').click({ position: { x: 5, y: 5 } });

    // Create success with key "sync" via admin window, replaces processing.
    await canvas.createNotification(
      {
        type: 'success',
        key: 'sync',
        title: 'Content synced',
        message: 'Content was synced successfully.',
      },
      adminPage,
    );

    // Reload editor to pick up changes.
    await canvas.openCanvas(canvasPage);
    await bell.click();
    await expect(page.getByText('Activity Center')).toBeVisible();
    await expect(page.getByText('Content synced')).toBeVisible();
    await expect(page.getByText('Syncing content')).toBeHidden();

    // Close the popover.
    await page.getByTestId('canvas-topbar').click({ position: { x: 5, y: 5 } });

    // Create success with key "import-fail" via admin window, replaces error.
    await canvas.createNotification(
      {
        type: 'success',
        title: 'Import success',
        message: 'Hurray!',
        key: 'import-fail',
      },
      adminPage,
    );

    // Reload editor to pick up changes.
    await canvas.openCanvas(canvasPage);
    await bell.click();
    await expect(page.getByText('Activity Center')).toBeVisible();
    await expect(page.getByText('Import success')).toBeVisible();
    await expect(page.getByText('Import failed')).toBeHidden();

    // Mark all as read, create new error, wait for badge.
    await page.getByRole('button', { name: 'Mark all as read' }).click();

    // Close the popover.
    await page.getByTestId('canvas-topbar').click({ position: { x: 5, y: 5 } });

    // Create a new error notification via admin window.
    await canvas.createNotification(
      {
        type: 'error',
        title: 'Big error',
        message: 'Massive error occurred',
      },
      adminPage,
    );

    // Wait for polling to pick up the new notification, badge should show "1".
    await expect(async () => {
      await expect(badge).toHaveText('1');
    }).toPass({ intervals: [2_000, 5_000, 10_000], timeout: 60_000 });

    await adminContext.close();
  });

  test('Notification with action links renders clickable links', async ({
    page,
    drupal,
    canvas,
    browser,
  }) => {
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const adminDrupal = new Drupal({
      page: adminPage,
      drupalSite: drupal.drupalSite,
    });
    await adminDrupal.setTestCookie();
    await adminDrupal.loginAsAdmin();

    await drupal.login({ username: 'editor', password: 'editor' });

    const bell = page.getByRole('button', { name: 'Notifications' });

    // Create a notification with action links.
    await canvas.createNotification(
      {
        type: 'error',
        title: 'Import failed',
        message: 'Could not connect to remote server.',
        actions: [
          { label: 'View logs', href: '/admin/reports/dblog' },
          { label: 'Retry', href: '/admin/config/content/canvas' },
        ],
      },
      adminPage,
    );

    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);

    // Wait for polling to fetch the notification.
    await expect(async () => {
      await expect(page.locator('[class*="badge"]')).toBeVisible();
    }).toPass({ intervals: [1_000, 2_000, 5_000], timeout: 30_000 });

    await bell.click();
    await expect(page.getByText('Activity Center')).toBeVisible();

    // Verify the notification card and its action links.
    await expect(page.getByText('Import failed')).toBeVisible();
    const viewLogsLink = page.getByRole('link', { name: 'View logs' });
    const retryLink = page.getByRole('link', { name: 'Retry' });
    await expect(viewLogsLink).toBeVisible();
    await expect(retryLink).toBeVisible();
    await expect(viewLogsLink).toHaveAttribute('href', '/admin/reports/dblog');
    await expect(retryLink).toHaveAttribute(
      'href',
      '/admin/config/content/canvas',
    );

    // Verify the pipe separator between action links.
    await expect(page.getByText('|', { exact: true })).toBeVisible();

    await adminContext.close();
  });

  test('Mark all as read clears badge and persists after reload', async ({
    page,
    drupal,
    canvas,
    browser,
  }) => {
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    const adminDrupal = new Drupal({
      page: adminPage,
      drupalSite: drupal.drupalSite,
    });
    await adminDrupal.setTestCookie();
    await adminDrupal.loginAsAdmin();

    await drupal.login({ username: 'editor', password: 'editor' });

    const bell = page.getByRole('button', { name: 'Notifications' });
    const badge = page.locator('[class*="badge"]');

    // Create notifications that contribute to the badge (info, warning, error).
    await canvas.createNotification(
      { type: 'info', title: 'Info note', message: 'FYI' },
      adminPage,
    );
    await canvas.createNotification(
      { type: 'warning', title: 'Warning note', message: 'Heads up' },
      adminPage,
    );
    await canvas.createNotification(
      { type: 'error', title: 'Error note', message: 'Something broke' },
      adminPage,
    );

    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);

    // Wait for badge to appear with count "3".
    await expect(async () => {
      await expect(badge).toHaveText('3');
    }).toPass({ intervals: [1_000, 2_000, 5_000], timeout: 30_000 });

    // Open bell — info is auto-read, badge becomes "2".
    await bell.click();
    await expect(page.getByText('Activity Center')).toBeVisible();
    await expect(async () => {
      await expect(badge).toHaveText('2');
    }).toPass({ intervals: [1_000, 2_000], timeout: 15_000 });

    // Click "Mark all as read" — badge should disappear.
    await page.getByRole('button', { name: 'Mark all as read' }).click();
    await expect(badge).toBeHidden();

    // Close the popover.
    await page.getByTestId('canvas-topbar').click({ position: { x: 5, y: 5 } });

    // Reload and verify badge stays hidden.
    await page.reload();
    await expect(async () => {
      // Wait for polling to fetch notifications.
      await expect(bell).toBeVisible();
    }).toPass({ intervals: [1_000, 2_000], timeout: 15_000 });
    await expect(badge).toBeHidden();

    await adminContext.close();
  });
});

test.describe('Toast notifications', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules([
      'canvas_dev_mode',
      'canvas_test_notifications',
    ]);
    await drupal.logout();
  });

  test('Toast appears for new notification', async ({
    page,
    drupal,
    canvas,
    browser,
  }) => {
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

    // Seed notification AFTER page load so timestamp > pageOpenedAt.
    await canvas.createNotification(
      {
        type: 'error',
        title: 'Toast test error',
        message: 'This should appear as a toast.',
      },
      adminPage,
    );

    // Wait for toast to appear.
    await expect(async () => {
      await expect(page.getByText('Toast test error')).toBeVisible();
    }).toPass({ intervals: [2_000, 5_000], timeout: 30_000 });

    await expect(
      page.getByText('This should appear as a toast.'),
    ).toBeVisible();

    await adminContext.close();
  });

  test('Toast has correct type styling', async ({
    page,
    drupal,
    canvas,
    browser,
  }) => {
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

    await canvas.createNotification(
      {
        type: 'error',
        title: 'Error toast',
        message: 'Error details.',
      },
      adminPage,
    );
    await canvas.createNotification(
      {
        type: 'warning',
        title: 'Warning toast',
        message: 'Warning details.',
      },
      adminPage,
    );

    // Wait for toasts to appear.
    await expect(async () => {
      await expect(page.getByText('Error toast')).toBeVisible();
    }).toPass({ intervals: [2_000, 5_000], timeout: 30_000 });

    await expect(page.getByText('Warning toast')).toBeVisible();

    // Verify type-specific data attributes on toast cards.
    const errorToast = page.locator('[data-type="error"]').filter({
      hasText: 'Error toast',
    });
    const warningToast = page.locator('[data-type="warning"]').filter({
      hasText: 'Warning toast',
    });
    await expect(errorToast).toBeVisible();
    await expect(warningToast).toBeVisible();

    await adminContext.close();
  });

  test('Dismiss marks as read', async ({ page, drupal, canvas, browser }) => {
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

    const bell = page.getByRole('button', { name: 'Notifications' });
    const badge = page.locator('[class*="badge"]');

    await canvas.createNotification(
      {
        type: 'error',
        title: 'Dismiss test',
        message: 'Dismiss this toast.',
      },
      adminPage,
    );

    // Wait for toast to appear.
    await expect(async () => {
      await expect(page.getByText('Dismiss test')).toBeVisible();
    }).toPass({ intervals: [2_000, 5_000], timeout: 30_000 });

    // Click dismiss on the toast.
    await page.getByRole('button', { name: 'Dismiss notification' }).click();

    // Toast should disappear.
    await expect(page.getByText('Dismiss test')).toBeHidden();

    // Open Activity Center — notification should be marked as read (no unread dot).
    await bell.click();
    await expect(page.getByText('Activity Center')).toBeVisible();
    await expect(page.getByText('Dismiss test')).toBeVisible();
    // Badge should not show since the only notification was marked read.
    await expect(badge).toBeHidden();

    await adminContext.close();
  });

  test('Toast and Activity Center show same notification', async ({
    page,
    drupal,
    canvas,
    browser,
  }) => {
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

    const bell = page.getByRole('button', { name: 'Notifications' });

    await canvas.createNotification(
      {
        type: 'info',
        title: 'Shared notification',
        message: 'Visible in both toast and activity center.',
      },
      adminPage,
    );

    // Wait for toast to appear.
    await expect(async () => {
      await expect(page.getByText('Shared notification')).toBeVisible();
    }).toPass({ intervals: [2_000, 5_000], timeout: 30_000 });

    // Open Activity Center — same notification should be there.
    await bell.click();
    await expect(
      page.getByRole('heading', { name: 'Activity Center' }),
    ).toBeVisible();
    // The notification text should appear in the Activity Center list too
    // (the popover dialog contains a second copy beyond the toast).
    await expect(
      page
        .getByRole('dialog')
        .getByText('Visible in both toast and activity center.'),
    ).toBeVisible();

    await adminContext.close();
  });
});
