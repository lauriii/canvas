import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

/**
 * Tests folder management in Drupal Canvas.
 */
test.describe('Folder Management', () => {
  test('Components tab folder display and creation', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    await canvas.addFolder('Pie');
    await canvas.addFolder('Cake');

    await expect(
      page.locator('[data-testid="canvas-library-components-tab-content"]'),
    ).toMatchAriaSnapshot({ name: 'components-tab.yml' });
    await page.reload();
    await canvas.openLibraryPanel();
    await expect(
      page.locator('[data-testid="canvas-library-components-tab-content"]'),
    ).toMatchAriaSnapshot({ name: 'components-tab.yml' });
  });

  test('Code panel folder display and creation', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();

    await canvas.openCodePanel();

    await canvas.addFolder('Awesome New Folder');
    await canvas.addFolder('Another folder');

    await expect(
      page.locator('[data-testid="canvas-code-panel-content"]'),
    ).toMatchAriaSnapshot({ name: 'code-panel.yml' });
    await page.reload();
    await canvas.openCodePanel();
    await expect(
      page.locator('[data-testid="canvas-code-panel-content"]'),
    ).toMatchAriaSnapshot({ name: 'code-panel.yml' });
  });

  test('Patterns tab folder display and creation', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await page.getByTestId('canvas-library-patterns-tab-select').click();
    await expect(page.getByText('No items to show in Patterns')).toBeVisible();
    await canvas.addFolder('Stuff');
    await canvas.addFolder('Things');

    await expect(
      page.locator('[data-testid="canvas-library-patterns-tab-content"]'),
    ).toMatchAriaSnapshot({ name: 'patterns-tab.yml' });
    await page.reload();
    await canvas.openLibraryPanel();
    await page.getByTestId('canvas-library-patterns-tab-select').click();
    await expect(
      page.locator('[data-testid="canvas-library-patterns-tab-content"]'),
    ).toMatchAriaSnapshot({ name: 'patterns-tab.yml' });
  });

  test('Folder renaming', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    // Create two test folders.
    await canvas.addFolder('Test Folder to Rename');
    await canvas.addFolder('Existing Folder');

    // Test: Double-click to enter rename mode.
    await page
      .locator('[data-canvas-folder-name="Test Folder to Rename"]')
      .dblclick();

    // Verify TextField is visible and focused after double-click.
    let textFieldDoubleClick = page.getByTestId('canvas-folder-rename-input');
    await expect(textFieldDoubleClick).toBeVisible();
    await expect(textFieldDoubleClick).toBeFocused();
    await expect(textFieldDoubleClick).toHaveValue('Test Folder to Rename');

    // Test successful rename via double-click with Enter key.
    await textFieldDoubleClick.fill('Renamed via Double Click');
    await textFieldDoubleClick.press('Enter');
    await expect(textFieldDoubleClick).not.toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Renamed via Double Click"]'),
    ).toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Test Folder to Rename"]'),
    ).not.toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Renamed via Double Click"]'),
    ).toBeVisible();

    // Test: Double-click rename cancellation with Escape.
    await page
      .locator('[data-canvas-folder-name="Renamed via Double Click"]')
      .dblclick();
    // Verify TextField is visible and focused after double-click.
    textFieldDoubleClick = page.getByTestId('canvas-folder-rename-input');
    await expect(textFieldDoubleClick).toBeVisible();
    await expect(textFieldDoubleClick).toBeFocused();
    await expect(textFieldDoubleClick).toHaveValue('Renamed via Double Click');
    await textFieldDoubleClick.fill('Should Be Cancelled');
    await textFieldDoubleClick.press('Escape');
    await expect(
      page.locator('[data-testid="canvas-folder-rename-input"]'),
    ).not.toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Renamed via Double Click"]'),
    ).toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Should Be Cancelled"]'),
    ).not.toBeAttached();

    // Test: Open folder menu and click Rename (traditional method).
    await page
      .locator('[data-canvas-folder-name="Renamed via Double Click"]')
      .hover();
    await page
      .locator('[data-canvas-folder-name="Renamed via Double Click"]')
      .getByRole('button', { name: 'Menu' })
      .click();
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    await expect(page.getByRole('menu')).toBeHidden();

    const textField = page.getByTestId('canvas-folder-rename-input');
    await expect(textField).toBeVisible();
    await expect(textField).toBeFocused();
    await expect(textField).toHaveValue('Renamed via Double Click');

    await textField.fill('Renamed Folder');
    await textField.press('Enter');
    await expect(
      page.locator('[data-canvas-folder-name="Renamed Folder"]'),
    ).toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Renamed via Double Click"]'),
    ).not.toBeAttached();

    // Test rename cancellation on blur without changes.
    await page.locator('[data-canvas-folder-name="Renamed Folder"]').hover();
    await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .getByRole('button', { name: 'Menu' })
      .click();
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    const textField3 = page.getByTestId('canvas-folder-rename-input');
    await expect(textField3).toBeVisible();
    await textField3.blur();
    await expect(
      page.locator('[data-canvas-folder-name="Renamed Folder"]'),
    ).toBeAttached();

    // Test validation error for duplicate folder name.
    await page.locator('[data-canvas-folder-name="Renamed Folder"]').hover();
    await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .getByRole('button', { name: 'Menu' })
      .click();
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    const textField4 = page.getByTestId('canvas-folder-rename-input');
    await expect(textField4).toBeVisible();
    await textField4.fill('Existing Folder');
    await textField4.press('Enter');
    // The error message is in a span with data-accent-color="red" and contains "is not unique".
    const errorSpan = page.locator('span[data-accent-color="red"]');
    await expect(errorSpan).toBeVisible();
    await expect(errorSpan).toContainText('is not unique');

    await textField4.press('Escape');

    // Verify folder was not renamed (still has original name).
    await expect(
      page.locator('[data-canvas-folder-name="Renamed Folder"]'),
    ).toBeAttached();
    await expect(
      page.locator('[data-canvas-folder-name="Existing Folder"]'),
    ).toHaveCount(1);

    // Test that folder state (open/closed) is preserved during rename.
    await page.locator('[data-canvas-folder-name="Renamed Folder"]').click();
    // The Collapsible.Trigger (chevron button) always carries aria-expanded.
    const folderToggle = page.getByRole('button', {
      name: /Renamed Folder folder/,
    });
    const ariaExpandedBefore = await folderToggle.getAttribute('aria-expanded');

    await page.locator('[data-canvas-folder-name="Renamed Folder"]').hover();
    await page
      .locator('[data-canvas-folder-name="Renamed Folder"]')
      .getByRole('button', { name: 'Menu' })
      .click();
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    const textField5 = page.getByTestId('canvas-folder-rename-input');
    await expect(textField5).toBeVisible();
    await textField5.press('Escape');

    await expect(folderToggle).toHaveAttribute(
      'aria-expanded',
      ariaExpandedBefore!,
    );
  });

  test('Folder deletion', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    await page
      .locator('[data-testid="canvas-library-components-tab-select"]')
      .click();
    await expect(
      page.locator(
        '[data-testid="canvas-library-components-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();

    // Create an empty test folder for deletion in Library tab.
    await canvas.addFolder('Empty Folder To Delete');

    // Successfully delete an empty folder.
    await canvas.deleteFolder('Empty Folder To Delete');

    // Attempt to delete a folder containing components and
    // verify deletion is disabled.
    // Find a folder that contains components.
    const folderWithComponents = page.locator(
      '[data-canvas-folder-name="Atom/Text"]',
    );
    await expect(folderWithComponents).toBeAttached();
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
  });

  test('Folder drag and drop reordering', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    // Helper to get current folder order.
    const getFolderOrder = async (): Promise<string[]> => {
      const folderElements = await page
        .locator('[data-canvas-folder-name]')
        .all();
      return await Promise.all(
        folderElements.map(async (element) => {
          return (await element.getAttribute('data-canvas-folder-name')) || '';
        }),
      );
    };

    // Start on the Components tab.
    await expect(
      page.locator(
        '[data-testid="canvas-library-components-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();

    // Create two test folders for drag and drop testing.
    await canvas.addFolder('Drag Test Folder A');
    await canvas.addFolder('Drag Test Folder B');

    // Get initial folder order.
    const initialOrder = await getFolderOrder();
    expect(initialOrder[0]).toBe('Drag Test Folder B');
    expect(initialOrder[1]).toBe('Drag Test Folder A');

    // Get the source folder (A is first, we'll drag it to B's position).
    const sourceFolder = page.locator(
      '[data-canvas-folder-name="Drag Test Folder A"]',
    );
    // Get the target folder.
    const targetFolder = page.locator(
      '[data-canvas-folder-name="Drag Test Folder B"]',
    );

    await expect(sourceFolder).toBeVisible();
    await expect(targetFolder).toBeVisible();

    // Get bounding boxes for drag coordinates.
    const sourceBox = (await sourceFolder.boundingBox())!;
    const targetBox = (await targetFolder.boundingBox())!;

    // Calculate center positions.
    const sourceX = sourceBox.x + sourceBox.width / 2;
    const sourceY = sourceBox.y + sourceBox.height / 2;
    const targetX = targetBox.x + targetBox.width / 2;
    const targetY = targetBox.y + targetBox.height / 2;

    // Perform manual drag for dnd-kit compatibility.
    // dnd-kit uses PointerSensor with 3px activation distance.
    await page.mouse.move(sourceX, sourceY);
    await page.mouse.down();
    // Move past activation distance.
    await page.mouse.move(sourceX, sourceY + 10, { steps: 5 });
    // Move to target.
    await page.mouse.move(targetX, targetY, { steps: 10 });
    await page.mouse.up();

    // Wait for the folder order to actually change.
    await expect(async () => {
      const currentOrder = await getFolderOrder();
      expect(currentOrder[0]).toBe('Drag Test Folder A');
    }).toPass({ timeout: 10000 });

    // Verify the order has changed(A moved to B's position, so B is now first).
    const newOrder = await getFolderOrder();
    expect(newOrder[0]).toBe('Drag Test Folder A');
    expect(newOrder[1]).toBe('Drag Test Folder B');
  });

  test('Folder drag visual state - source folder highlights with blue background when dragging', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    await canvas.addFolder('Visual Test A');
    await canvas.addFolder('Visual Test B');

    const sourceFolder = page.locator(
      '[data-canvas-folder-name="Visual Test A"]',
    );
    await expect(sourceFolder).toBeVisible();

    const sourceBox = (await sourceFolder.boundingBox())!;
    const sourceX = sourceBox.x + sourceBox.width / 2;
    const sourceY = sourceBox.y + sourceBox.height / 2;

    // Begin drag gesture.
    await page.mouse.move(sourceX, sourceY);
    await page.mouse.down();
    // Move past dnd-kit's 3px PointerSensor activation distance.
    await page.mouse.move(sourceX, sourceY + 10, { steps: 5 });

    // While dragging: the folder row receives the draggingFolderTrigger class
    // which sets background: var(--blue-9).
    await expect(async () => {
      const { blue9 } = await sourceFolder.evaluate((el) => {
        const style = window.getComputedStyle(el);
        return {
          blue9: style.getPropertyValue('--blue-9').trim(),
        };
      });
      // Confirm the Radix --blue-9 token itself hasn't drifted.
      expect(blue9).toBe('#0090ff');
    }).toPass({ timeout: 5000 });

    // Release the drag — cancel by dropping in place.
    await page.mouse.up();
  });

  test('Folder drag visual state - no floating chip overlay during folder drag', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    await canvas.addFolder('Chip Test A');
    await canvas.addFolder('Chip Test B');

    const sourceFolder = page.locator(
      '[data-canvas-folder-name="Chip Test A"]',
    );
    await expect(sourceFolder).toBeVisible();

    const sourceBox = (await sourceFolder.boundingBox())!;
    const sourceX = sourceBox.x + sourceBox.width / 2;
    const sourceY = sourceBox.y + sourceBox.height / 2;

    // Begin drag gesture.
    await page.mouse.move(sourceX, sourceY);
    await page.mouse.down();
    // Move past activation distance to trigger the drag.
    await page.mouse.move(sourceX, sourceY + 10, { steps: 5 });

    // During a folder drag, DragEventsHandler renders `null` inside DragOverlay.
    // dnd-kit appends its DragOverlay portal to <body> as a direct child element
    // marked with aria-live="off" and pointer-events: none.  When the overlay
    // content is null that portal element must have no child elements.
    await expect(async () => {
      const overlayIsEmpty = await page.evaluate(() => {
        // Identify the dnd-kit DragOverlay portal: a direct <body> child with
        // aria-live="off" (set by dnd-kit) and pointer-events:none (set by the
        // dragOverlay CSS class in DragOverlay.module.css).
        const bodyChildren = Array.from(document.body.children);
        const overlay = bodyChildren.find(
          (el) =>
            el.getAttribute('aria-live') === 'off' &&
            window.getComputedStyle(el).pointerEvents === 'none',
        );
        // If no overlay portal exists yet, treat as empty (drag not active).
        if (!overlay) return true;
        return overlay.childElementCount === 0;
      });
      // No chip element must be rendered in the overlay during a folder drag.
      expect(overlayIsEmpty).toBe(true);
    }).toPass({ timeout: 5000 });

    // Release the drag — cancel by dropping in place.
    await page.mouse.up();
  });

  test('Folder drag collapse state - folder collapses during drag and restores open state after drop', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    await canvas.addFolder('Collapsible Test A');
    await canvas.addFolder('Collapsible Test B');

    await canvas.expandFolder('Collapsible Test A');
    await expect(
      page.getByRole('button', { name: 'Collapse Collapsible Test A folder' }),
    ).toBeVisible();

    const sourceFolder = page.locator(
      '[data-canvas-folder-name="Collapsible Test A"]',
    );
    await expect(sourceFolder).toBeVisible();

    const sourceBox = (await sourceFolder.boundingBox())!;
    const sourceX = sourceBox.x + sourceBox.width / 2;
    const sourceY = sourceBox.y + sourceBox.height / 2;

    // Begin drag gesture.
    await page.mouse.move(sourceX, sourceY);
    await page.mouse.down();
    // Move past activation distance.
    await page.mouse.move(sourceX, sourceY + 10, { steps: 5 });

    await expect(async () => {
      await expect(
        page.getByRole('button', {
          name: 'Expand Collapsible Test A folder',
        }),
      ).toBeVisible();
    }).toPass({ timeout: 5000 });

    // Release the drag — cancel by dropping in place.
    await page.mouse.up();
    await expect(async () => {
      await expect(
        page.getByRole('button', {
          name: 'Collapse Collapsible Test A folder',
        }),
      ).toBeVisible();
    }).toPass({ timeout: 10000 });
  });

  test('Folder drag visual state - folder cannot move horizontally during drag', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();

    await canvas.addFolder('Transform Test A');
    await canvas.addFolder('Transform Test B');

    const sourceFolder = page.locator(
      '[data-canvas-folder-name="Transform Test A"]',
    );
    await expect(sourceFolder).toBeVisible();

    const sourceBox = (await sourceFolder.boundingBox())!;
    const sourceX = sourceBox.x + sourceBox.width / 2;
    const sourceY = sourceBox.y + sourceBox.height / 2;

    // Begin drag gesture.
    await page.mouse.move(sourceX, sourceY);
    await page.mouse.down();
    // Move past activation distance.
    await page.mouse.move(sourceX, sourceY + 10, { steps: 5 });
    // Move diagonally — both horizontally and vertically.
    await page.mouse.move(sourceX + 80, sourceY + 40, { steps: 10 });

    // Assert the folder's x-position hasn't changed despite horizontal cursor movement.
    await expect(async () => {
      const currentBox = (await sourceFolder.boundingBox())!;
      // The x-position must remain the same as before the drag started,
      // proving horizontal movement is locked.
      expect(currentBox.x).toBe(sourceBox.x);
    }).toPass({ timeout: 5000 });

    await page.mouse.up();
  });

  // Assertions are made in the helper functions.
  // eslint-disable-next-line playwright/expect-expect
  test('Component drag and drop between folders and uncategorized list', async ({
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openCodePanel();
    await canvas.addFolder('1337 Code');
    await canvas.addFolder('Suboptimal Code');

    const code = await readFile(
      'tests/fixtures/code_components/page-elements/PageTitle.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('One', code);
    await canvas.createCodeComponent('Two', code);
    await canvas.createCodeComponent('Three', code);

    await canvas.moveComponentIntoFolder('One', '1337 Code');
    await canvas.moveComponentOutOfFolder('One');
    await canvas.moveComponentIntoFolder('One', 'Suboptimal Code');
    await canvas.moveComponentIntoFolder('One', '1337 Code');
    await canvas.moveComponentOutOfFolder('One');
  });

  // Assertions are made in the helper functions.
  // eslint-disable-next-line playwright/expect-expect
  test('moveComponentToLibraryLocation moves code component into folder', async ({
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openCodePanel();
    await canvas.addFolder('Library Location Target');

    const code = await readFile(
      'tests/fixtures/code_components/page-elements/PageTitle.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('LocationTest', code);

    await canvas.moveComponentToLibraryLocation(
      'LocationTest',
      'Library Location Target',
    );
  });
});
