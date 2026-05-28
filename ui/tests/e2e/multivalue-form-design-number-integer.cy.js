/**
 * Comprehensive tests for multi-value form design for number and integer fields.
 *
 * This test covers the popover-based multi-value form UI for:
 * - Number (float) fields
 * - Integer fields
 *
 * The design features:
 * - Collapsed list items showing numeric previews.
 * - Popover-based editing (click item → popover with input).
 * - Custom drag handles and remove buttons via DrupalInputMultivalueForm component.
 */

/**
 * Helper function to open popover for a specific row and type a value.
 */
const typeInRow = (alias, rowIndex, value) => {
  cy.get(alias)
    .find('tbody tr')
    .eq(rowIndex)
    .find('[class*="_listItem_"]')
    .click();
  cy.get('[role="dialog"][data-state="open"]')
    .find('input[type="number"]')
    .clear();
  cy.get('[role="dialog"][data-state="open"]')
    .find('input[type="number"]')
    .type(value);
  cy.get('[role="dialog"][data-state="open"]')
    .find('input[type="number"]')
    .type('{enter}');
  cy.get('[role="dialog"][data-state="open"]').should('not.exist');
};

/**
 * Helper to verify the displayed text in a row's item.
 */
const verifyRowText = (alias, rowIndex, expectedText) => {
  cy.get(alias)
    .find('tbody tr')
    .eq(rowIndex)
    .find('[class*="_itemText_"]')
    .should('have.text', expectedText);
};

/**
 * Types into a row and waits for the resulting preview update.
 *
 * The intercept MUST be registered before the {enter} keypress inside typeInRow,
 * otherwise the preview POST can fire before the alias exists and cy.wait hangs.
 */
const typeInRowAwaitPreview = (alias, rowIndex, value) => {
  typeInRow(alias, rowIndex, value);
};

/**
 * Helper to confirm the contents of all rows.
 *
 * Uses retryable `should` assertions on each row's text so it waits for the DOM
 * to settle after an async update (e.g. preview POST, drag reorder).
 */
const confirmInputs = (alias, inputContent) => {
  cy.get(alias)
    .find('[class*="_listItem_"]')
    .should('have.length', inputContent.length);
  cy.get(alias).find('tbody tr').should('have.length', inputContent.length);
  inputContent.forEach((expected, ix) => {
    const expectedText = expected === '' ? 'Empty' : expected;
    cy.get(alias)
      .find('tbody tr')
      .eq(ix)
      .find('[class*="_itemText_"]')
      .should('have.text', expectedText);
  });
};

/**
 * Configuration array for parameterized test suites.
 */
const configs = [
  {
    fieldType: 'Number Field',
    fieldLabel: 'Canvas Unlimited Number',
    fieldAlias: 'unlimited-number',
    drupalSelector: 'edit-field-cvt-unlimited-number',
    defaultValue: '3',
    testValues: ['5', '4', '2', '4'],
    reorderedValues: ['3', '4', '5'],
  },
  {
    fieldType: 'Integer Field',
    fieldLabel: 'Canvas Unlimited Integer',
    fieldAlias: 'unlimited-int',
    drupalSelector: 'edit-field-cvt-unlimited-int',
    defaultValue: '10',
    testValues: ['30', '50', '20', '40'],
    reorderedValues: ['10', '50', '30'],
  },
  {
    fieldType: 'Required Number Field',
    fieldLabel: 'Canvas Required Number',
    fieldAlias: 'required-number',
    drupalSelector: 'edit-field-cvt-required-number',
    defaultValue: '42',
    testValues: ['100', '200', '150', '175'],
    reorderedValues: ['42', '200', '100'],
    isRequired: true,
  },
];

