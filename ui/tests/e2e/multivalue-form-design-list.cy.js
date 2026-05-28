/**
 * Comprehensive tests for multi-value form design for List (Text) and
 * List (Integer) fields.
 */

/**
 * Loads the Canvas editor for node/2 and creates a scoped Cypress alias for
 * the React Select container of the field under test.
 *
 * @param {object} config - The field config object from the configs array.
 */
const loadPageAndFindField = (config) => {
  cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
  cy.findByTestId('canvas-contextual-panel--page-data').should(
    'have.attr',
    'data-state',
    'active',
  );
  cy.get(config.fieldNameClass).as(config.fieldAlias);
  cy.get(`@${config.fieldAlias}`).scrollIntoView();
};

/**
 * Opens the React Select dropdown by clicking the chevron indicator.
 *
 * @param {string} fieldAlias - Cypress alias (e.g. '@unlimited-list-text').
 */
const openDropdown = (fieldAlias) => {
  cy.get(fieldAlias).find('.canvas-select__dropdown-indicator').click();
  cy.get(fieldAlias).find('.canvas-select__menu').should('be.visible');
};

/**
 * Closes the React Select dropdown by clicking the chevron indicator again.
 *
 * @param {string} fieldAlias - Cypress alias.
 */
const closeDropdown = (fieldAlias) => {
  cy.get(fieldAlias).find('.canvas-select__dropdown-indicator').click();
  cy.get(fieldAlias).find('.canvas-select__menu').should('not.exist');
};

/**
 * Selects an option by its visible label text from the React Select dropdown.
 * Opens the dropdown, clicks the first non-disabled option matching the label,
 * then closes the menu.
 *
 * @param {string} fieldAlias  - Cypress alias.
 * @param {string} optionLabel - Visible text of the option to select.
 */
const selectOption = (fieldAlias, optionLabel) => {
  openDropdown(fieldAlias);
  cy.get(fieldAlias)
    .find('.canvas-select__option')
    .not('.canvas-select__option--is-disabled')
    .contains(optionLabel)
    .click();
  // Close the menu: it stays open because closeMenuOnSelect=false.
  closeDropdown(fieldAlias);
};

/**
 * Asserts that the chip labels inside the React Select exactly match
 * expectedLabels in order.
 *
 * @param {string}   fieldAlias     - Cypress alias.
 * @param {string[]} expectedLabels - Ordered list of expected chip texts.
 */
const verifyChips = (fieldAlias, expectedLabels) => {
  if (expectedLabels.length === 0) {
    cy.get(fieldAlias).find('.canvas-select__multi-value').should('not.exist');
    return;
  }
  cy.get(fieldAlias)
    .find('.canvas-select__multi-value__label')
    .should('have.length', expectedLabels.length)
    .then(($labels) => {
      const actual = [...$labels].map((el) => el.textContent.trim());
      expect(actual).to.deep.equal(expectedLabels);
    });
};

/**
 * Removes a specific chip by clicking its × remove button.
 *
 * @param {string} fieldAlias - Cypress alias.
 * @param {string} chipLabel  - The visible text of the chip to remove.
 */
const removeChip = (fieldAlias, chipLabel) => {
  cy.get(fieldAlias)
    .contains('.canvas-select__multi-value__label', chipLabel)
    .siblings('.canvas-select__multi-value__remove')
    .click({ force: true });
};

/**
 * Clicks the global "clear all" (×) indicator to deselect every chip.
 *
 * @param {string} fieldAlias - Cypress alias.
 */
const clearAll = (fieldAlias) => {
  cy.get(fieldAlias)
    .find('.canvas-select__clear-indicator')
    .click({ force: true });
};

/**
 * Registers a Cypress intercept for the canvas layout preview POST request.
 */
const interceptPreview = () => {
  cy.intercept({
    url: '**/canvas/api/v0/layout/node/2',
    times: 1,
    method: 'POST',
  }).as('updatePreview');
};

/**
 * Waits for the previously intercepted layout preview to finish.
 */
const waitForPreview = () => {
  cy.wait('@updatePreview');
  cy.findByLabelText('Loading Preview').should('not.exist');
};

