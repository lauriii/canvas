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
  cy.get('[role="dialog"]').find('input[type="number"]').clear();
  cy.get('[role="dialog"]').find('input[type="number"]').type(value);
  cy.get('[role="dialog"]').find('input[type="number"]').type('{enter}');
  cy.get('[role="dialog"]').should('not.exist');
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
 * Helper to wait for the preview layout update.
 */
const waitForPreview = () => {
  cy.intercept({
    url: '**/canvas/api/v0/layout/node/2',
    times: 1,
    method: 'POST',
  }).as('updatePreview');
  cy.get(document.activeElement).blur();
  cy.wait('@updatePreview');
};

/**
 * Helper to confirm the contents of all rows.
 */
const confirmInputs = (alias, inputContent) => {
  cy.get(alias)
    .find('[class*="_listItem_"]')
    .should('have.length', inputContent.length);

  cy.get(alias)
    .find('tbody tr')
    .should('have.length', inputContent.length)
    .then(($rows) => {
      const items = [];
      $rows.each((ix, row) => {
        const listItem = Cypress.$(row).find('[class*="_listItem_"]');
        if (listItem.length > 0) {
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
      cy.drupalCanvasInstall([
        'canvas_test_article_fields',
        // @todo remove once https://drupal.org/i/3577946 is fixed.
        // Required for multi-value form UI.
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

      cy.intercept('POST', '**/canvas/api/v0/form/content-entity/**');

      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');

      // Populate the empty second item using the popover interface.
      typeInRow(`@${config.fieldAlias}`, 1, config.testValues[0]);

      cy.wait('@updatePreview');
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
      typeInRow(`@${config.fieldAlias}`, 1, config.testValues[0]);
      waitForPreview();

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
      typeInRow(`@${config.fieldAlias}`, 2, config.testValues[1]);
      waitForPreview();
      cy.waitForAjax();

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
      typeInRow(`@${config.fieldAlias}`, 1, config.testValues[0]);
      waitForPreview();

      cy.get(`@${config.fieldAlias}`)
        .findByRole('button', { name: '+ Add new' })
        .click();
      cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

      typeInRow(`@${config.fieldAlias}`, 2, config.testValues[1]);
      waitForPreview();
      cy.waitForAjax();

      confirmInputs(`@${config.fieldAlias}`, [
        config.defaultValue,
        config.testValues[0],
        config.testValues[1],
      ]);

      cy.log('Move item 3 to position 2 using custom drag handle');

      cy.get(`@${config.fieldAlias}`).find('tbody tr').eq(0).scrollIntoView();

      const dndDefaults = {
        position: 'topLeft',
        scrollBehavior: false,
      };

      // Verify custom drag handles are present.
      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-drag-handle a.tabledrag-handle')
        .should('have.length', 3);

      cy.get(
        `[data-drupal-selector="${config.drupalSelector}"] tr.draggable:nth-child(3) [title="Change order"]`,
      ).realDnd(
        `[data-drupal-selector="${config.drupalSelector}"] tr.draggable:nth-child(2)`,
        dndDefaults,
      );

      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
      cy.waitForAjax();

      // eslint-disable-next-line cypress/no-unnecessary-waiting
      cy.wait(1000);
      confirmInputs(`@${config.fieldAlias}`, config.reorderedValues);
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

      typeInRow(`@${config.fieldAlias}`, 1, config.testValues[0]);
      waitForPreview();

      cy.get(`@${config.fieldAlias}`)
        .findByRole('button', { name: '+ Add new' })
        .click();
      cy.selectorShouldHaveUpdatedFormBuildId(
        '[data-testid="canvas-page-data-form"]',
      );
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

      typeInRow(`@${config.fieldAlias}`, 2, config.testValues[1]);
      waitForPreview();
      cy.waitForAjax();

      confirmInputs(`@${config.fieldAlias}`, [
        config.defaultValue,
        config.testValues[0],
        config.testValues[1],
      ]);

      // Open the popover for the second item and click Remove.
      cy.get(`@${config.fieldAlias}`)
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

      cy.get(`@${config.fieldAlias}`).find('tbody tr').should('have.length', 2);
    });

    it(`popover opens and closes correctly for ${fieldTypeLower}`, () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      // Click the first item to open popover.
      cy.get(`@${config.fieldAlias}`)
        .find('tbody tr')
        .eq(0)
        .find('[class*="_listItem_"]')
        .click();

      cy.get('[role="dialog"]')
        .should('be.visible')
        .and('contain', config.fieldLabel);
      cy.get('[role="dialog"]')
        .find('input[type="number"]')
        .should('be.visible')
        .should('have.value', config.defaultValue);

      cy.get('[role="dialog"]').find('[aria-label="Close"]').should('exist');

      cy.get('[aria-label="Close"]').click();
      cy.get('[role="dialog"]').should('not.exist');
    });

    it('popover discards uncommitted changes when closed without Enter', () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      cy.get(`@${config.fieldAlias}`)
        .find('tbody tr')
        .eq(0)
        .find('[class*="_listItem_"]')
        .click();

      cy.get('[role="dialog"]').find('input[type="number"]').clear();
      cy.get('[role="dialog"]').find('input[type="number"]').type('99');
      cy.get('[aria-label="Close"]').click();
      cy.get('[role="dialog"]').should('not.exist');

      verifyRowText(`@${config.fieldAlias}`, 0, config.defaultValue);
    });

    it(`maintains form state across popover interactions for ${fieldTypeLower}`, () => {
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.findByTestId('canvas-page-data-form').as('entityForm');
      cy.get('@entityForm').recordFormBuildId();

      cy.findByRole('heading', { name: config.fieldLabel })
        .closest('.js-form-wrapper')
        .as(config.fieldAlias);

      typeInRow(`@${config.fieldAlias}`, 0, config.testValues[2]);
      waitForPreview();

      typeInRow(`@${config.fieldAlias}`, 1, config.testValues[3]);

      waitForPreview();
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
        typeInRow(`@${config.fieldAlias}`, 1, config.testValues[0]);
        waitForPreview();

        confirmInputs(`@${config.fieldAlias}`, [
          config.defaultValue,
          config.testValues[0],
        ]);

        // Open popover for first item (we have 2 items now, remove should be enabled)
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .eq(0)
          .find('[class*="_listItem_"]')
          .click();

        cy.get('[role="dialog"]').should('be.visible');

        // Remove button should be enabled when there are multiple items
        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .should('not.be.disabled');

        // Close popover
        cy.get('[aria-label="Close"]').click();
        cy.get('[role="dialog"]').should('not.exist');

        // Now remove the second item to leave only one
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .eq(1)
          .find('[class*="_listItem_"]')
          .click();

        cy.get('[role="dialog"]').should('be.visible');

        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .click();

        cy.get('[role="dialog"]').should('not.exist');
        cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
        cy.waitForAjax();

        // Now we should have only one item
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .should('have.length', 1);
        confirmInputs(`@${config.fieldAlias}`, [config.defaultValue]);

        // Open popover for the only remaining item
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .eq(0)
          .find('[class*="_listItem_"]')
          .click();

        cy.get('[role="dialog"]').should('be.visible');

        // Remove button should be DISABLED because field is required and only one item exists
        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .should('be.disabled');

        // Close popover
        cy.get('[aria-label="Close"]').click();
        cy.get('[role="dialog"]').should('not.exist');
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
        typeInRow(`@${config.fieldAlias}`, 1, config.testValues[0]);
        waitForPreview();

        // Add a third item
        cy.get(`@${config.fieldAlias}`)
          .findByRole('button', { name: '+ Add new' })
          .click();

        cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
        cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

        typeInRow(`@${config.fieldAlias}`, 2, config.testValues[1]);
        waitForPreview();
        cy.waitForAjax();

        // Now we have 3 items
        confirmInputs(`@${config.fieldAlias}`, [
          config.defaultValue,
          config.testValues[0],
          config.testValues[1],
        ]);

        // Open popover for any item - remove should be enabled
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .eq(1)
          .find('[class*="_listItem_"]')
          .click();

        cy.get('[role="dialog"]').should('be.visible');

        // Remove button should be enabled when there are multiple items
        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .should('not.be.disabled');

        // Close popover without removing
        cy.get('[aria-label="Close"]').click();
        cy.get('[role="dialog"]').should('not.exist');

        // Remove one item to get down to 2 items
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .eq(2)
          .find('[class*="_listItem_"]')
          .click();

        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .click();

        cy.get('[role="dialog"]').should('not.exist');
        cy.waitForAjax();

        // Still 2 items, remove should still be enabled
        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .should('have.length', 2);

        cy.get(`@${config.fieldAlias}`)
          .find('tbody tr')
          .eq(0)
          .find('[class*="_listItem_"]')
          .click();

        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible')
          .should('not.be.disabled');

        cy.get('[aria-label="Close"]').click();
      });
    });
  });
});
