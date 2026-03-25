import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * This test suite will verify that links in the preview are intercepted.
 */
test.describe('Preview Link Behavior', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules(['canvas_test_sdc']);
    await drupal.logout();
  });

  test('Can view a preview and change preview width', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Hero' });

    await canvas.openPreview();

    // Check the preview iframe has loaded and contains the hero heading
    const previewFrame = await page
      .locator('iframe[class^="_PagePreviewIframe"]')
      .contentFrame();
    await expect(previewFrame.getByText('There goes my hero')).toBeVisible();

    // Switch to Tablet view
    await page.getByRole('button', { name: 'Select preview width' }).click();
    await page.getByRole('menuitemradio', { name: 'Tablet (1024px)' }).click();

    await expect(page.locator('iframe[title="Page preview"]')).toHaveCSS(
      'width',
      '1024px',
    );

    // /canvas/{node|canvas_page}/{whateverID}/preview/tablet
    await expect(page).toHaveURL(/\/canvas\/preview\/[^/]+\/[^/]+\/tablet/);
    // Exit preview and wait for editor UI
    await canvas.closePreview();
  });

  test('Links in the preview should be intercepted', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Hero' });
    await page.getByRole('button', { name: 'Preview', exact: true }).click();

    await expect(
      page.frameLocator('iframe[title="Page preview"]').locator('body'),
    ).toBeVisible();

    await page
      .locator('iframe[title="Page preview"]')
      .contentFrame()
      .getByRole('link', { name: 'View' })
      .click();

    await expect(
      page.getByRole('button', { name: 'Open in new window' }),
    ).toBeVisible();
    await expect(page.getByText('Link clicked')).toBeVisible();
    await expect(page.getByText('https://example.com/')).toBeVisible();

    await page.getByRole('button', { name: 'Close' }).click();

    // Insert a link into the preview iframe so that we can ensure that even links added dynamically are intercepted.
    const iframeElement = await page.$('iframe[title="Page preview"]');
    const previewFrame = await iframeElement.contentFrame();
    if (!previewFrame) throw new Error('Preview iframe not found');
    await previewFrame.evaluate(() => {
      const link = document.createElement('a');
      link.href = 'https://example.com/';
      link.textContent = 'Dynamically inserted link';
      link.id = 'test-drupal-link';
      document.body.appendChild(link);
    });

    // Click the newly inserted link
    await previewFrame.locator('a#test-drupal-link').click();

    await expect(
      page.getByRole('button', { name: 'Open in new window' }),
    ).toBeVisible();
    await expect(page.getByText('Link clicked')).toBeVisible();
    await expect(page.getByText('https://example.com/')).toBeVisible();

    await page.getByRole('button', { name: 'Close' }).click();

    // Test intercepting links by focusing and pressing Enter instead of clicking.
    await previewFrame.locator('a#test-drupal-link').focus();
    await page.keyboard.press('Enter');

    await expect(
      page.getByRole('button', { name: 'Open in new window' }),
    ).toBeVisible();
    await expect(page.getByText('Link clicked')).toBeVisible();
    await expect(page.getByText('https://example.com/')).toBeVisible();

    await page.getByRole('button', { name: 'Close' }).click();
  });

  test('Form submission in the preview should be intercepted', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await page.getByRole('button', { name: 'Preview', exact: true }).click();

    await expect(
      page.frameLocator('iframe[title="Page preview"]').locator('body'),
    ).toBeVisible();

    // Insert a form with a text input and submit button into the preview iframe.
    const iframeElement = await page.$('iframe[title="Page preview"]');
    const previewFrame = await iframeElement.contentFrame();
    if (!previewFrame) throw new Error('Preview iframe not found');
    await previewFrame.evaluate(() => {
      const form = document.createElement('form');
      form.id = 'test-drupal-form';
      form.method = 'post';
      form.action = '/';
      const input = document.createElement('input');
      input.type = 'text';
      input.id = 'test-input';
      input.name = 'test-input';
      form.appendChild(input);
      const button = document.createElement('button');
      button.type = 'submit';
      button.textContent = 'Submit';
      form.appendChild(button);
      document.body.appendChild(form);
    });

    // Type a value into the input and submit the form.
    await previewFrame.locator('input#test-input').fill('test value');
    await previewFrame.locator('button[type="submit"]').click();

    // Modal should be visible with a message to the user about a form submission being intercepted.
    await expect(
      page.getByText(
        'You attempted to submit a form in the preview but it was intercepted before you were navigated away from this page.',
      ),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Close' }).click();
  });
});