const configs = [
  {
    fieldType: 'List (Text) – Unlimited',
    fieldLabel: 'Canvas Unlimited List (Text)',
    fieldAlias: 'unlimited-list-text',
    fieldNameClass: '.field--name-field-cvt-unlimited-list-string',
    initialChips: ['One'],
    pickOption: 'Two',
    pickOption2: 'Three',
    isLimited: false,
  },
  {
    fieldType: 'List (Text) – Limited (cardinality 3)',
    fieldLabel: 'Canvas Limited List (Text)',
    fieldAlias: 'limited-list-text',
    fieldNameClass: '.field--name-field-cvt-limited-list-string',
    initialChips: ['One', 'Two'],
    limitOption: 'Three',
    disabledAfterLimit: ['Four', 'Five'],
    cardinality: 3,
    isLimited: true,
  },
  {
    fieldType: 'List (Integer) – Unlimited',
    fieldLabel: 'Canvas Unlimited List (Integer)',
    fieldAlias: 'unlimited-list-integer',
    fieldNameClass: '.field--name-field-cvt-unlimited-list-integer',
    initialChips: ['1'],
    pickOption: '2',
    pickOption2: '3',
    isLimited: false,
  },
  {
    fieldType: 'List (Integer) – Limited (cardinality 3)',
    fieldLabel: 'Canvas Limited List (Integer)',
    fieldAlias: 'limited-list-integer',
    fieldNameClass: '.field--name-field-cvt-limited-list-integer',
    initialChips: ['1', '2'],
    limitOption: '3',
    disabledAfterLimit: ['4', '5'],
    cardinality: 3,
    isLimited: true,
  },
];

