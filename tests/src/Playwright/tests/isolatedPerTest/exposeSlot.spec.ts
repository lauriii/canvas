/**
 * Tests exposing slots and selecting them in the template editor.
 *
 * The tests run on the `article_translation` recipe (Article bundle + preview
 * node) plus `article_slot_field`, which adds a reusable `canvas_slot_reusable`
 * field with content on one article node, so the dialog has a candidate to
 * default to.
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
    // A reusable slot field holding content, for the "reuse existing" test.
    // Applied before canvas_test_article_fields, whose required fields the
    // recipe's article node does not fill.
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/article_slot_field`,
    );
    // Field UI backs the "Manage fields" links.
    await drupal.installModules(['canvas_test_article_fields', 'field_ui']);
    await drupal.addPermissions({
      role: 'editor',
      permissions: ['edit any article content', 'administer node fields'],
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
    return page
      .getByTestId('canvas-primary-panel')
      .locator('[data-canvas-type="slot"]')
      .first();
  };

  test('add-new path creates a canvas_slot_ field, then Disable editing removes it', async ({
    page,
    drupal,
    canvas,
  }) => {
    const slotRow = await openTemplateWithSlot({ page, drupal, canvas });

    // Right-click the slot to open its context menu, then choose "Enable editing".
    await slotRow.click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Enable editing' }).click();

    const dialog = page.getByRole('dialog').filter({
      has: page.getByRole('heading', { name: 'Enable editing' }),
    });
    await expect(dialog).toBeVisible();

    // The "Slot field" Select defaults to reusing an existing candidate (see
    // the next test), so explicitly choose "Add new slot…" to exercise the
    // create path. That reveals the label + machine-name fields.
    await dialog.getByLabel('Slot field').click();
    await page.getByRole('option', { name: 'Add new slot…' }).click();

    // Typing the label auto-derives the canvas_slot_-prefixed machine name.
    await dialog.getByLabel('Slot name').fill('My hero');
    await expect(dialog.getByLabel('Machine name')).toHaveValue(
      'canvas_slot_my_hero',
    );

    // The exposure is auto-saved even though only the exposed slots changed
    // (the component was inserted, and saved, before): it survives a reload.
    const exposureSaved = page.waitForResponse(
      (response) =>
        response.request().method() === 'POST' &&
        response.url().includes('/api/v0/layout-content-template/') &&
        (response.request().postData() ?? '').includes('canvas_slot_my_hero'),
    );
    await dialog.getByRole('button', { name: 'Enable editing' }).click();
    await expect(dialog).toBeHidden();
    await exposureSaved;
    // Reload without a selected component.
    await page.goto(page.url().replace(/\/component\/.*$/, ''));
    // Let the editor finish initializing first: its re-render would otherwise
    // replace the Layers row and close the context menu opened below. With no
    // component selected the contextual panel has no form, so waitForEditorUi()
    // does not apply.
    await canvas.waitForCanvasSideMenu();
    await canvas.waitForCanvasTopbar();
    await canvas.waitForEditorFrame();
    await canvas.openLayersPanel();

    // An exposed slot is marked by its outline. Like any slot, it shows its
    // chip only while its component is hovered or selected.
    await expect(
      page.locator('#canvasPreviewOverlay .slotOverlay[data-canvas-exposed]'),
    ).toHaveCount(1);
    await page.mouse.move(0, 0);
    await expect(page.getByTestId(/^slot-expose-chip-/)).toHaveCount(0);

    // The slot is now exposed: its context menu offers Edit label + Disable
    // editing instead of Enable editing.
    await slotRow.click({ button: 'right' });
    await expect(
      page.getByRole('menuitem', { name: 'Edit label' }),
    ).toBeVisible();
    await expect(
      page.getByRole('menuitem', { name: 'Manage fields' }),
    ).toBeVisible();

    // Edit label opens prefilled, never flashing the "required" alert.
    await page.evaluate(() => {
      new MutationObserver((records) => {
        const added = records.flatMap((record) => [...record.addedNodes]);
        if (added.some((node) => node.textContent?.includes('is required'))) {
          document.body.dataset.sawRequiredAlert = 'true';
        }
      }).observe(document.body, { childList: true, subtree: true });
    });
    await page.getByRole('menuitem', { name: 'Edit label' }).click();
    const labelDialog = page.getByRole('dialog').filter({
      has: page.getByRole('heading', { name: 'Edit slot label' }),
    });
    await expect(labelDialog.getByLabel('Slot name')).toHaveValue('My hero');
    await expect(page.locator('body')).not.toHaveAttribute(
      'data-saw-required-alert',
    );
    await labelDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(labelDialog).toBeHidden();

    await slotRow.click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Disable editing' }).click();

    const detachDialog = page.getByRole('dialog').filter({
      has: page.getByRole('heading', { name: 'Disable editing' }),
    });
    await expect(detachDialog).toBeVisible();
    await expect(
      detachDialog.getByRole('link', { name: 'Manage fields' }),
    ).toHaveAttribute(
      'href',
      /\/admin\/structure\/types\/manage\/article\/fields$/,
    );
    await detachDialog.getByRole('button', { name: 'Disable editing' }).click();
    await expect(detachDialog).toBeHidden();

    // Editing disabled again: the context menu offers "Enable editing" again.
    await slotRow.click({ button: 'right' });
    await expect(
      page.getByRole('menuitem', { name: 'Enable editing' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
  });

  test('defaults to reusing an existing slot field', async ({
    page,
    drupal,
    canvas,
  }) => {
    const slotRow = await openTemplateWithSlot({ page, drupal, canvas });

    // Preview the node with content in the reused field.
    await page.getByTestId('select-content-preview-item').click();
    await page
      .getByTestId(/^preview-item-\d+$/)
      .filter({ hasText: 'Slot field alpha' })
      .click();
    await expect(page.getByTestId('select-content-preview-item')).toContainText(
      'Slot field alpha',
    );
    await canvas.waitForEditorUi();

    await slotRow.click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Enable editing' }).click();

    const dialog = page.getByRole('dialog').filter({
      has: page.getByRole('heading', { name: 'Enable editing' }),
    });
    await expect(dialog).toBeVisible();

    // The single "Slot field" Select defaults to the content-first existing
    // candidate (NOT "Add new slot…"), and the label/machine-name create fields
    // stay hidden while reusing an existing field.
    const slotFieldSelect = dialog.getByLabel('Slot field');
    await expect(slotFieldSelect).toContainText(
      'Reusable slot (canvas_slot_reusable) — 1 with content',
    );
    await expect(
      dialog.getByText(
        'Reusing this field restores content already stored in it by 1 entity.',
      ),
    ).toBeVisible();
    await expect(slotFieldSelect).not.toContainText('Add new slot…');
    await expect(dialog.getByLabel('Machine name')).toHaveCount(0);

    await dialog.getByRole('button', { name: 'Enable editing' }).click();
    await expect(dialog).toBeHidden();

    // The previewed node overrides the slot it now backs, so the slot is
    // marked as overridden by that node without a reload. The marker stays
    // shown when nothing is hovered or selected.
    await page.mouse.move(0, 0);
    await expect(page.getByTestId(/^slot-overridden-label-/)).toHaveText(
      'Overridden by Slot field alpha',
    );

    // The reused field is now exposed on this slot: the selected slot's
    // sidebar offers Disable editing, through the same confirm dialog as the
    // context menu.
    await slotRow.click();
    await expect(page.getByTestId('canvas-slot-usage-panel')).toBeVisible();
    await page.getByTestId('canvas-slot-disable-editing-button').click();
    const detachDialog = page.getByRole('dialog').filter({
      has: page.getByRole('heading', { name: 'Disable editing' }),
    });
    await expect(detachDialog).toContainText('Reusable slot');
    await detachDialog.getByRole('button', { name: 'Disable editing' }).click();
    await expect(detachDialog).toBeHidden();
    await expect(page.getByTestId('canvas-slot-expose-button')).toBeVisible();
  });

  test('a click in a slot selects its component, its chip the slot, a drag moves the component', async ({
    page,
    drupal,
    canvas,
  }) => {
    await openTemplateWithSlot({ page, drupal, canvas });
    const componentOverlay = page.locator(
      '#canvasPreviewOverlay .componentOverlay:has(> [data-canvas-component-id="sdc.canvas_test_sdc.two_column"])',
    );
    await expect(componentOverlay).toHaveCount(1);
    const componentUuid = await componentOverlay
      .locator('> .canvas--sortable-item')
      .getAttribute('data-canvas-uuid');
    expect(componentUuid).not.toBeNull();
    // Locator actions hit-test (unlike the dispatched events of
    // canvas.clickPreviewComponent()), so they fail when something paints on
    // top of their target. The two_column slots fill the component.
    const columnOne = componentOverlay.getByRole('button', {
      name: 'Slot Column One (Two Column)',
    });
    const columnTwo = componentOverlay.getByRole('button', {
      name: 'Slot Column Two (Two Column)',
    });
    const chipOne = page.getByTestId(/^slot-expose-chip-.*column_one$/);
    const chipTwo = page.getByTestId(/^slot-expose-chip-.*column_two$/);
    const slotPanel = page.getByTestId('canvas-slot-expose-button');
    const clearSelection = async () => {
      await page.goto(page.url().replace(/\/component\/.*$/, ''));
      // The editor loads the layout after the page: the slot overlay is the
      // last thing to appear, and the one the test acts on.
      await canvas.waitForEditorFrame();
      await expect(columnOne).toBeVisible();
    };

    // Without a hovered or selected component, the slots show no chips.
    await clearSelection();
    await page.mouse.move(0, 0);
    await expect(chipOne).toBeHidden();
    await expect(chipTwo).toBeHidden();

    // Every click in a slot selects its component.
    await columnOne.click();
    await expect(page).toHaveURL(new RegExp(`/component/${componentUuid}$`));
    await expect(componentOverlay).toHaveAttribute(
      'data-canvas-selected',
      'true',
    );
    await columnOne.click();
    await columnTwo.click();
    await expect(page).toHaveURL(new RegExp(`/component/${componentUuid}$`));
    await expect(slotPanel).toBeHidden();
    // A selected component shows its slots' chips.
    await page.mouse.move(0, 0);
    await expect(chipOne).toBeVisible();
    await expect(chipTwo).toBeVisible();

    // A right-click in a slot opens the component's menu, which offers the
    // slots' actions in submenus.
    await columnOne.click({ button: 'right' });
    await expect(
      page.getByRole('menuitem', { name: 'Duplicate' }),
    ).toBeVisible();
    await page.getByRole('menuitem', { name: 'Column Two' }).hover();
    await expect(
      page.getByRole('menuitem', { name: 'Enable editing' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menuitem')).toHaveCount(0);

    // The chip does not cover the component's name tag: a click on the chip
    // reaches it. The chip stays shown while the pointer moves to it from the
    // slot, across the component. The chip selects the slot, without opening
    // a menu. The selected slot keeps its chip once the pointer leaves.
    await clearSelection();
    const slotBox = (await columnOne.boundingBox())!;
    await page.mouse.move(
      slotBox.x + slotBox.width / 2,
      slotBox.y + slotBox.height / 2,
    );
    // Hovering highlights what a click selects: in the slot, the component;
    // on the chip, the slot.
    const slotOneOverlay = componentOverlay.locator(
      '.slotOverlay[aria-label^="Column One"]',
    );
    await expect(componentOverlay).toHaveClass(/hovered/);
    await expect(slotOneOverlay).not.toHaveClass(/hovered/);
    const chipBox = (await chipOne.boundingBox())!;
    await page.mouse.move(
      chipBox.x + chipBox.width / 2,
      chipBox.y + chipBox.height / 2,
      { steps: 20 },
    );
    await expect(chipOne).toBeVisible();
    await expect(slotOneOverlay).toHaveClass(/hovered/);
    await expect(componentOverlay).not.toHaveClass(/hovered/);
    await chipOne.click();
    await expect(slotPanel).toBeVisible();
    await expect(page).not.toHaveURL(/\/component\//);
    await expect(page.getByRole('menuitem')).toHaveCount(0);
    await page.mouse.move(0, 0);
    await expect(chipOne).toBeVisible();

    // A selected slot keeps the chips of the other slots of its component, so
    // the selection can switch between them.
    await expect(chipTwo).toBeVisible();
    await chipTwo.click();
    await expect(slotPanel).toBeVisible();
    await expect(
      componentOverlay.locator('.slotOverlay[aria-label^="Column Two"]'),
    ).toHaveClass(/selected/);
    await page.mouse.move(0, 0);
    await expect(chipOne).toBeVisible();
    await expect(chipTwo).toBeVisible();
    // With a slot selected, a click in a slot selects the component.
    await columnOne.click();
    await expect(page).toHaveURL(new RegExp(`/component/${componentUuid}$`));
    await expect(slotPanel).toBeHidden();

    // Pressing inside a slot and moving drags the component.
    const box = (await columnOne.boundingBox())!;
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2 + 5);
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2 + 10);
    await expect(componentOverlay).toHaveClass(/dragging/);
    await page.keyboard.press('Escape');
    await page.mouse.up();
    await expect(componentOverlay).not.toHaveClass(/dragging/);

    // A click in a slot selects the component, so Delete removes it; undo
    // restores it.
    await clearSelection();
    await columnOne.click();
    // The component's form renders once the selection reaches the router.
    await expect(
      page.getByTestId('canvas-contextual-panel').locator('form').first(),
    ).toBeAttached();
    await page.keyboard.press('Delete');
    await expect(componentOverlay).toHaveCount(0);
    await page.keyboard.press('ControlOrMeta+z');
    await expect(componentOverlay).toHaveCount(1);
  });
});
