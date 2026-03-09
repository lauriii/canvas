import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Canvas Page Unpublish/Publish', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['canvas']);
      await page.close();
    },
  );

  test('Page unpublish and publish workflow', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage('Unpublish Test Page', '/unpublish-test');
    await page.goto('/unpublish-test');
    await canvasEditor.goToEditor();

    // Verify a published page can be unpublished.
    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    let dropdownButton = page.getByLabel(
      'Page options for Unpublish Test Page',
    );
    await dropdownButton.click({ force: true });

    let contextMenu = page.getByRole('menu', {
      name: 'Page options for Unpublish Test Page',
    });
    await expect(contextMenu).toBeVisible();

    await expect(contextMenu.getByText('Unpublish page')).toBeVisible();
    await contextMenu.getByText('Unpublish page').click();

    await page.waitForTimeout(500);

    await page.getByTestId('canvas-navigation-button').click();

    let pageListItem = page
      .getByTestId('canvas-navigation-results')
      .filter({ hasText: 'Unpublish Test Page' });
    await expect(pageListItem).toBeVisible();
    await expect(
      pageListItem.getByText('Unpublish', { exact: true }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Review 1 change' }).click();
    await expect(
      page.getByTestId('canvas-publish-review-select-all'),
    ).toBeVisible();
    await page.getByTestId('canvas-publish-review-select-all').check();
    await page.getByRole('button', { name: 'Publish 1 selected' }).click();

    await page.waitForTimeout(1000);

    await page.getByTestId('canvas-navigation-button').click();

    pageListItem = page
      .getByTestId('canvas-navigation-results')
      .filter({ hasText: 'Unpublish Test Page' });
    await expect(pageListItem).toBeVisible();
    await expect(pageListItem.getByText('Unpublished')).toBeVisible();
    await expect(
      pageListItem.getByText('Unpublish', { exact: true }),
    ).not.toBeVisible();

    // Verify an unpublished page can be published.
    await page.getByTestId('canvas-navigation-button').click();
    await page.waitForTimeout(100);
    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    dropdownButton = page.getByLabel('Page options for Unpublish Test Page');
    await dropdownButton.click({ force: true });

    contextMenu = page.getByRole('menu', {
      name: 'Page options for Unpublish Test Page',
    });
    await expect(contextMenu).toBeVisible();

    const publishButton = contextMenu.getByText('Publish page');
    await publishButton.waitFor({ state: 'visible' });
    await publishButton.click();

    // Close the navigation menu
    await page.getByTestId('canvas-navigation-button').click();
    await page.waitForTimeout(100);

    // Wait for the change to be registered and review button to appear
    await expect(
      page.getByRole('button', { name: 'Review 1 change' }),
    ).toBeVisible();

    // Reopen navigation to verify the page state
    await page.getByTestId('canvas-navigation-button').click();

    pageListItem = page
      .getByTestId('canvas-navigation-results')
      .filter({ hasText: 'Unpublish Test Page' });
    await expect(pageListItem).toBeVisible();
    await expect(
      pageListItem.getByText('Unpublish', { exact: true }),
    ).not.toBeVisible();
    await expect(pageListItem.getByText('Unpublished')).not.toBeVisible();

    // Close navigation menu before interacting with Review button
    await page.getByTestId('canvas-navigation-button').click();

    await page.getByRole('button', { name: 'Review 1 change' }).click();
    await expect(
      page.getByTestId('canvas-publish-review-select-all'),
    ).toBeVisible();
    await page.getByTestId('canvas-publish-review-select-all').check();
    await page.getByRole('button', { name: 'Publish 1 selected' }).click();

    await page.waitForTimeout(1000);

    await page.getByTestId('canvas-navigation-button').click();
    await page.waitForTimeout(500);
    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    pageListItem = page
      .getByTestId('canvas-navigation-results')
      .filter({ hasText: 'Unpublish Test Page' });
    await expect(pageListItem).toBeVisible();
    await expect(pageListItem.getByText('Unpublished')).not.toBeVisible();
    await expect(
      pageListItem.getByText('Unpublish', { exact: true }),
    ).not.toBeVisible();

    await page.getByTestId('canvas-navigation-button').click();

    // Verify draft pages cannot be unpublished or published.
    await drupal.createCanvasPage('Untitled page', '/draft-test', false);

    await page.goto('/draft-test');
    await canvasEditor.goToEditor();

    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    const draftPageItem = page
      .getByTestId('canvas-navigation-results')
      .filter({ hasText: 'Untitled page' })
      .first();

    await expect(draftPageItem).toBeVisible();

    dropdownButton = page.getByLabel('Page options for Untitled page');
    await dropdownButton.click({ force: true });

    contextMenu = page.getByRole('menu', {
      name: 'Page options for Untitled page',
    });
    await expect(contextMenu).toBeVisible();

    await expect(contextMenu.getByText('Unpublish page')).not.toBeAttached();
    await expect(contextMenu.getByText('Publish page')).not.toBeAttached();

    // Verify homepage cannot be unpublished.
    await drupal.createCanvasPage('Homepage Test', '/homepage-test');

    await page.goto('/homepage-test');
    await canvasEditor.goToEditor();

    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    dropdownButton = page.getByLabel('Page options for Homepage Test');
    await dropdownButton.click({ force: true });

    contextMenu = page.getByRole('menu', {
      name: 'Page options for Homepage Test',
    });
    await expect(contextMenu).toBeVisible();

    await expect(contextMenu.getByText('Set as homepage')).toBeVisible();
    await contextMenu.getByText('Set as homepage').click();

    // Close the navigation menu to allow the "Review 1 change" button to appear
    await page.getByTestId('canvas-navigation-button').click();

    // Wait longer for the auto-save to be triggered and the review button to appear
    await page.waitForTimeout(1000);

    await expect(
      page.getByRole('button', { name: 'Review 1 change' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Review 1 change' }).click();

    await expect(
      page.getByTestId('canvas-publish-review-select-all'),
    ).toBeVisible();
    await page.getByTestId('canvas-publish-review-select-all').check();

    await page.getByRole('button', { name: 'Publish 1 selected' }).click();

    await page.waitForTimeout(1000);

    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    dropdownButton = page.getByLabel('Page options for Homepage Test');
    await dropdownButton.click({ force: true });

    contextMenu = page.getByRole('menu', {
      name: 'Page options for Homepage Test',
    });
    await expect(contextMenu).toBeVisible();
    await expect(contextMenu.getByText('Duplicate page')).toBeAttached();
    await expect(contextMenu.getByText('Delete page')).not.toBeAttached();
    await expect(contextMenu.getByText('Publish page')).not.toBeAttached();
    await expect(contextMenu.getByText('Unpublish page')).not.toBeAttached();
  });
});
