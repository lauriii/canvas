import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

const consoleErrors = [];

test.describe('Components with Children Slots', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas and children slot components',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['canvas', 'canvas_children_slot_component']);
      await page.close();
    },
  );

  test.beforeEach(async ({ drupal, page }) => {
    await drupal.loginAsAdmin();
    page.on('console', async (msg) => {
      if (msg.type() === 'error') {
        const args = msg.args();
        // If the error was logged as an object, get its JSON representation.
        let error = null;
        if (args.length > 0) {
          try {
            error = await args[0].jsonValue();
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
          } catch (e) {
            // If it's not JSON serializable, get the text
            error = await args[0].evaluate((arg) => arg.toString());
          }
          consoleErrors.push(error);
        }
      }
    });
  });

  test.afterEach(async () => {
    consoleErrors.forEach((consoleMessage) => {
      if (
        consoleMessage &&
        typeof consoleMessage === 'object' &&
        'status' in consoleMessage
      ) {
        expect(consoleMessage.status).not.toBe(500);
      }
    });
  });

  test('Can add and edit Hero component that uses Container with children', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.createCanvasPage(
      'Hero with Container',
      '/hero-container-test',
    );
    await page.goto('/hero-container-test');
    await canvasEditor.goToEditor();
    await canvasEditor.openLibraryPanel();

    // Add the Hero component that uses Container internally
    await canvasEditor.addComponent({ name: 'Hero' });

    // Verify the component appears in the preview frame
    const previewFrame = await canvasEditor.getActivePreviewFrame();

    // Verify the Hero component appears in the preview frame
    await expect(previewFrame.locator('.bg-blue-500')).toBeVisible();

    // Verify the Container component (with children slot) is rendered
    await expect(previewFrame.locator('.m-4')).toBeVisible();

    // Verify the Hero component content renders within Container
    await expect(previewFrame.locator('.bg-blue-500')).toBeVisible();
    await expect(previewFrame.locator('h1.text-2xl')).toBeVisible();
    await expect(previewFrame.locator('p.text-gray-500')).toBeVisible();
  });

  test('Can add Container component directly and place components in its children slot', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.createCanvasPage(
      'Container with Direct Children',
      '/container-direct-test',
    );
    await page.goto('/container-direct-test');
    await canvasEditor.goToEditor();
    await canvasEditor.openLibraryPanel();

    // Add Container component directly to the page (no props, so no form)
    await canvasEditor.addComponent(
      { name: 'Container' },
      { hasInputs: false },
    );

    // Add Plain text component to the page
    await canvasEditor.addComponent({ name: 'Plain text' });

    await canvasEditor.openLayersPanel();

    // Move Plain text into Container's children slot via layers panel
    await canvasEditor.moveComponent('Plain text', 'children');

    // Verify the components render correctly
    const previewFrame = await canvasEditor.getActivePreviewFrame();

    // Verify Container component is rendered
    await expect(previewFrame.locator('.m-4')).toBeVisible();

    // Verify Plain text component is rendered within the Container
    // Plain text should render as a simple text element inside Container
    await expect(
      previewFrame.locator('.m-4').getByText('Plain text', { exact: false }),
    ).toBeVisible();
  });
});
