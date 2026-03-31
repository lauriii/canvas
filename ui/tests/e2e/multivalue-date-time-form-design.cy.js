/**
 * Comprehensive test for multivalue datetime form.
 *
 * This test covers the popover-based multivalue datetime form UI.
 *
 * The design features:
 * - Collapsed list items showing date/time previews.
 * - Popover-based editing (click item → popover with date and time inputs).
 * - Custom drag handles and remove buttons via DrupalDatetimeMultivalueForm component.
 */

describe('Multivalue DateTime Form Design (canvas_dev_mode)', () => {
  before(() => {
    cy.drupalCanvasInstall([
      'canvas_test_article_fields',
      // @todo remove once https://drupal.org/i/3577946 is fixed.
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
   * Helper function to open popover for a specific row and set date/time.
   */
  const getEditableRows = (fieldAlias) => {
    return cy
      .get(fieldAlias)
      .find('tbody tr')
      .filter((_, row) => {
        return Cypress.$(row).find('[class*="_listItem_"]').length > 0;
      });
  };

  const openEditableRowPopover = (fieldAlias, rowIndex) => {
    getEditableRows(fieldAlias)
      .eq(rowIndex)
      .find('[class*="_listItem_"]')
      .click();

    cy.get('[role="dialog"]').should('be.visible').as('datetimePopover');
  };

  const commitPopoverWithEnter = (inputSelector) => {
    cy.get('@datetimePopover').find(inputSelector).first().trigger('keydown', {
      key: 'Enter',
      code: 'Enter',
      which: 13,
      keyCode: 13,
      bubbles: true,
      force: true,
    });
  };

  const setDateTimeInRow = (fieldAlias, rowIndex, date, time) => {
    // Open popover from editable rows only (ignores utility/placeholder table rows).
    openEditableRowPopover(fieldAlias, rowIndex);

    // Set the date value.
    if (date !== null) {
      cy.get('@datetimePopover').find('input[type="date"]').clear();
      cy.get('@datetimePopover').find('input[type="date"]').type(date);
      cy.get('@datetimePopover')
        .find('input[type="date"]')
        .should('have.value', date);
    }

    // Set the time value (if provided and time input exists).
    if (time !== null) {
      cy.get('@datetimePopover')
        .find('input[type="time"]')
        .then(($timeInput) => {
          if ($timeInput.length > 0) {
            cy.wrap($timeInput).clear();
            cy.wrap($timeInput).type(time);
            cy.wrap($timeInput).should('have.value', time);
            commitPopoverWithEnter('input[type="time"]');
            return;
          }

          commitPopoverWithEnter('input[type="date"]');
        });
    } else {
      commitPopoverWithEnter('input[type="date"]');
    }

    // Wait for popover to close after Enter.
    cy.get('[role="dialog"]').should('not.exist');
  };

  const findEditableEmptyRowIndex = (fieldAlias) => {
    return getEditableRows(fieldAlias).then(($editableRows) => {
      const editableRows = Array.from($editableRows);
      return editableRows.findIndex((row) => {
        return (
          Cypress.$(row).find('[class*="_itemText_"]').text().trim() === 'Empty'
        );
      });
    });
  };

  const setDateTimeInFirstEmptyRow = (fieldAlias, date, time) => {
    findEditableEmptyRowIndex(fieldAlias).then((emptyIndex) => {
      expect(emptyIndex).to.be.greaterThan(-1);
      setDateTimeInRow(fieldAlias, emptyIndex, date, time);
    });
  };

  /**
   * Helper function to set only date (for date-only fields).
   */
  const setDateInRow = (fieldAlias, rowIndex, date) => {
    setDateTimeInRow(fieldAlias, rowIndex, date, null);
  };

  /**
   * Helper function to verify date/time content in a row.
   */
  const verifyRowDateTime = (fieldAlias, rowIndex, expectedText) => {
    getEditableRows(fieldAlias)
      .eq(rowIndex)
      .find('[class*="_itemText_"]')
      .should('have.text', expectedText);
  };

  /**
   * Helper function to format date using Intl.DateTimeFormat.
   */
  const formatDateForDisplay = (dateStr) => {
    if (!dateStr) return '';
    try {
      // Parse ISO date string (YYYY-MM-DD) and format using browser's locale.
      const date = new Date(dateStr + 'T00:00:00');
      return new Intl.DateTimeFormat().format(date);
    } catch {
      return dateStr;
    }
  };

  /**
   * Helper function to format time using Intl.DateTimeFormat.
   */
  const formatTimeForDisplay = (timeStr) => {
    if (!timeStr) return '';
    try {
      // Create a date with the time value to format it.
      const date = new Date(`2000-01-01T${timeStr}`);
      // Only show seconds if the value explicitly includes non-zero seconds.
      // Browsers normalize time input values to include ':00' seconds even when
      // only HH:MM was typed, so we check for non-zero seconds explicitly.
      const parts = timeStr.split(':');
      const hasNonZeroSeconds =
        parts.length === 3 && parts[2] !== '00' && parts[2] !== '00.000';
      return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: 'numeric',
        second: hasNonZeroSeconds ? 'numeric' : undefined,
        hour12: true,
      }).format(date);
    } catch {
      return timeStr;
    }
  };

  /**
   * Helper function to confirm the contents of all rows by checking
   * the list item text content (visible in the collapsed state).
   */
  const confirmDateTimeInputs = (fieldAlias, expectedValues) => {
    // First, wait for the list items to be present (using CSS module class prefix).
    cy.get(fieldAlias)
      .find('[class*="_listItem_"]')
      .should('have.length', expectedValues.length);

    cy.get(fieldAlias)
      .find('tbody tr')
      .should('have.length', expectedValues.length)
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
        expect(items).to.deep.equal(expectedValues);
      });
  };

  /**
   * Helper to assert number of committed (non-placeholder) items.
   */
  const assertCommittedItemCount = (fieldAlias, expectedCount) => {
    cy.get(fieldAlias)
      .find('[class*="_itemText_"]')
      .then(($items) => {
        const committed = Array.from($items).filter((item) => {
          const text = Cypress.$(item).text().trim();
          return text && text !== 'Empty';
        });
        expect(committed).to.have.length(expectedCount);
      });
  };

  const getCommittedItemCount = (fieldAlias) => {
    return cy
      .get(fieldAlias)
      .find('[class*="_itemText_"]')
      .then(($items) => {
        return Array.from($items).filter((item) => {
          const text = Cypress.$(item).text().trim();
          return text && text !== 'Empty';
        }).length;
      });
  };

  it('renders multivalue datetime fields with popover-based UI', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    cy.findByTestId('canvas-contextual-panel--page-data').should(
      'have.attr',
      'data-state',
      'active',
    );
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    // Find the multivalue datetime field container.
    cy.findByRole('heading', { name: 'Canvas Unlimited DateTime' })
      .closest('.js-form-wrapper')
      .as('unlimited-datetime');

    // Verify the multivalue container exists with proper structure.
    cy.get('@unlimited-datetime').find('.multivalue-container').should('exist');
    cy.get('@unlimited-datetime').find('table').scrollIntoView();
    cy.get('@unlimited-datetime').find('table').should('be.visible');
    cy.get('@unlimited-datetime')
      .find('tbody tr')
      .should('have.length.at.least', 1);

    // Verify list items are visible (collapsed state).
    cy.get('@unlimited-datetime')
      .find('[class*="_listItem_"]')
      .should('have.length.at.least', 1);
  });

  it('can edit multivalue datetime items using popover interface', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited DateTime' })
      .closest('.js-form-wrapper')
      .as('unlimited-datetime');

    cy.intercept('POST', '**/canvas/api/v0/form/content-entity/**');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    findEditableEmptyRowIndex('@unlimited-datetime').then((emptyRowIndex) => {
      if (emptyRowIndex !== -1) {
        setDateTimeInRow(
          '@unlimited-datetime',
          emptyRowIndex,
          '2024-03-15',
          '14:30',
        );
        return;
      }

      cy.get('@unlimited-datetime')
        .findByRole('button', { name: '+ Add new' })
        .click();
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
      setDateTimeInFirstEmptyRow('@unlimited-datetime', '2024-03-15', '14:30');
    });

    // Wait for the preview to finish loading.
    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify the value was set (formatted for display).
    cy.get('@unlimited-datetime')
      .find('[class*="_itemText_"]')
      .should('contain', formatDateForDisplay('2024-03-15'));
  });

  it('can edit date-only fields using popover interface', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    // Find a date-only field (adjust field name as needed).
    cy.findByRole('heading', { name: 'Canvas Unlimited Date' })
      .closest('.js-form-wrapper')
      .as('unlimited-date');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    findEditableEmptyRowIndex('@unlimited-date').then((emptyRowIndex) => {
      if (emptyRowIndex !== -1) {
        setDateInRow('@unlimited-date', emptyRowIndex, '2024-06-20');
        return;
      }

      cy.get('@unlimited-date')
        .findByRole('button', { name: '+ Add new' })
        .click();
      cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
      setDateTimeInFirstEmptyRow('@unlimited-date', '2024-06-20', null);
    });

    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify the date was set (date-only, no time).
    const expectedDisplay = formatDateForDisplay('2024-06-20');
    cy.get('@unlimited-date')
      .find('[class*="_itemText_"]')
      .should('contain', expectedDisplay);
  });

  it('can add new datetime items using "+ Add new" button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited DateTime' })
      .closest('.js-form-wrapper')
      .as('unlimited-datetime');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Get initial row count.
    cy.get('@unlimited-datetime')
      .find('tbody tr')
      .then(($initialRows) => {
        const initialCount = $initialRows.length;

        // Register intercept BEFORE the action that triggers the preview
        // update.
        cy.intercept({
          url: '**/canvas/api/v0/layout/node/2',
          times: 1,
          method: 'POST',
        }).as('updatePreview');

        // Set first datetime in the first editable empty row.
        setDateTimeInFirstEmptyRow(
          '@unlimited-datetime',
          '2024-01-10',
          '09:00',
        );
        cy.wait('@updatePreview');
        cy.waitForAjax();

        // Verify the new button text.
        cy.get('@unlimited-datetime')
          .findByRole('button', { name: '+ Add new' })
          .should('be.visible');

        // Add another item.
        cy.get('@unlimited-datetime')
          .findByRole('button', { name: '+ Add new' })
          .click();

        cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
        cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

        // Verify row count increased.
        cy.get('@unlimited-datetime')
          .find('tbody tr')
          .should('have.length', initialCount + 1);

        // Register intercept before the second action.
        cy.intercept({
          url: '**/canvas/api/v0/layout/node/2',
          times: 1,
          method: 'POST',
        }).as('updatePreview');

        // Populate the new item in the new empty row.
        setDateTimeInFirstEmptyRow(
          '@unlimited-datetime',
          '2024-02-15',
          '13:45',
        );
        cy.wait('@updatePreview');
        cy.waitForAjax();

        // Verify both values are set.
        cy.get('@unlimited-datetime')
          .find('[class*="_itemText_"]')
          .should('contain', formatDateForDisplay('2024-01-10'));
        cy.get('@unlimited-datetime')
          .find('[class*="_itemText_"]')
          .should('contain', formatDateForDisplay('2024-02-15'));
      });
  });

  it('can drag and drop datetime items with custom drag handles', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited DateTime' })
      .closest('.js-form-wrapper')
      .as('unlimited-datetime');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Set up two datetime items. Register intercept BEFORE each action that
    // triggers the preview update.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    setDateTimeInFirstEmptyRow('@unlimited-datetime', '2024-01-01', '10:00');
    cy.wait('@updatePreview');
    cy.waitForAjax();

    cy.get('@unlimited-datetime')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    setDateTimeInFirstEmptyRow('@unlimited-datetime', '2024-02-01', '11:00');
    cy.wait('@updatePreview');
    cy.waitForAjax();

    // Store original order.
    const date1 = formatDateForDisplay('2024-01-01');
    const date2 = formatDateForDisplay('2024-02-01');

    cy.log('Move item 2 to position 1 using custom drag handle');

    // Ensure the drop target is in the viewport.
    cy.get('@unlimited-datetime').find('tbody tr').eq(0).scrollIntoView();

    const dndDefaults = {
      position: 'topLeft',
      scrollBehavior: false,
    };

    // Verify custom drag handles are present.
    cy.get('@unlimited-datetime')
      .find('.canvas-drag-handle a.tabledrag-handle')
      .should('have.length.at.least', 2);

    // Verify custom SVG icon is present in drag handles.
    cy.get('@unlimited-datetime')
      .find('.canvas-drag-handle a.tabledrag-handle .drag-handle-icon')
      .should('have.length.at.least', 2);

    // Perform drag and drop by targeting rows containing the specific dates.
    cy.get('@unlimited-datetime')
      .find('tbody tr')
      .then(($rows) => {
        const sourceRowIndex = Array.from($rows).findIndex((row) => {
          return Cypress.$(row)
            .find('[class*="_itemText_"]')
            .text()
            .includes(date2);
        });
        const targetRowIndex = Array.from($rows).findIndex((row) => {
          return Cypress.$(row)
            .find('[class*="_itemText_"]')
            .text()
            .includes(date1);
        });

        expect(sourceRowIndex).to.be.greaterThan(-1);
        expect(targetRowIndex).to.be.greaterThan(-1);

        cy.get('@unlimited-datetime')
          .invoke('attr', 'data-drupal-selector')
          .then((selector) => {
            cy.get(
              `[data-drupal-selector="${selector}"] tbody tr:nth-child(${sourceRowIndex + 1}) [title="Change order"]`,
            ).realDnd(
              `[data-drupal-selector="${selector}"] tbody tr:nth-child(${targetRowIndex + 1})`,
              dndDefaults,
            );
          });
      });

    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.waitForAjax();

    // Verify date2 appears before date1 after drag and drop.
    cy.get('@unlimited-datetime')
      .find('[class*="_itemText_"]')
      .then(($items) => {
        const committedTexts = Array.from($items)
          .map((item) => Cypress.$(item).text().trim())
          .filter((text) => text && text !== 'Empty');
        expect(
          committedTexts.findIndex((text) => text.includes(date2)),
        ).to.be.lessThan(
          committedTexts.findIndex((text) => text.includes(date1)),
        );
      });
  });

  it('can remove datetime items using popover remove button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited DateTime' })
      .closest('.js-form-wrapper')
      .as('unlimited-datetime');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Set up two items. Register intercept BEFORE each action that triggers
    // the preview update.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    setDateTimeInFirstEmptyRow('@unlimited-datetime', '2024-01-01', '10:00');
    cy.wait('@updatePreview');
    cy.waitForAjax();

    cy.get('@unlimited-datetime')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    setDateTimeInFirstEmptyRow('@unlimited-datetime', '2024-02-01', '11:00');
    cy.wait('@updatePreview');
    cy.waitForAjax();

    // Get initial row count and verify removal works.
    assertCommittedItemCount('@unlimited-datetime', 2);

    // Open the popover for the first item and verify the Remove button.
    openEditableRowPopover('@unlimited-datetime', 0);

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

    // Verify row count decreased.
    assertCommittedItemCount('@unlimited-datetime', 1);
  });

  it('popover opens and closes correctly for datetime fields', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited DateTime' })
      .closest('.js-form-wrapper')
      .as('unlimited-datetime');

    // Click the first item to open popover.
    openEditableRowPopover('@unlimited-datetime', 0);

    cy.get('[role="dialog"]').should('be.visible');

    // Verify popover shows field label.
    cy.get('[role="dialog"]').should('contain', 'Canvas Unlimited DateTime');

    // Verify date input is visible.
    cy.get('[role="dialog"]').find('input[type="date"]').should('be.visible');

    // Verify time input is visible (for datetime fields).
    cy.get('[role="dialog"]').find('input[type="time"]').should('be.visible');

    // Verify close button exists.
    cy.get('[role="dialog"]').find('[aria-label="Close"]').should('exist');

    // Close the popover.
    cy.get('[role="dialog"]').find('[aria-label="Close"]');
    cy.get('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
  });

  it('popover discards uncommitted datetime changes when closed without Enter', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited DateTime' })
      .closest('.js-form-wrapper')
      .as('unlimited-datetime');

    // First set a value.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    setDateTimeInRow('@unlimited-datetime', 0, '2024-05-10', '15:30');
    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    const originalDisplay = `${formatDateForDisplay('2024-05-10')}, ${formatTimeForDisplay('15:30')}`;

    // Open popover and change values without committing.
    openEditableRowPopover('@unlimited-datetime', 0);

    cy.get('[role="dialog"]').find('input[type="date"]').clear();
    cy.get('[role="dialog"]').find('input[type="date"]').type('2024-12-25');
    cy.get('[role="dialog"]').find('input[type="time"]').clear();
    cy.get('[role="dialog"]').find('input[type="time"]').type('23:59');

    // Close without pressing Enter.
    cy.get('[role="dialog"]').find('[aria-label="Close"]');
    cy.get('[aria-label="Close"]').click();

    cy.get('[role="dialog"]').should('not.exist');

    // Verify original value is retained.
    verifyRowDateTime('@unlimited-datetime', 0, originalDisplay);
  });

  it('maintains form state across datetime popover interactions', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited DateTime' })
      .closest('.js-form-wrapper')
      .as('unlimited-datetime');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      method: 'POST',
    }).as('updatePreview');

    // Set first datetime value.
    setDateTimeInRow('@unlimited-datetime', 0, '2024-03-15', '09:30');
    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Add and set second datetime value.
    cy.get('@unlimited-datetime')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    setDateTimeInRow('@unlimited-datetime', 1, '2024-04-20', '14:45');
    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify both items maintain their values.
    const display1 = `${formatDateForDisplay('2024-03-15')}, ${formatTimeForDisplay('09:30')}`;
    const display2 = `${formatDateForDisplay('2024-04-20')}, ${formatTimeForDisplay('14:45')}`;

    verifyRowDateTime('@unlimited-datetime', 0, display1);
    verifyRowDateTime('@unlimited-datetime', 1, display2);
  });

  it('date-only popover does not show time input', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    // Find a date-only field.
    cy.findByRole('heading', { name: 'Canvas Unlimited Date' })
      .closest('.js-form-wrapper')
      .as('unlimited-date');

    // Click the first item to open popover.
    openEditableRowPopover('@unlimited-date', 0);

    cy.get('[role="dialog"]').should('be.visible');

    // Verify date input is visible.
    cy.get('[role="dialog"]').find('input[type="date"]').should('be.visible');

    // Verify time input does NOT exist for date-only fields.
    cy.get('[role="dialog"]').find('input[type="time"]').should('not.exist');

    // Close the popover.
    cy.get('[role="dialog"]').find('[aria-label="Close"]');
    cy.get('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
  });

  it('remove button is disabled for required field with only one item', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Required DateTime' })
      .closest('.js-form-wrapper')
      .as('required-datetime');

    // Set one datetime value (required field must have at least one value).
    // Register intercept BEFORE the action that triggers the preview update.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    setDateTimeInFirstEmptyRow('@required-datetime', '2024-01-01', '10:00');
    cy.wait('@updatePreview');
    cy.waitForAjax();

    // Verify at least one committed value exists.
    getCommittedItemCount('@required-datetime').then((count) => {
      expect(count).to.be.greaterThan(0);
      cy.wrap(count).as('requiredCountBeforePopover');
    });

    // Open the popover for the only item.
    openEditableRowPopover('@required-datetime', 0);

    cy.get('[role="dialog"]').should('be.visible');

    // Verify button state reflects the current count.
    cy.get('@requiredCountBeforePopover').then((count) => {
      if (count === 1) {
        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.disabled');
      } else {
        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible');
      }
    });

    // Close the popover.
    cy.get('[role="dialog"]').find('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
  });

  it('remove button is enabled for required field with multiple items', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Required DateTime' })
      .closest('.js-form-wrapper')
      .as('required-datetime');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Set first datetime value. Register intercept BEFORE the action that
    // triggers the preview update.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    setDateTimeInFirstEmptyRow('@required-datetime', '2024-01-01', '10:00');
    cy.wait('@updatePreview');
    cy.waitForAjax();

    // Add second item.
    cy.get('@required-datetime')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    // Set second datetime value.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    setDateTimeInFirstEmptyRow('@required-datetime', '2024-02-01', '11:00');
    cy.wait('@updatePreview');
    cy.waitForAjax();

    // Verify multiple committed values exist.
    getCommittedItemCount('@required-datetime').then((countBeforeRemove) => {
      expect(countBeforeRemove).to.be.greaterThan(1);
      cy.wrap(countBeforeRemove).as('countBeforeRemove');
    });

    // Open the popover for the first item.
    openEditableRowPopover('@required-datetime', 0);

    cy.get('[role="dialog"]').should('be.visible');

    // Verify Remove button IS visible (enabled for required field with multiple items).
    cy.get('[role="dialog"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.visible');

    // Click remove button to verify it works.
    cy.get('[role="dialog"]')
      .findByRole('button', { name: /Remove/i })
      .click();

    cy.get('[role="dialog"]').should('not.exist');

    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.waitForAjax();

    // Verify one item was removed.
    cy.get('@countBeforeRemove').then((countBeforeRemove) => {
      getCommittedItemCount('@required-datetime').then((countAfterRemove) => {
        expect(countAfterRemove).to.be.lessThan(countBeforeRemove);
        expect(countAfterRemove).to.be.greaterThan(0);
      });
    });

    // Now open the popover again for the remaining item.
    openEditableRowPopover('@required-datetime', 0);

    cy.get('[role="dialog"]').should('be.visible');

    // Verify button state matches remaining item count.
    getCommittedItemCount('@required-datetime').then((remainingCount) => {
      if (remainingCount === 1) {
        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.disabled');
      } else {
        cy.get('[role="dialog"]')
          .findByRole('button', { name: /Remove/i })
          .should('be.visible');
      }
    });

    // Close the popover.
    cy.get('[role="dialog"]').find('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
  });
});
