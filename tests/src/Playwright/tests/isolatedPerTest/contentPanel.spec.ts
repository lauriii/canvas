import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({
  enableTestExtensions: true,
});

// The Content panel lists templated entities, grouped by content type. Its
// menu icon is always shown; without templated content it shows an empty state.
test.describe('Content panel', () => {
  test('icon is always available and the panel shows an empty state without templated content', async ({
    drupal,
    canvas,
    page,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();

    // The Content icon is shown even when no bundle has exposed slots.
    const contentButton = page
      .getByTestId('canvas-side-menu')
      .getByLabel('Content');
    await expect(contentButton).toBeVisible();

    await canvas.openContentPanel();

    // With no templated content the panel offers its search field and an empty
    // state, not any content-type groups.
    await expect(
      page.getByRole('textbox', { name: 'Search content' }),
    ).toBeVisible();
    await expect(page.getByText('No content found')).toBeVisible();
  });

  // The article_exposed_slots fixture recipe: a templated Article bundle
  // (active exposed slot) with three seeded nodes, opened in the Content panel
  // as the editor.
  test.describe('with templated content', () => {
    test.beforeEach(async ({ drupal, canvas }) => {
      await drupal.loginAsAdmin();
      await drupal.applyRecipe(
        `modules/contrib/canvas/tests/fixtures/recipes/article_exposed_slots`,
      );
      await drupal.addPermissions({
        role: 'editor',
        permissions: ['edit any article content'],
      });
      await drupal.logout();

      await drupal.login({ username: 'editor', password: 'editor' });
      await canvas.createCanvas();
      await canvas.openContentPanel();
    });

    // The grouped-content flow.
    test('groups templated content with search, collapse, and row navigation', async ({
      canvas,
      page,
    }) => {
      // The templated Article bundle renders as a content-type group holding
      // the seeded entities.
      const group = page.getByTestId('canvas-templated-content-node');
      await expect(group).toBeVisible();
      await expect(group.getByText('Templated alpha')).toBeVisible();
      await expect(group.getByText('Templated beta')).toBeVisible();
      await expect(group.getByText('Another gamma')).toBeVisible();

      // The folder header collapses and expands its rows.
      const folderTrigger = group.getByRole('button', { name: 'Article' });
      await folderTrigger.click();
      await expect(group.getByText('Templated alpha')).toBeHidden();
      await folderTrigger.click();
      await expect(group.getByText('Templated alpha')).toBeVisible();

      // The shared search field filters every group's rows.
      const search = page.getByRole('textbox', { name: 'Search content' });
      await search.fill('alpha');
      await expect(group.getByText('Templated alpha')).toBeVisible();
      await expect(group.getByText('Templated beta')).toHaveCount(0);
      await search.clear();
      await expect(group.getByText('Templated beta')).toBeVisible();

      // Selecting a row opens the entity in the per-content editor.
      await group.getByText('Templated alpha').click();
      await expect(page).toHaveURL(/\/editor\/node\/\d+/);
    });

    // A component in an exposed slot is selectable from the preview, not only
    // from the Layers panel, and an edit to it publishes as the entity's slot
    // override. The fixture's "Templated beta" node has a heading in its Main
    // content slot.
    test('selects, edits and publishes a component in an exposed slot', async ({
      canvas,
      page,
    }) => {
      await page
        .getByTestId('canvas-templated-content-node')
        .getByText('Templated beta')
        .click();
      await expect(page).toHaveURL(/\/editor\/node\/\d+/);
      await canvas.waitForEditorFrame();

      await canvas.clickPreviewComponent('sdc.canvas_test_sdc.heading');
      await expect(page).toHaveURL(
        /\/component\/6b0e2f4a-8c1d-4e3f-9a5b-7c6d5e4f3a2b/,
      );

      // The edit is saved to the entity's layout, then published.
      const propSaved = page.waitForResponse(
        (r) =>
          r.url().includes('/canvas/api/v0/layout/node/') &&
          r.request().method() === 'PATCH' &&
          r.status() === 200,
      );
      await canvas.editComponentProp('text', 'Published slot heading');
      await propSaved;
      await expect(
        (await canvas.getActivePreviewFrame()).getByText(
          'Published slot heading',
        ),
      ).toBeVisible();
      await canvas.publishAllChanges(['Templated beta']);

      // The front end renders the published slot override.
      await page.goto('/templated-beta');
      await expect(page.getByText('Published slot heading')).toBeVisible();
      await expect(page.getByText('Beta slot heading')).toHaveCount(0);
    });

    // Each exposed slot is its own drop target. The fixture template exposes a
    // second, empty Footer content slot after Main content.
    test('drops a component into the second exposed slot', async ({
      canvas,
      page,
    }) => {
      await page
        .getByTestId('canvas-templated-content-node')
        .getByText('Templated beta')
        .click();
      await expect(page).toHaveURL(/\/editor\/node\/\d+/);
      await canvas.waitForEditorFrame();

      const mainSlotItems = page.locator(
        '[data-canvas-drop-container="canvas_slot_main"] > [data-canvas-drop-item]',
      );
      const footerSlotItems = page.locator(
        '[data-canvas-drop-container="canvas_slot_footer"] > [data-canvas-drop-item]',
      );
      await expect(mainSlotItems).toHaveCount(1);
      await expect(footerSlotItems).toHaveCount(0);

      // Layers labels each slot's state. Footer content has an empty template
      // default, so there is nothing to override.
      await canvas.openLayersPanel();
      await expect(
        page.getByTestId('slot-layer-overridden-marker-canvas_slot_main'),
      ).toBeVisible();
      await expect(
        page.getByTestId('slot-layer-empty-marker-canvas_slot_footer'),
      ).toBeVisible();

      await canvas.openLibraryPanel();
      const layoutSaved = page.waitForResponse(
        (r) =>
          r.url().includes('/canvas/api/v0/layout/node/') &&
          r.request().method() === 'POST' &&
          r.status() === 200,
      );
      await canvas.drag(
        page
          .getByTestId('canvas-primary-panel')
          .locator(
            '[data-canvas-type="component"][data-canvas-component-id="sdc.canvas_test_sdc.heading"]',
          ),
        '[data-testid="canvas-empty-region-drop-zone-footer-content"]',
      );
      await layoutSaved;

      // The component lands in Footer content, not in Main content.
      await expect(footerSlotItems).toHaveCount(1);
      await expect(mainSlotItems).toHaveCount(1);
      await canvas.openLayersPanel();
      await expect(
        page.getByTestId('slot-layer-overridden-marker-canvas_slot_footer'),
      ).toBeVisible();
    });

    // An inherited slot shows the template's default content. Override forks an
    // editable copy, and Revert to default restores the template's content. The
    // fixture's "Templated alpha" node has an empty Main content slot field, so
    // it inherits the template's default heading.
    test('overrides, edits and reverts an inherited exposed slot', async ({
      canvas,
      page,
    }) => {
      await page
        .getByTestId('canvas-templated-content-node')
        .getByText('Templated alpha')
        .click();
      await expect(page).toHaveURL(/\/editor\/node\/\d+/);
      await canvas.waitForEditorFrame();
      await expect(
        (await canvas.getActivePreviewFrame()).getByText(
          'Template default heading',
        ),
      ).toBeVisible();

      // The inherited slot is one selectable unit. Its contextual panel explains
      // the default, and Insert from the Library is disabled until overridden.
      await page.getByTestId('slot-inherited-canvas_slot_main').click();
      await expect(
        page.getByTestId('canvas-inherited-slot-panel'),
      ).toBeVisible();
      await canvas.openLibraryPanel();
      const libraryItem = page
        .getByTestId('canvas-primary-panel')
        .locator(
          '[data-canvas-type="component"][data-canvas-component-id="sdc.canvas_test_sdc.heading"]',
        );
      await libraryItem.hover();
      await libraryItem.getByLabel('Open contextual menu').click();
      await expect(
        page.getByRole('menuitem', {
          name: 'Insert (override the slot first)',
        }),
      ).toBeDisabled();
      await page.keyboard.press('Escape');

      // Override forks the default and selects the forked heading.
      await page.getByTestId('canvas-inherited-slot-override').click();
      await expect(
        page.getByTestId('slot-inherited-canvas_slot_main'),
      ).toHaveCount(0);
      await expect(page).toHaveURL(/\/component\/[0-9a-f-]{36}/);
      // The fork has fresh instance identity, not the template's UUID.
      await expect(page).not.toHaveURL(/0d4c6b1e-5f3a-4b2c-9e8d-1a2b3c4d5e6f/);

      const propSaved = page.waitForResponse(
        (r) =>
          r.url().includes('/canvas/api/v0/layout/node/') &&
          r.request().method() === 'PATCH' &&
          r.status() === 200,
      );
      await canvas.editComponentProp('text', 'Overridden slot heading');
      await propSaved;
      const preview = await canvas.getActivePreviewFrame();
      await expect(preview.getByText('Overridden slot heading')).toBeVisible();
      await expect(preview.getByText('Template default heading')).toHaveCount(
        0,
      );

      // Revert to default, from the slot's row in the Layers panel, discards the
      // override and shows the template's default content again.
      // A layout structure change is saved with a POST of the whole layout (the
      // PATCH above is for a single component's props).
      await canvas.openLayersPanel();
      const revertSaved = page.waitForResponse(
        (r) =>
          r.url().includes('/canvas/api/v0/layout/node/') &&
          r.request().method() === 'POST' &&
          r.status() === 200,
      );
      await page
        .getByTestId('canvas-primary-panel')
        .getByText('Main content', { exact: true })
        .click({ button: 'right' });
      await page.getByTestId('slot-layer-revert-canvas_slot_main').click();
      await revertSaved;
      await expect(
        page.getByTestId('slot-inherited-canvas_slot_main'),
      ).toBeVisible();
      await expect(
        page.getByTestId('slot-layer-inherited-marker-canvas_slot_main'),
      ).toBeVisible();
      const revertedPreview = await canvas.getActivePreviewFrame();
      await expect(
        revertedPreview.getByText('Template default heading'),
      ).toBeVisible();
      await expect(
        revertedPreview.getByText('Overridden slot heading'),
      ).toHaveCount(0);
    });
  });

  // @todo Cover the Content tab and the reduced Page data form of a templated
  //   entity. EntityFormControllerTest covers the form on the server.
});
