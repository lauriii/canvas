/**
 * Comprehensive test for multivalue form.
 *
 * This test covers the new popover-based multivalue form UI.
 *
 * The new design features:
 * - Collapsed list items showing text previews.
 * - Popover-based editing (click item → popover with input).
 * - Custom drag handles and remove buttons via DrupalInputMultivalueForm component.
 */

describe('Multivalue Form Design', () => {
  before(() => {
    cy.drupalCanvasInstall(['canvas_test_article_fields']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  /**
   * Helper function to open popover for a specific row and type text.
   */
  /**
   * Types a value into the text input of the currently open popover dialog.
   *
   * @param {string} text - The value to type.
   * @param {boolean} [clear=false] - When true, clears the field before typing.
   */
  const typeInPopover = (text, clear = false) => {
    if (clear) {
      cy.get('[role="dialog"][data-state="open"]')
        .find('input[type="text"]')
        .clear({ force: true });
    }
    cy.get('[role="dialog"][data-state="open"]')
      .find('input[type="text"]')
      .type(text);
  };

  const typeInRow = (fieldAlias, rowIndex, text) => {
    // Click the list item to open the popover (using CSS module class prefix).
    cy.openMultivaluePopover(fieldAlias, rowIndex);
    // Type in the input field that appears in the popover.
    cy.get('[role="dialog"][data-state="open"]').should('be.visible');
    typeInPopover(text, true);
    // Press Enter to close the popover.
    typeInPopover('{enter}');
    // Wait for popover to close after Enter.
    cy.get('[role="dialog"][data-state="open"]').should('not.exist');
  };

  /**
   * Types into a row and waits for the resulting preview update.
   *
   * The intercept MUST be registered before the {enter} keypress inside typeInRow,
   * otherwise the preview POST can fire before the alias exists and cy.wait hangs.
   */
  const typeInRowAwaitPreview = (fieldAlias, rowIndex, text) => {
    typeInRow(fieldAlias, rowIndex, text);
  };

  /**
   * Helper function to verify text content in a row.
   */
  const verifyRowText = (fieldAlias, rowIndex, expectedText) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .eq(rowIndex)
      .find('[class*="_itemText_"]')
      .should('have.text', expectedText);
  };

  /**
   * Helper function to confirm the contents of all rows.
   *
   * Uses retryable `should` assertions on each row's text so it waits for the
   * DOM to settle after an async update (e.g. preview POST, drag reorder).
   */
  const confirmTextInputs = (fieldAlias, inputContent) => {
    cy.get(fieldAlias)
      .find('[class*="_listItem_"]')
      .should('have.length', inputContent.length);
    cy.get(fieldAlias)
      .find('tbody tr')
      .should('have.length', inputContent.length);

    inputContent.forEach((expected, ix) => {
      const expectedText = expected === '' ? 'Empty' : expected;
      cy.get(fieldAlias)
        .find('tbody tr')
        .eq(ix)
        .find('[class*="_itemText_"]')
        .should('have.text', expectedText);
    });
  };

  it('renders multivalue fields with new popover-based UI', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    cy.findByTestId('canvas-contextual-panel--page-data').should(
      'have.attr',
      'data-state',
      'active',
    );
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    // Find the multivalue field container.
    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    // Verify the multivalue container exists with proper structure.
    cy.get('@unlimited-text').find('.multivalue-container').should('exist');
    cy.get('@unlimited-text').find('table').should('be.visible');
    cy.get('@unlimited-text').find('tbody tr').should('have.length', 2);

    // Verify initial values using the new UI.
    confirmTextInputs('@unlimited-text', ['Marshmallow Coast', '']);

    // Verify list items are visible (collapsed state).
    cy.get('@unlimited-text')
      .find('[class*="_listItem_"]')
      .should('have.length', 2);

    // Verify the first item displays the text preview.
    verifyRowText('@unlimited-text', 0, 'Marshmallow Coast');

    // Verify the second item shows "Empty".
    verifyRowText('@unlimited-text', 1, 'Empty');
  });

  it('can edit multivalue items using popover interface', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    // Log all ajax form requests to help with debugging.
    cy.intercept('POST', '**/canvas/api/v0/form/content-entity/**');

    // Populate the empty second item using the new popover interface.
    typeInRowAwaitPreview('@unlimited-text', 1, 'Neutral Milk Hotel');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify the value was set.
    verifyRowText('@unlimited-text', 1, 'Neutral Milk Hotel');
    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'Neutral Milk Hotel',
    ]);
  });

  it('can add new items using "+ Add new" button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Populate the empty second item first.
    typeInRowAwaitPreview('@unlimited-text', 1, 'Neutral Milk Hotel');

    // Verify the new button text.
    cy.get('@unlimited-text')
      .findByRole('button', { name: '+ Add new' })
      .should('be.visible');

    // Add another item
    cy.get('@unlimited-text')
      .findByRole('button', { name: '+ Add new' })
      .click();

    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.get('@unlimited-text').find('tbody tr').should('have.length', 3);

    // Populate the new item
    typeInRowAwaitPreview('@unlimited-text', 2, 'The Olivia Tremor Control');

    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'Neutral Milk Hotel',
      'The Olivia Tremor Control',
    ]);
  });

  it('can drag and drop items with custom drag handles', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Set up three items
    typeInRowAwaitPreview('@unlimited-text', 1, 'Neutral Milk Hotel');

    cy.get('@unlimited-text')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    typeInRowAwaitPreview('@unlimited-text', 2, 'The Olivia Tremor Control');

    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'Neutral Milk Hotel',
      'The Olivia Tremor Control',
    ]);

    cy.log('Move "item 3" to position 2 using custom drag handle');

    // Ensure the drop target is in the viewport.
    cy.get('@unlimited-text')
      .find('tbody tr')
      .eq(0)
      .scrollIntoView({ offset: { top: -500 } });

    const dndDefaults = {
      position: 'topLeft',
      scrollBehavior: false,
    };

    // Verify custom drag handles are present.
    cy.get('@unlimited-text')
      .find('.canvas-drag-handle a.tabledrag-handle')
      .should('have.length', 3);

    // Verify custom SVG icon is present in drag handles.
    cy.get('@unlimited-text')
      .find('.canvas-drag-handle a.tabledrag-handle .drag-handle-icon')
      .should('have.length', 3);

    // Register intercept before the drag so we capture every preview POST
    // the reorder triggers (multiple can fire; the one that carries the
    // reordered `_weight` values is what we wait for below).
    cy.intercept('POST', '**/canvas/api/v0/layout/node/2').as('previewUpdate');

    cy.get(
      '[data-drupal-selector="edit-field-cvt-unlimited-text"] tr.draggable:nth-child(3) [title="Change order"]',
    ).realDnd(
      '[data-drupal-selector="edit-field-cvt-unlimited-text"] tr.draggable:nth-child(2) [title="Change order"]',
      dndDefaults,
    );

    cy.assertMultivalueReorder({
      alias: 'previewUpdate',
      fieldName: 'field_cvt_unlimited_text',
      expectedOrder: [
        'Marshmallow Coast',
        'The Olivia Tremor Control',
        'Neutral Milk Hotel',
      ],
    });
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'The Olivia Tremor Control',
      'Neutral Milk Hotel',
    ]);
    // Wait to ensure order is auto saved before reloading.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(10000);
    cy.log('Refresh the page to ensure the update persists.');
    cy.reload();
    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'The Olivia Tremor Control',
      'Neutral Milk Hotel',
      '',
    ]);

    cy.log('Drag and drop continues to work after page reload.');
    cy.get('@unlimited-text').find('tbody tr').eq(0).scrollIntoView();

    cy.intercept('POST', '**/canvas/api/v0/layout/node/2').as(
      'previewUpdateAfterReload',
    );

    cy.get(
      '[data-drupal-selector="edit-field-cvt-unlimited-text"] tr.draggable:nth-child(3) [title="Change order"]',
    ).realDnd(
      '[data-drupal-selector="edit-field-cvt-unlimited-text"] tr.draggable:nth-child(2) [title="Change order"]',
      dndDefaults,
    );

    cy.assertMultivalueReorder({
      alias: 'previewUpdateAfterReload',
      fieldName: 'field_cvt_unlimited_text',
      expectedOrder: [
        'Marshmallow Coast',
        'Neutral Milk Hotel',
        'The Olivia Tremor Control',
        '',
      ],
    });
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'Neutral Milk Hotel',
      'The Olivia Tremor Control',
      '',
    ]);
  });

  it('can remove items using popover remove button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';
    cy.get('@unlimited-text').scrollIntoView();
    typeInRowAwaitPreview('@unlimited-text', 1, 'Neutral Milk Hotel');
    cy.reasonableWait();
    cy.get('@unlimited-text')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    typeInRowAwaitPreview('@unlimited-text', 2, 'The Olivia Tremor Control');

    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'Neutral Milk Hotel',
      'The Olivia Tremor Control',
    ]);

    // Open the popover for the second item and verify the Remove button.
    cy.openMultivaluePopover('@unlimited-text', 1);

    cy.get('[role="dialog"][data-state="open"]').should('be.visible');
    cy.get('@unlimited-text').scrollIntoView();

    cy.get('[role="dialog"][data-state="open"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.visible');
    cy.get('[role="dialog"][data-state="open"]')
      .findByRole('button', { name: /Remove/i })
      .click();
    cy.get('@unlimited-text').scrollIntoView();

    cy.get('[role="dialog"][data-state="open"]').should('not.exist');

    // cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    cy.get('@unlimited-text').find('tbody tr').should('have.length', 2);
  });

  it('popover opens and closes correctly', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    // Click the first item to open popover.
    cy.openMultivaluePopover('@unlimited-text', 0);

    cy.get('[role="dialog"][data-state="open"]').should('be.visible');

    cy.get('[role="dialog"][data-state="open"]').should(
      'contain',
      'Canvas Unlimited Text',
    );

    cy.get('[role="dialog"][data-state="open"]')
      .find('input[type="text"]')
      .should('be.visible')
      .should('have.value', 'Marshmallow Coast');

    cy.get('[role="dialog"][data-state="open"]')
      .find('[aria-label="Close"]')
      .should('exist');
    cy.closeMultivaluePopover();
  });

  it('popover propagates changes live as user types', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    // Open the first row's popover.
    cy.openMultivaluePopover('@unlimited-text', 0);

    // Type a new value without pressing Enter — row label should update immediately.
    typeInPopover('Live Update', true);
    verifyRowText('@unlimited-text', 0, 'Live Update');

    // Change the value again while the popover is still open.
    typeInPopover('Second Edit', true);
    verifyRowText('@unlimited-text', 0, 'Second Edit');

    // Close via Enter — value should not be reverted.
    typeInPopover('{enter}');
    cy.get('[role="dialog"][data-state="open"]').should('not.exist');
    verifyRowText('@unlimited-text', 0, 'Second Edit');

    // Reopen, make another change, then close via the × button — still no revert.
    cy.openMultivaluePopover('@unlimited-text', 0);

    typeInPopover('After Close', true);
    verifyRowText('@unlimited-text', 0, 'After Close');

    cy.closeMultivaluePopover();

    verifyRowText('@unlimited-text', 0, 'After Close');
  });

  it('maintains form state across popover interactions', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    cy.openMultivaluePopover('@unlimited-text', 0);

    typeInPopover('Modified Item 1{enter}', true);

    cy.get('[role="dialog"][data-state="open"]').should('not.exist');

    typeInRowAwaitPreview('@unlimited-text', 1, 'Modified Item 2');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify both items maintain their values
    verifyRowText('@unlimited-text', 0, 'Modified Item 1');
    verifyRowText('@unlimited-text', 1, 'Modified Item 2');

    confirmTextInputs('@unlimited-text', [
      'Modified Item 1',
      'Modified Item 2',
    ]);
  });
});
