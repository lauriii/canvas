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

describe('Multivalue Form Design (canvas_dev_mode)', () => {
  before(() => {
    cy.drupalCanvasInstall([
      'canvas_test_article_fields',
      // @todo remove once https://drupal.org/i/3577946 is fixed.
      // Required for new multivalue form UI.
      'canvas_dev_mode',
    ]);
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
  const typeInRow = (fieldAlias, rowIndex, text) => {
    // Click the list item to open the popover (using CSS module class prefix).
    cy.get(fieldAlias)
      .find('tbody tr')
      .eq(rowIndex)
      .find('[class*="_listItem_"]')
      .click();
    // Type in the input field that appears in the popover.
    cy.get('[role="dialog"]').find('input[type="text"]').clear();
    cy.get('[role="dialog"]').find('input[type="text"]').type(text);
    // Press Enter to commit the value (required for the value to update).
    cy.get('[role="dialog"]').find('input[type="text"]').type('{enter}');
    // Wait for popover to close after Enter.
    cy.get('[role="dialog"]').should('not.exist');
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
   * Helper function to confirm the contents of all rows by checking
   * the list item text content (visible in the collapsed state).
   */
  const confirmTextInputs = (fieldAlias, inputContent) => {
    // First, wait for the list items to be present (using CSS module class prefix).
    cy.get(fieldAlias)
      .find('[class*="_listItem_"]')
      .should('have.length', inputContent.length);

    cy.get(fieldAlias)
      .find('tbody tr')
      .should('have.length', inputContent.length)
      .then(($rows) => {
        const items = [];
        $rows.each((ix, row) => {
          // Find the listItem element (CSS module class) which contains the itemText.
          const listItem = Cypress.$(row).find('[class*="_listItem_"]');
          if (listItem.length > 0) {
            // Get the text from the itemText element (CSS module class).
            const textElement = listItem.find('[class*="_itemText_"]');
            if (textElement.length > 0) {
              const text = textElement.text().trim();
              items.push(text === 'Empty' ? '' : text);
            } else {
              items.push('');
            }
          } else {
            items.push('');
          }
        });
        expect(items).to.deep.equal(inputContent);
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

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    // Populate the empty second item using the new popover interface.
    typeInRow('@unlimited-text', 1, 'Neutral Milk Hotel');

    // Wait for the preview to finish loading.
    cy.wait('@updatePreview');
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
    typeInRow('@unlimited-text', 1, 'Neutral Milk Hotel');

    const waitForPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      // Trigger a blur.
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    waitForPreview();

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
    typeInRow('@unlimited-text', 2, 'The Olivia Tremor Control');
    waitForPreview();
    cy.waitForAjax();

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
    typeInRow('@unlimited-text', 1, 'Neutral Milk Hotel');

    const waitForPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    waitForPreview();

    cy.get('@unlimited-text')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    typeInRow('@unlimited-text', 2, 'The Olivia Tremor Control');
    waitForPreview();
    cy.waitForAjax();

    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'Neutral Milk Hotel',
      'The Olivia Tremor Control',
    ]);

    cy.log('Move "item 3" to position 2 using custom drag handle');

    // Ensure the drop target is in the viewport.
    cy.get('@unlimited-text').find('tbody tr').eq(0).scrollIntoView();

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

    cy.get(
      '[data-drupal-selector="edit-field-cvt-unlimited-text"] tr.draggable:nth-child(3) [title="Change order"]',
    ).realDnd(
      '[data-drupal-selector="edit-field-cvt-unlimited-text"] tr.draggable:nth-child(2)',
      dndDefaults,
    );

    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.waitForAjax();
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(1000);

    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'The Olivia Tremor Control',
      'Neutral Milk Hotel',
    ]);
    // Refresh the page to ensure the update persists.
    cy.reload();
    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'The Olivia Tremor Control',
      'Neutral Milk Hotel',
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

    typeInRow('@unlimited-text', 1, 'Neutral Milk Hotel');

    const waitForPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    waitForPreview();

    cy.get('@unlimited-text')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    typeInRow('@unlimited-text', 2, 'The Olivia Tremor Control');
    waitForPreview();
    cy.waitForAjax();

    confirmTextInputs('@unlimited-text', [
      'Marshmallow Coast',
      'Neutral Milk Hotel',
      'The Olivia Tremor Control',
    ]);

    // Open the popover for the second item and verify the Remove button.
    cy.get('@unlimited-text')
      .find('tbody tr')
      .eq(1)
      .find('[class*="_listItem_"]')
      .click();

    cy.get('[role="dialog"]').should('be.visible');

    cy.get('[role="dialog"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.visible');

    cy.get('[role="dialog"]')
      .findByRole('button', { name: /Remove/i })
      .click();

    cy.get('[role="dialog"]').should('not.exist');

    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.waitForAjax();

    cy.get('@unlimited-text').find('tbody tr').should('have.length', 2);
  });

  it('popover opens and closes correctly', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    // Click the first item to open popover.
    cy.get('@unlimited-text')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .click();

    cy.get('[role="dialog"]').should('be.visible');

    cy.get('[role="dialog"]').should('contain', 'Canvas Unlimited Text');

    cy.get('[role="dialog"]')
      .find('input[type="text"]')
      .should('be.visible')
      .should('have.value', 'Marshmallow Coast');

    cy.get('[role="dialog"]').find('[aria-label="Close"]').should('exist');

    cy.get('[role="dialog"]').find('[aria-label="Close"]');
    cy.get('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
  });

  it('popover discards uncommitted changes when closed without Enter', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    const originalText = 'Marshmallow Coast';

    cy.get('@unlimited-text')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .click();

    cy.get('[role="dialog"]').find('input[type="text"]').clear();
    cy.get('[role="dialog"]')
      .find('input[type="text"]')
      .type('This should not be saved');

    cy.get('[role="dialog"]').find('[aria-label="Close"]');
    cy.get('[aria-label="Close"]').click();

    cy.get('[role="dialog"]').should('not.exist');

    verifyRowText('@unlimited-text', 0, originalText);
  });

  it('maintains form state across popover interactions', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
      .parents('.js-form-wrapper')
      .as('unlimited-text');

    cy.get('@unlimited-text')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .click();

    cy.get('[role="dialog"]').find('input[type="text"]').clear();
    cy.get('[role="dialog"]')
      .find('input[type="text"]')
      .type('Modified Item 1{enter}');

    cy.get('[role="dialog"]').should('not.exist');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    typeInRow('@unlimited-text', 1, 'Modified Item 2');

    cy.wait('@updatePreview');
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