configs.forEach((config) => {
  const fieldType = config.fieldType;
  const fieldTypeLower = fieldType.toLowerCase();

  describe(`Multi-value Form Design – ${fieldType}`, () => {
    // Destructuring inside describe but outside hooks can trigger mocha/no-setup-in-describe
    // in some strict configs. To be safe, we access via config.* or destructure inside hooks.

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

    it(`renders multi-value ${fieldTypeLower} with popover-based UI`, () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

      cy.findByTestId('canvas-contextual-panel--page-data').should(
        'have.attr',
        'data-state',
        'active',
      );
      cy.findByTestId('canvas-page-data-form').as('entityForm');

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      // Verify the multi-value container exists with proper structure.
      cy.get(`@${config.fieldAlias}`)
        .find('.multivalue-container')
        .should('exist');
      cy.get(`@${config.fieldAlias}`).find('table').first().scrollIntoView();
      cy.get(`@${config.fieldAlias}`)
        .find('table')
        .first()
        .should('be.visible');
      cy.get(`@${config.fieldAlias}`).find('tbody tr').should('have.length', 2);

      // Verify initial values using the UI.
      confirmInputs(`@${config.fieldAlias}`, [config.defaultValue, '']);

      // Verify list items are visible (collapsed state).
      cy.get(`@${config.fieldAlias}`)
        .find('[class*="_listItem_"]')
        .should('have.length', 2);

      // Verify the first item displays the value preview.
      verifyRowText(`@${config.fieldAlias}`, 0, config.defaultValue);

      // Verify the second item shows "Empty".
      verifyRowText(`@${config.fieldAlias}`, 1, 'Empty');
    });

    it(`can edit items using popover interface`, () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');
      cy.get('@entityForm').recordFormBuildId();

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);
      cy.get('@' + config.fieldAlias).scrollIntoView({ offset: { top: -500 } });
      cy.intercept('POST', '**/canvas/api/v0/form/content-entity/**');

      // Populate the empty second item using the popover interface.
      typeInRowAwaitPreview(`@${config.fieldAlias}`, 1, config.testValues[0]);
      cy.findByLabelText('Loading Preview').should('not.exist');

      verifyRowText(`@${config.fieldAlias}`, 1, config.testValues[0]);
      confirmInputs(`@${config.fieldAlias}`, [
        config.defaultValue,
        config.testValues[0],
      ]);
    });

    it('can add new items using "+ Add new" button', () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');
      cy.get('@entityForm').recordFormBuildId();

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      const entityFormSelector = '[data-testid="canvas-page-data-form"]';

      // Populate the empty second item first.
      typeInRowAwaitPreview(`@${config.fieldAlias}`, 1, config.testValues[0]);

      cy.get(`@${config.fieldAlias}`)
        .findByRole('button', { name: '+ Add new' })
        .should('be.visible');

      cy.get(`@${config.fieldAlias}`)
        .findByRole('button', { name: '+ Add new' })
        .click();

      cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
      cy.get(`@${config.fieldAlias}`).find('tbody tr').should('have.length', 3);

      // Populate the new item.
      typeInRowAwaitPreview(`@${config.fieldAlias}`, 2, config.testValues[1]);

      confirmInputs(`@${config.fieldAlias}`, [
        config.defaultValue,
        config.testValues[0],
        config.testValues[1],
      ]);
    });

    it('can drag and drop items with custom drag handles', () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');
      cy.get('@entityForm').recordFormBuildId();

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      const entityFormSelector = '[data-testid="canvas-page-data-form"]';

      // Set up three items.
      typeInRowAwaitPreview(`@${config.fieldAlias}`, 1, config.testValues[0]);

      cy.get(`@${config.fieldAlias}`)
        .findByRole('button', { name: '+ Add new' })
        .click();
      cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

      typeInRowAwaitPreview(`@${config.fieldAlias}`, 2, config.testValues[1]);

      confirmInputs(`@${config.fieldAlias}`, [
        config.defaultValue,
        config.testValues[0],
        config.testValues[1],
      ]);

      cy.log('Move item 3 to position 2 using custom drag handle');

      cy.get(`@${config.fieldAlias}`).scrollIntoView({ offset: { top: -500 } });

      const dndDefaults = {
        position: 'topLeft',
        scrollBehavior: false,
      };

      // Verify custom drag handles are present.
      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-drag-handle a.tabledrag-handle')
        .should('have.length', 3);

      // Register intercept before the drag so we capture every preview POST
      // the reorder triggers (multiple can fire; the one that carries the
      // reordered `_weight` values is what we wait for below).
      cy.intercept('POST', '**/canvas/api/v0/layout/node/2').as(
        'previewUpdate',
      );

      cy.get(
        `[data-drupal-selector="${config.drupalSelector}"] tr.draggable:nth-child(3) [title="Change order"]`,
      ).realDnd(
        `[data-drupal-selector="${config.drupalSelector}"] tr.draggable:nth-child(2) [title="Change order"]`,
        dndDefaults,
      );

      // `drupalSelector` is `edit-<field_name-with-dashes>`; convert to the
      // form-field name shape (`field_cvt_unlimited_number`) used in the POST
      // body.
      const fieldName = config.drupalSelector
        .replace(/^edit-/, '')
        .replace(/-/g, '_');
      cy.assertMultivalueReorder({
        alias: 'previewUpdate',
        fieldName,
        expectedOrder: config.reorderedValues,
      });
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

      confirmInputs(`@${config.fieldAlias}`, config.reorderedValues);
      // Wait to ensure order is auto saved before reloading.
      // eslint-disable-next-line cypress/no-unnecessary-waiting
      cy.wait(10000);
      // Refresh the page to ensure the update persists.
      cy.reload();
      confirmInputs(`@${config.fieldAlias}`, [...config.reorderedValues, '']);
    });

    it('can remove items using popover remove button', () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');
      cy.get('@entityForm').recordFormBuildId();

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      typeInRowAwaitPreview(`@${config.fieldAlias}`, 1, config.testValues[0]);

      cy.get(`@${config.fieldAlias}`)
        .findByRole('button', { name: '+ Add new' })
        .click();
      cy.selectorShouldHaveUpdatedFormBuildId(
        '[data-testid="canvas-page-data-form"]',
      );
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

      typeInRowAwaitPreview(`@${config.fieldAlias}`, 2, config.testValues[1]);

      confirmInputs(`@${config.fieldAlias}`, [
        config.defaultValue,
        config.testValues[0],
        config.testValues[1],
      ]);

      // Open the popover for the second item and click Remove.
      cy.openMultivaluePopover(`@${config.fieldAlias}`, 1);

      cy.get('[role="dialog"][data-state="open"]').should('be.visible');

      cy.get('[role="dialog"][data-state="open"]')
        .findByRole('button', { name: /Remove/i })
        .should('be.visible');

      cy.get('[role="dialog"][data-state="open"]')
        .findByRole('button', { name: /Remove/i })
        .click();

      cy.get('[role="dialog"][data-state="open"]').should('not.exist');
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

      cy.get(`@${config.fieldAlias}`).find('tbody tr').should('have.length', 2);
    });

    it(`popover opens and closes correctly for ${fieldTypeLower}`, () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      // Click the first item to open popover.
      cy.openMultivaluePopover(`@${config.fieldAlias}`, 0);

      cy.get('[role="dialog"][data-state="open"]')
        .should('be.visible')
        .and('contain', config.fieldLabel);
      cy.get('[role="dialog"][data-state="open"]')
        .find('input[type="number"]')
        .should('be.visible')
        .should('have.value', config.defaultValue);

      cy.get('[role="dialog"][data-state="open"]')
        .find('[aria-label="Close"]')
        .should('exist');

      cy.closeMultivaluePopover();
    });

    it('popover propagates changes live as user types', () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      // Open the first row's popover.
      cy.openMultivaluePopover(`@${config.fieldAlias}`, 0);

      // Type a new value — row label should update immediately without Enter.
      cy.get('[role="dialog"][data-state="open"]')
        .find('input[type="number"]')
        .clear();
      cy.get('[role="dialog"][data-state="open"]')
        .find('input[type="number"]')
        .type('99');

      verifyRowText(`@${config.fieldAlias}`, 0, '99');

      // Close via the × button — value should not be reverted.
      cy.closeMultivaluePopover();

      verifyRowText(`@${config.fieldAlias}`, 0, '99');
    });

    it(`maintains form state across popover interactions for ${fieldTypeLower}`, () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');
      cy.get('@entityForm').recordFormBuildId();

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      typeInRowAwaitPreview(`@${config.fieldAlias}`, 0, config.testValues[2]);
      typeInRowAwaitPreview(`@${config.fieldAlias}`, 1, config.testValues[3]);
      cy.findByLabelText('Loading Preview').should('not.exist');

      verifyRowText(`@${config.fieldAlias}`, 0, config.testValues[2]);
      verifyRowText(`@${config.fieldAlias}`, 1, config.testValues[3]);

      confirmInputs(`@${config.fieldAlias}`, [
        config.testValues[2],
        config.testValues[3],
      ]);
    });

    // Test "disable remove when required and single item" behavior only for required fields
    describe('Required field specific behaviors', () => {
      // Logic inside describe moved to hooks or used within it blocks
      it('disables remove button when required field has only one item', function () {
        if (!config.isRequired) {
          this.skip();
        }

        cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
        cy.findByTestId('canvas-page-data-form').as('entityForm');
        cy.get('@entityForm').recordFormBuildId();

        cy.findByRole('heading', { name: config.fieldLabel })
          .closest('.js-form-wrapper')
          .as(config.fieldAlias);

        // Verify the field label has the required class
        cy.findByRole('heading', { name: config.fieldLabel }).should(
          'have.class',
          'form-required',
        );

        // Initially we have 2 items (one with value, one empty)
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .should('have.length', 2);

        // Fill the empty second item
        typeInRowAwaitPreview(`@${config.fieldAlias}`, 1, config.testValues[0]);

        confirmInputs(`@${config.fieldAlias}`, [
          config.defaultValue,
          config.testValues[0],
        ]);

        // Open popover for first item (we have 2 items now, remove should be enabled)
        cy.openMultivaluePopover(`@${config.fieldAlias}`, 0);

        cy.get('[role="dialog"][data-state="open"]').should('be.visible');

        // Remove button should be enabled when there are multiple items
        cy.get('[role="dialog"][data-state="open"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .should('not.be.disabled');

        // Close popover
        cy.closeMultivaluePopover();
        cy.openMultivaluePopover(`@${config.fieldAlias}`, 1);

        cy.get('[role="dialog"][data-state="open"]').should('be.visible');

        cy.get('[role="dialog"][data-state="open"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .click();

        cy.get('[role="dialog"][data-state="open"]').should('not.exist');
        cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

        // Now we should have only one item
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .should('have.length', 1);
        confirmInputs(`@${config.fieldAlias}`, [config.defaultValue]);

        // Open popover for the only remaining item
        cy.openMultivaluePopover(`@${config.fieldAlias}`, 0);

        cy.get('[role="dialog"][data-state="open"]').should('be.visible');

        // Remove button should be DISABLED because field is required and only one item exists
        cy.get('[role="dialog"][data-state="open"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .should('be.disabled');

        // Close popover
        cy.closeMultivaluePopover();
      });

      it('enables remove button when required field has multiple items', function () {
        if (!config.isRequired) {
          this.skip();
        }

        cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
        cy.findByTestId('canvas-page-data-form').as('entityForm');
        cy.get('@entityForm').recordFormBuildId();

        cy.findByRole('heading', { name: config.fieldLabel })
          .closest('.js-form-wrapper')
          .as(config.fieldAlias);

        const entityFormSelector = '[data-testid="canvas-page-data-form"]';

        // Fill the empty second item
        typeInRowAwaitPreview(`@${config.fieldAlias}`, 1, config.testValues[0]);

        // Add a third item
        cy.get(`@${config.fieldAlias}`)
          .findByRole('button', { name: '+ Add new' })
          .click();

        cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
        cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

        typeInRowAwaitPreview(`@${config.fieldAlias}`, 2, config.testValues[1]);

        // Now we have 3 items
        confirmInputs(`@${config.fieldAlias}`, [
          config.defaultValue,
          config.testValues[0],
          config.testValues[1],
        ]);

        // Open popover for any item - remove should be enabled
        cy.openMultivaluePopover(`@${config.fieldAlias}`, 1);

        cy.get('[role="dialog"][data-state="open"]').should('be.visible');

        // Remove button should be enabled when there are multiple items
        cy.get('[role="dialog"][data-state="open"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .should('not.be.disabled');

        // Close popover without removing
        cy.closeMultivaluePopover();

        // Remove one item to get down to 2 items
        cy.openMultivaluePopover(`@${config.fieldAlias}`, 2);

        cy.get('[role="dialog"][data-state="open"]')
          .findByRole('button', { name: /Remove/i })
          .click();

        cy.get('[role="dialog"][data-state="open"]').should('not.exist');

        // Still 2 items, remove should still be enabled
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .should('have.length', 2);

        cy.openMultivaluePopover(`@${config.fieldAlias}`, 0);

        cy.get('[role="dialog"][data-state="open"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .should('not.be.disabled');

        cy.closeMultivaluePopover();
      });
    });
  });
});
