import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.describe('Components with Children Slots', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules(['canvas_children_slot_component']);
    await drupal.logout();
  });

  test('Can add and edit Hero component that uses Container with children', async ({
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    // Add the Hero component that uses Container internally
    await canvas.addComponent({ name: 'Hero' });
    await canvas.waitForContextualPanel();

    // Verify the component appears in the preview frame
    const previewFrame = await canvas.getActivePreviewFrame();

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
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    // Add Container component directly to the page (no props, so no form)
    await canvas.addComponent({ name: 'Container' }, { hasInputs: false });

    // Add Plain text component to the page
    await canvas.addComponent({ name: 'Plain text' });

    await canvas.openLayersPanel();

    // Move Plain text into Container's children slot via layers panel
    await canvas.moveComponent('Plain text', 'children');

    // Verify the components render correctly
    const previewFrame = await canvas.getActivePreviewFrame();

    // Verify Container component is rendered
    await expect(previewFrame.locator('.m-4')).toBeVisible();

    // Verify Plain text component is rendered within the Container
    // Plain text should render as a simple text element inside Container
    await expect(
      previewFrame.locator('.m-4').getByText('Plain text', { exact: false }),
    ).toBeVisible();
  });
});
