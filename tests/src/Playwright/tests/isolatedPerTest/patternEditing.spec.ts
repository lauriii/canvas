import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

/**
 * Tests editing a stored Pattern on the canvas.
 *
 * A Pattern can be renamed from the library (via a modal, like code
 * components), and re-opened on the canvas to edit its component tree and
 * component props through the draft + Publish cycle. Retroactively updating
 * already-placed instances is out of scope.
 */
test.describe('Pattern editing', () => {
  test('rename a pattern, then edit a component prop and publish', async ({
    page,
    drupal,
    canvas,
  }) => {
    const patternName = 'My reusable pattern';
    const renamedPattern = 'Renamed reusable pattern';
    const componentId = 'sdc.canvas_test_sdc.my-hero';
    const editedHeading = 'Heading edited in the pattern editor';

    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());

    // Place a component, then save it as a pattern.
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: componentId });

    await canvas.openLayersPanel();
    await page
      .getByTestId('canvas-primary-panel')
      .getByText('Hero')
      .click({ button: 'right' });
    await page
      .getByRole('menu', { name: 'Context menu for Hero' })
      .getByText('Create pattern')
      .click();

    const patternNameDialogInput = page.locator('#patternName');
    await expect(patternNameDialogInput).toBeVisible();
    await patternNameDialogInput.fill(patternName);
    await page.getByRole('button', { name: 'Add to library' }).click();
    await expect(page.getByText('Add new pattern')).toBeHidden();

    // Open the Patterns tab in the library.
    await canvas.openLibraryPanel();
    await page.getByTestId('canvas-library-patterns-tab-select').click();
    const patternsTab = page.getByTestId('canvas-library-patterns-tab-content');
    await expect(patternsTab.getByText(patternName)).toBeVisible();

    // Rename the pattern via the modal (immediate; no publish needed).
    await patternsTab.getByText(patternName).click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Rename' }).click();
    const renameInput = page.locator('#patternRenameInput');
    await expect(renameInput).toBeVisible();
    await expect(renameInput).toHaveValue(patternName);
    await renameInput.fill(renamedPattern);
    await page.getByRole('button', { name: 'Rename' }).click();
    // The library reflects the new name.
    await expect(patternsTab.getByText(renamedPattern)).toBeVisible();
    await expect(patternsTab.getByText(patternName)).toHaveCount(0);

    // Open the renamed pattern in the editor.
    await patternsTab.getByText(renamedPattern).click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Edit pattern' }).click();
    await expect(page).toHaveURL(/\/canvas\/pattern\//);
    // The top bar shows the (renamed) pattern name, and the tree is loaded.
    await expect(page.getByTestId('canvas-navigation-button')).toContainText(
      renamedPattern,
    );
    await expect(
      page.locator(
        `#canvasPreviewOverlay [data-canvas-component-id="${componentId}"]`,
      ),
    ).toBeVisible();

    // Selecting the component must render its settings form (this regressed:
    // the form errored with "unknown type: pattern"). Then edit a prop and
    // wait for its draft save (PATCH) to land.
    await canvas.clickPreviewComponent(componentId);
    await expect(
      page.locator('form[data-form-id="component_instance_form"]'),
    ).toBeVisible();
    const propSaved = page.waitForResponse(
      (r) =>
        r.url().includes('/layout-pattern/') &&
        r.request().method() === 'PATCH' &&
        r.status() === 200,
    );
    await canvas.editComponentProp('heading', editedHeading);
    await propSaved;

    // Publish the draft, then reload and confirm the prop edit persisted and
    // the pattern is still renamed.
    await canvas.publishAllChanges();
    await page.reload();
    await expect(page.getByTestId('canvas-navigation-button')).toContainText(
      renamedPattern,
    );
    await canvas.clickPreviewComponent(componentId);
    await expect(
      page.locator(
        'form[data-form-id="component_instance_form"] .field--name-heading input',
      ),
    ).toHaveValue(editedHeading);
  });
});