configs.forEach((config) => {
  const { fieldType } = config;
  const fieldTypeLower = fieldType.toLowerCase();

  describe(`Multi-value Form Design – ${fieldType}`, () => {
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

    it(`renders ${fieldTypeLower} with the React Select control and initial chip(s) from seeded default value(s)`, () => {
      loadPageAndFindField(config);

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__control')
        .should('be.visible');

      verifyChips(`@${config.fieldAlias}`, config.initialChips);

      cy.get(`@${config.fieldAlias}`).find('select[multiple]').should('exist');
    });

    it(`${fieldTypeLower} dropdown opens via the chevron indicator and closes on a second click`, () => {
      loadPageAndFindField(config);

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__dropdown-indicator')
        .click();

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__menu')
        .should('be.visible');

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__option')
        .should('have.length.at.least', 1);

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__dropdown-indicator')
        .click();

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__menu')
        .should('not.exist');
    });

    it('can select an additional value from the dropdown and triggers a canvas preview update', () => {
      loadPageAndFindField(config);

      const optionToSelect = config.isLimited
        ? config.limitOption
        : config.pickOption;

      interceptPreview();
      selectOption(`@${config.fieldAlias}`, optionToSelect);
      waitForPreview();

      verifyChips(`@${config.fieldAlias}`, [
        ...config.initialChips,
        optionToSelect,
      ]);
    });

    it('can remove an individual chip via its × button and triggers a preview update', () => {
      loadPageAndFindField(config);

      const chipToRemove = config.initialChips[0];
      interceptPreview();
      removeChip(`@${config.fieldAlias}`, chipToRemove);
      waitForPreview();

      const remaining = config.initialChips.slice(1);
      if (remaining.length === 0) {
        cy.get(`@${config.fieldAlias}`)
          .find('.canvas-select__multi-value')
          .should('not.exist');
      } else {
        verifyChips(`@${config.fieldAlias}`, remaining);
      }
    });

    it('can clear all chips using the clear-all indicator and triggers a preview update', () => {
      loadPageAndFindField(config);

      interceptPreview();
      clearAll(`@${config.fieldAlias}`);
      waitForPreview();

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__multi-value')
        .should('not.exist');

      // Check for the correct placeholder text.
      const expectedPlaceholder = `Select ${config.fieldLabel}`;

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__placeholder')
        .should('exist')
        .and('contain.text', expectedPlaceholder);
    });

    it('form state is maintained across multiple consecutive selections', function () {
      if (config.isLimited) {
        this.skip();
      }

      loadPageAndFindField(config);

      interceptPreview();
      selectOption(`@${config.fieldAlias}`, config.pickOption);
      waitForPreview();

      verifyChips(`@${config.fieldAlias}`, [
        ...config.initialChips,
        config.pickOption,
      ]);

      interceptPreview();
      selectOption(`@${config.fieldAlias}`, config.pickOption2);
      waitForPreview();

      verifyChips(`@${config.fieldAlias}`, [
        ...config.initialChips,
        config.pickOption,
        config.pickOption2,
      ]);
    });

    it('selections persist after a page reload', function () {
      if (config.isLimited) {
        this.skip();
      }

      loadPageAndFindField(config);

      interceptPreview();
      selectOption(`@${config.fieldAlias}`, config.pickOption);

      waitForPreview();

      interceptPreview();
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      selectOption(`@${config.fieldAlias}`, config.pickOption2);
      cy.wait('@updatePreview');
      cy.findByLabelText('Loading Preview').should('not.exist');
      waitForPreview();

      const expectedChips = [
        ...config.initialChips,
        config.pickOption,
        config.pickOption2,
      ];
      verifyChips(`@${config.fieldAlias}`, expectedChips);

      cy.reload();
      cy.findByTestId('canvas-contextual-panel--page-data').should(
        'have.attr',
        'data-state',
        'active',
      );
      cy.get(config.fieldNameClass).as(config.fieldAlias);
      cy.get(`@${config.fieldAlias}`).scrollIntoView();
      verifyChips(`@${config.fieldAlias}`, expectedChips);
    });

    it('remaining options are disabled in the dropdown when the cardinality limit is reached', function () {
      if (!config.isLimited) {
        this.skip();
      }

      loadPageAndFindField(config);

      interceptPreview();
      selectOption(`@${config.fieldAlias}`, config.limitOption);
      waitForPreview();

      verifyChips(`@${config.fieldAlias}`, [
        ...config.initialChips,
        config.limitOption,
      ]);

      openDropdown(`@${config.fieldAlias}`);

      config.disabledAfterLimit.forEach((disabledLabel) => {
        cy.get(`@${config.fieldAlias}`)
          .find('.canvas-select__option--is-disabled')
          .contains(disabledLabel)
          .should('exist')
          .and('have.attr', 'aria-disabled', 'true');
      });

      closeDropdown(`@${config.fieldAlias}`);
    });

    it('clicking a disabled option does not add a chip beyond the cardinality limit', function () {
      if (!config.isLimited) {
        this.skip();
      }

      loadPageAndFindField(config);

      interceptPreview();
      selectOption(`@${config.fieldAlias}`, config.limitOption);
      waitForPreview();

      verifyChips(`@${config.fieldAlias}`, [
        ...config.initialChips,
        config.limitOption,
      ]);

      openDropdown(`@${config.fieldAlias}`);
      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__option--is-disabled')
        .first()
        .click({ force: true });
      closeDropdown(`@${config.fieldAlias}`);

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__multi-value')
        .should('have.length', config.cardinality);
    });

    it('removing a chip below the cardinality limit re-enables options in the dropdown', function () {
      if (!config.isLimited) {
        this.skip();
      }

      loadPageAndFindField(config);

      interceptPreview();
      selectOption(`@${config.fieldAlias}`, config.limitOption);
      waitForPreview();

      verifyChips(`@${config.fieldAlias}`, [
        ...config.initialChips,
        config.limitOption,
      ]);

      interceptPreview();
      removeChip(`@${config.fieldAlias}`, config.limitOption);
      waitForPreview();

      verifyChips(`@${config.fieldAlias}`, config.initialChips);

      openDropdown(`@${config.fieldAlias}`);

      config.disabledAfterLimit.forEach((enabledLabel) => {
        cy.get(`@${config.fieldAlias}`)
          .find('.canvas-select__option')
          .contains(enabledLabel)
          .should('not.have.class', 'canvas-select__option--is-disabled');
      });

      closeDropdown(`@${config.fieldAlias}`);
    });

    it('clear-all re-enables all options in the dropdown', function () {
      if (!config.isLimited) {
        this.skip();
      }

      loadPageAndFindField(config);

      interceptPreview();
      selectOption(`@${config.fieldAlias}`, config.limitOption);
      waitForPreview();

      interceptPreview();
      clearAll(`@${config.fieldAlias}`);
      waitForPreview();

      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__multi-value')
        .should('not.exist');

      openDropdown(`@${config.fieldAlias}`);
      cy.get(`@${config.fieldAlias}`)
        .find('.canvas-select__option--is-disabled')
        .should('not.exist');
      closeDropdown(`@${config.fieldAlias}`);
    });
  });
});
