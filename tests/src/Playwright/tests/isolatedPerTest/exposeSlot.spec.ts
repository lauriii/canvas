/**
 * Expose-slot dialog coverage, migrated from the (deprecated) Cypress unit spec
 * ui/tests/unit/exposed-slots.cy.jsx (@see [[Playwright not Cypress]]).
 *
 * The first test (add-new path + detach) is self-contained on the existing
 * `article_translation` recipe and runs. The second test ("defaults to reusing
 * an existing slot field") stays test.fixme: it needs a fixture that does not
 * exist yet.
 *
 * FIXTURE NEEDED:
 *   A recipe that seeds, on the Article bundle (in addition to its Full content
 *   content template + preview node from `article_translation`):
 *     1. At least one pre-existing `canvas_slot_*` component_tree field, ideally
 *        already holding content on one or two Article nodes, so the expose
 *        dialog's single "Slot field" Select has a candidate, defaults to reusing
 *        it (content-first), and renders the "N with content" hint.
 *     2. (Optional) a second `canvas_slot_*` field defined only on another
 *        bundle sharing storage, to cover the cross-bundle "add it here too"
 *        message and the createSlotField attach path.
 *   Suggested location:
 *     modules/contrib/canvas/tests/fixtures/recipes/article_exposed_slots
 *   Wire it into the beforeEach where noted, then remove the add-new-only
 *   assumption from the first test's dialog default.
 *
 * @see ui/src/features/layout/exposeSlot/ExposeSlotDialog.tsx
 * @see ui/src/features/layout/preview/SlotContextMenu.tsx
 * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController
 */
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({
  modules: ['canvas_test_sdc'],
  enableTestExtensions: true,
});

test.describe('Expose slot dialog', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    // Article bundle + Full content content template + a preview node.
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/article_translation`,
    );
    await drupal.installModules(['canvas_test_article_fields']);
    // TODO once the fixture exists, also apply the exposed-slots recipe so the
    // "reuse existing" test below has a candidate slot field:
    // await drupal.applyRecipe(
    //   `modules/contrib/canvas/tests/fixtures/recipes/article_exposed_slots`,
    // );
    await drupal.addPermissions({
      role: 'editor',
      permissions: ['edit any article content'],
    });
    await drupal.logout();
  });

  // Build an Article "Full content" template that hosts a component with a slot,
  // then open the Layers panel. The two_column component provides an editable
  // slot to expose.
  const openTemplateWithSlot = async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvasRoot();
    await canvas.openTemplatesPanel();
    await canvas.addTemplate('Article', 'Full content');
    await page.getByTestId('template-list-item-article-Full content').click();
    expect(page.url()).toContain('canvas/template/node/article/full');
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.two_column' });
    await canvas.openLayersPanel();
    // A slot row in the Layers panel; its outer box carries data-canvas-type.
    // TODO if the fixture uses a different slotted component, update the id above.
    return page
      .getByTestId('canvas-primary-panel')
      .locator('[data-canvas-type="slot"]')
      .first();
  };

  test('add-new path creates a canvas_slot_ field, then Detach removes it', async ({
    page,
    drupal,
    canvas,
  }) => {
    const slotRow = await openTemplateWithSlot({ page, drupal, canvas });

    // Right-click the slot to open its context menu, then choose "Expose slot".
    await slotRow.click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Expose slot' }).click();

    const dialog = page.getByRole('dialog').filter({
      has: page.getByRole('heading', { name: 'Expose slot' }),
    });
    await expect(dialog).toBeVisible();

    // With no pre-existing canvas_slot_ fields on this bundle the single
    // "Slot field" Select has no candidates and falls back to "Add new slot…",
    // which reveals the label + machine-name fields.
    await expect(dialog.getByLabel('Slot field')).toContainText(
      'Add new slot…',
    );

    // Typing the label auto-derives the canvas_slot_-prefixed machine name.
    await dialog.getByLabel('Slot name').fill('My hero');
    await expect(dialog.getByLabel('Machine name')).toHaveValue(
      'canvas_slot_my_hero',
    );

    await dialog.getByRole('button', { name: 'Expose slot' }).click();
    await expect(dialog).toBeHidden();

    // The slot is now exposed: its context menu offers Edit label + Detach
    // instead of Expose slot.
    await slotRow.click({ button: 'right' });
    await expect(
      page.getByRole('menuitem', { name: 'Edit label' }),
    ).toBeVisible();
    await page.getByRole('menuitem', { name: 'Detach' }).click();

    const detachDialog = page.getByRole('dialog').filter({
      has: page.getByRole('heading', { name: 'Detach exposed slot' }),
    });
    await expect(detachDialog).toBeVisible();
    await detachDialog.getByRole('button', { name: 'Detach' }).click();
    await expect(detachDialog).toBeHidden();

    // Detached: the context menu offers "Expose slot" again.
    await slotRow.click({ button: 'right' });
    await expect(
      page.getByRole('menuitem', { name: 'Expose slot' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
  });

  test.fixme('defaults to reusing an existing slot field', async ({
    page,
    drupal,
    canvas,
  }) => {
    // TODO this test requires the article_exposed_slots fixture described in the
    // file header (a pre-existing canvas_slot_* field on the Article bundle).
    // Until it is applied in beforeEach the Select has no candidates and this
    // test fails (the job is allow_failure).
    const slotRow = await openTemplateWithSlot({ page, drupal, canvas });

    await slotRow.click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Expose slot' }).click();

    const dialog = page.getByRole('dialog').filter({
      has: page.getByRole('heading', { name: 'Expose slot' }),
    });
    await expect(dialog).toBeVisible();

    // The single "Slot field" Select defaults to the content-first existing
    // candidate (NOT "Add new slot…"), and the label/machine-name create fields
    // stay hidden while reusing an existing field.
    const slotFieldSelect = dialog.getByLabel('Slot field');
    await expect(slotFieldSelect).toContainText('canvas_slot_');
    await expect(slotFieldSelect).not.toContainText('Add new slot…');
    await expect(dialog.getByLabel('Machine name')).toHaveCount(0);

    await dialog.getByRole('button', { name: 'Expose slot' }).click();
    await expect(dialog).toBeHidden();

    // The reused field is now exposed on this slot.
    await slotRow.click({ button: 'right' });
    await expect(page.getByRole('menuitem', { name: 'Detach' })).toBeVisible();
    await page.keyboard.press('Escape');
  });
});
