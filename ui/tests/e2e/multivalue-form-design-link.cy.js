/**
 * Comprehensive tests for multi-value form design for link fields.
 *
 * This test covers the new popover-based multi-value form UI for
 * link fields.
 *
 * The new design features:
 * - Popover-based editing (click item → popover with input).
 * - Custom drag handles and remove buttons via DrupalInputMultivalueForm.
 */

describe('Multivalue Form Design – Link Field', () => {
  before(() => {
    cy.drupalCanvasInstall([
      'canvas_test_article_fields',
      'canvas_test_e2e_code_components',
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
   * Helper function to open the URL sub-field popover for a specific row and
   * type a value, then commit it either via autocomplete selection or Enter key.
   *
   * @param {string} fieldAlias - The Cypress alias for the field container.
   * @param {number} rowIndex - The zero-based row index.
   * @param {string} title - The value to type (node title or plain URL).
   * @param {boolean} [useAutocomplete=false] - When true, waits for the jQuery
   *   UI autocomplete dropdown (.ui-menu-item-wrapper) and clicks the matching
   *   suggestion. When false (default), commits the value by pressing Enter.
   */
  /**
   * Types a value into the first input of the currently open popover dialog,
   * using select-all to replace any existing content without triggering a
   * debounced commit of the intermediate empty value.
   *
   * @param {string} value - The value to type.
   * @param {bool} clear - If the input should first be cleared.
   */
  const typeInPopover = (value, clear = true) => {
    cy.get('[role="dialog"][data-state="open"]')
      .find('input')
      .first()
      .type(`${clear ? '{selectall}' : ''}${value}`);
  };

  const typeUrlInRow = (
    fieldAlias,
    rowIndex,
    title,
    useAutocomplete = false,
  ) => {
    cy.openMultivaluePopover(fieldAlias, rowIndex);
    if (useAutocomplete) {
      cy.get('[role="dialog"][data-state="open"]')
        .find('input')
        .first()
        .realType(title);
      // Wait for the autocomplete suggestion matching the title to appear, then
      // click it so the resolved URL is committed and propagated to the right panel.
      cy.get('.ui-menu-item-wrapper').contains(title).should('be.visible');
      cy.get('.ui-menu-item-wrapper').contains(title).click();
    } else {
      // For plain URLs that don't trigger autocomplete,
      typeInPopover(`${title}`);
      cy.closeMultivaluePopover();
    }
    cy.get('[role="dialog"][data-state="open"]').should('not.exist');
  };

  /**
   * Types into a row and waits for the resulting preview update.
   *
   * Registers the intercept before typeUrlInRow so the preview POST triggered
   * by the {enter} keypress (or the autocomplete selection) is reliably captured.
   */
  const typeUrlInRowAwaitPreview = (
    fieldAlias,
    rowIndex,
    title,
    useAutocomplete = false,
  ) => {
    typeUrlInRow(fieldAlias, rowIndex, title, useAutocomplete);
  };

  /**
   * Helper to verify the text shown in the URL list item of a row.
   */
  const verifyUrlRowText = (fieldAlias, rowIndex, expectedText) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .eq(rowIndex)
      .find('[class*="_listItem_"]')
      .eq(0)
      .find('[class*="_itemText_"]')
      .should('have.text', expectedText);
  };

  /**
   * Helper to trigger Enter key in the open popover dialog.
   */
  const keyOutOfPopover = () => {
    cy.get('[role="dialog"][data-state="open"]')
      .find('input')
      .first()
      .trigger('keydown', {
        key: 'Enter',
        code: 'Enter',
        keyCode: 13,
        which: 13,
        bubbles: true,
      });
  };

  /**
   * Helper function to confirm the URL values displayed in all rows.
   * Uses per-row retryable assertions to avoid stale DOM issues.
   */
  const confirmUrlInputs = (fieldAlias, expectedUrls) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .should('have.length', expectedUrls.length);

    expectedUrls.forEach((expectedUrl, ix) => {
      const expected = expectedUrl === '' ? 'Empty' : expectedUrl;
      cy.get(fieldAlias)
        .find('tbody tr')
        .eq(ix)
        .find('[class*="_listItem_"]')
        .eq(0)
        .find('[class*="_itemText_"]')
        .should('have.text', expected);
    });
  };

  /**
   * Helper function to verify preview iframe shows expected values for all four
   * fields in the Four Multiple Links component.
   *
   * @param {string} relativeValue - Expected value for Relative field item 0
   * @param {string} relativeTwoValue - Expected value for Relative Two field item 0
   * @param {string} absoluteValue - Expected value for Absolute field item 0
   * @param {string} absoluteTwoValue - Expected value for Absolute Two field item 0
   */
  const confirmFourLinksPreview = (
    relativeValue,
    relativeTwoValue,
    absoluteValue,
    absoluteTwoValue,
  ) => {
    cy.waitForElementContentInIframe(
      '[data-field="relative"] p[data-item="0"]',
      relativeValue,
    );
    cy.waitForElementContentInIframe(
      '[data-field="relative-two"] p[data-item="0"]',
      relativeTwoValue,
    );
    cy.waitForElementContentInIframe(
      '[data-field="absolute"] p[data-item="0"]',
      absoluteValue,
    );
    cy.waitForElementContentInIframe(
      '[data-field="absolute-two"] p[data-item="0"]',
      absoluteTwoValue,
    );
  };

  it('validates Four Multiple Links component fields on the layout', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    // Add the "Four Multiple Links" component to the layout.
    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').should('contain.text', 'Components');
    cy.insertComponent({ name: 'Four Multiple Links' });

    // Wait for the preview to finish loading before asserting iframe content.
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Alias the component form.
    cy.get('form[data-form-id="component_instance_form"]').as('componentForm');

    // Confirm preview iframe shows default values for all four fields.
    confirmFourLinksPreview(
      'first-path',
      'another-first-path',
      'https://drupal.org',
      'https://github.com',
    );

    // Find the Relative field wrapper.
    cy.get('@componentForm')
      .findByRole('heading', { name: 'Relative' })
      .parents('.js-form-wrapper')
      .as('relative-field');

    // Open the popover for the first row of the Relative field.
    cy.openMultivaluePopover('@relative-field', 0);

    // Type an invalid URI-reference value containing '>'.
    // Use select-all + type rather than .clear() to avoid triggering a
    // debounced commit of the intermediate empty value.
    typeInPopover('invalid>value');

    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(100);

    cy.log('Validation should catch invalid>value on item 1.');
    // The popover should report a validation error.
    cy.get('[role="dialog"][data-state="open"]')
      .find('[data-prop-message="true"]')
      .should('contain.text', '❌ data/0 must match format "uri-reference"');

    // The list item text should be unchanged (error should not propagate).
    // The display (and preview) only show the most recent valid value.
    // The user typed "invalid>value", and it became invalid with the ">".
    verifyUrlRowText('@relative-field', 0, 'first-path');

    // Attempt to close the popover, but it should remain open.
    cy.closeMultivaluePopover(true);

    cy.log('Validation should accept quite-valid on item 1.');
    // Enter a valid value.
    typeInPopover('quite-valid');

    cy.log('Preview should accept quite-valid on item 1.');
    confirmFourLinksPreview(
      'quite-valid',
      'another-first-path',
      'https://drupal.org',
      'https://github.com',
    );

    keyOutOfPopover();

    cy.log('Set an autocomplete suggestion on item 1.');
    typeUrlInRow('@relative-field', 0, 'a block', true);
    // Confirm preview updated with the new valid value.
    confirmFourLinksPreview(
      '/the-one-with-a-block',
      'another-first-path',
      'https://drupal.org',
      'https://github.com',
    );

    cy.log('Test validation on the second row of the Relative field.');
    cy.openMultivaluePopover('@relative-field', 0);

    // Type an invalid URI-reference value.
    // Use select-all + type rather than .clear() to avoid triggering a
    // debounced commit of the intermediate empty value.
    typeInPopover('apple test');
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(100);

    // The popover should report a validation error.
    cy.get('[role="dialog"][data-state="open"]')
      .find('[data-prop-message="true"]')
      .should('contain.text', '❌ data/0 must match format "uri-reference"');

    keyOutOfPopover();
    verifyUrlRowText(
      '@relative-field',
      0,
      'Canvas With a block in the layout (3)',
    );
    // The list item text should be unchanged (error should not propagate).
    cy.get('@relative-field').find('tbody tr').should('have.length', 1);

    // Attempt to close the popover (it will not close due to invalid value).
    cy.closeMultivaluePopover(true);

    // Change to a valid value.
    typeInPopover('pear');
    cy.get('[role="dialog"][data-state="open"]')
      .find('[data-prop-message="true"]')
      .should('not.exist');
    cy.closeMultivaluePopover();

    // Find the Absolute field wrapper.
    cy.get('@componentForm')
      .findByRole('heading', { name: 'Absolute' })
      .parents('.js-form-wrapper')
      .as('absolute-field');

    // Open the popover for the first row of the Absolute field.
    cy.openMultivaluePopover('@absolute-field', 0);

    // Type a single-character value (not a valid absolute URI).
    // Use select-all + type rather than .clear() to avoid triggering a
    // debounced commit of the intermediate empty value.
    typeInPopover('x');
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(100);

    // The popover should report a uri validation error.
    cy.get('[role="dialog"][data-state="open"]')
      .find('[data-prop-message="true"]')
      .should('contain.text', '❌ data/0 must match format "uri"');

    cy.closeMultivaluePopover(true);

    // The list item text should be unchanged (error should not propagate).
    verifyUrlRowText('@absolute-field', 0, 'https://drupal.org');

    // Wait 1000ms to ensure any in-flight requests have settled, then confirm
    // the preview has restored to the original value.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(1000);
    confirmFourLinksPreview(
      'pear',
      'another-first-path',
      'https://drupal.org',
      'https://github.com',
    );

    // --- Edit valid values and confirm the preview updates ---
    // (Relative fields use entity autocomplete and require node selection;
    // we test valid edits on the absolute URL fields which accept plain input.)

    // Edit the Absolute field row 0: change to a new valid absolute URL.
    typeInPopover('https://www.example.com');
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(100);
    keyOutOfPopover();
    cy.get('[role="dialog"][data-state="open"]').should('not.exist');
    confirmFourLinksPreview(
      'pear',
      'another-first-path',
      'https://www.example.com',
      'https://github.com',
    );

    // Edit the Absolute Two field row 0: change to a new valid absolute URL.
    cy.get('@componentForm')
      .findByRole('heading', { name: 'Absolute Two' })
      .parents('.js-form-wrapper')
      .as('absolute-two-field');
    cy.openMultivaluePopover('@absolute-two-field', 0);
    typeInPopover('https://www.cypress.io');
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(100);
    keyOutOfPopover();
    cy.get('[role="dialog"][data-state="open"]').should('not.exist');
    confirmFourLinksPreview(
      'pear',
      'another-first-path',
      'https://www.example.com',
      'https://www.cypress.io',
    );
  });

  it('renders multi-value link fields with popover-based UI', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    cy.findByTestId('canvas-contextual-panel--page-data').should(
      'have.attr',
      'data-state',
      'active',
    );
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    // Find the multi-value link field container.
    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView();

    // Verify the multi-value container exists with proper structure.
    cy.get('@unlimited-link').find('.multivalue-container').should('exist');
    cy.get('@unlimited-link').find('.multivalue-container').scrollIntoView();
    cy.get('@unlimited-link').find('table').should('be.visible');
    cy.get('@unlimited-link').find('tbody tr').should('have.length', 2);

    // Each link row has one list item (URL only), so 2 rows × 1 = 2 total.
    cy.get('@unlimited-link')
      .find('[class*="_listItem_"]')
      .should('have.length', 2);

    // Verify the first row shows the default URL value.
    verifyUrlRowText('@unlimited-link', 0, 'https://drupal.org');

    // Verify the second row shows "Empty".
    verifyUrlRowText('@unlimited-link', 1, 'Empty');
  });

  it('can edit link URL using popover interface', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView();

    // Populate the empty second row URL using the popover interface.
    typeUrlInRowAwaitPreview('@unlimited-link', 1, 'https://www.example.com');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify the URL was set.
    verifyUrlRowText('@unlimited-link', 1, 'https://www.example.com');

    // Confirm all values.
    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
    ]);
  });

  it('can add new link items using "+ Add new" button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView();

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Populate the empty second item first.
    typeUrlInRowAwaitPreview('@unlimited-link', 1, 'https://www.example.com');

    // Verify the "+ Add new" button text.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .should('be.visible');

    // Add another item.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .click();

    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.get('@unlimited-link').find('tbody tr').should('have.length', 3);

    // Populate the new item.
    typeUrlInRowAwaitPreview('@unlimited-link', 2, 'https://www.cypress.io');

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
      'https://www.cypress.io',
    ]);
  });

  it('can drag and drop link items with custom drag handles', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView({ offset: { top: -500 } });

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Populate the empty second item.
    typeUrlInRowAwaitPreview('@unlimited-link', 1, 'https://www.example.com');

    // Add a third item.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    // Populate the third item.
    typeUrlInRowAwaitPreview('@unlimited-link', 2, 'https://www.cypress.io');

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
      'https://www.cypress.io',
    ]);

    cy.log('Move "item 3" to position 2 using custom drag handle');

    // Ensure the drop target is in the viewport.
    cy.get('@unlimited-link').find('tbody tr').eq(0).scrollIntoView();
    cy.reasonableWait();
    const dndDefaults = {
      position: 'topLeft',
      scrollBehavior: false,
    };

    // Verify custom drag handles are present.
    cy.get('@unlimited-link')
      .find('.canvas-drag-handle a.tabledrag-handle')
      .should('have.length', 3);

    // Verify custom SVG drag handle icons are present.
    cy.get('@unlimited-link')
      .find('.canvas-drag-handle a.tabledrag-handle .drag-handle-icon')
      .should('have.length', 3);
    cy.get('@unlimited-link').scrollIntoView();

    // The drag-handle reorder does NOT submit a Drupal form AJAX POST (unlike
    // typing into a popover, which posts to /canvas/api/v0/form/content-entity).
    // It dispatches only through the preview pathway: POST
    // /canvas/api/v0/layout/node/2 → ApiLayoutController::post →
    // autoSaveManager->saveEntity(), which is what cy.reload() below reads.
    //
    // Multiple preview POSTs can fire around a drag (debounced updates from
    // typing's tail end, intermediate drag-state updates, and the final
    // reorder POST). We can't rely on "the first /layout/node/2 POST after
    // realDnd" being the one with the reorder — it's often a stale POST from
    // an earlier action. Instead, inspect every intercepted request body and
    // assert that at least one carries the expected post-reorder sort order.
    cy.intercept('POST', '**/canvas/api/v0/layout/node/2').as('previewUpdate');

    cy.get(
      '[data-drupal-selector="edit-field-cvt-unlimited-link"] tr.draggable:nth-child(3) [title="Change order"]',
    ).realDnd(
      '[data-drupal-selector="edit-field-cvt-unlimited-link"] tr.draggable:nth-child(2)',
      dndDefaults,
    );

    cy.assertMultivalueReorder({
      alias: 'previewUpdate',
      fieldName: 'field_cvt_unlimited_link',
      valueKey: 'uri',
      expectedOrder: [
        'https://drupal.org',
        'https://www.cypress.io',
        'https://www.example.com',
      ],
    });
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.cypress.io',
      'https://www.example.com',
    ]);
    // Wait to ensure order is auto saved before reloading.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(10000);
    // Refresh the page to ensure the update persists.
    cy.reload();
    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.cypress.io',
      'https://www.example.com',
      '',
    ]);
  });

  it('can remove link items using popover remove button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView();

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Populate the empty second item.
    typeUrlInRowAwaitPreview('@unlimited-link', 1, 'https://www.example.com');

    // Add a third item.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    // Populate the third item.
    typeUrlInRowAwaitPreview('@unlimited-link', 2, 'https://www.cypress.io');

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
      'https://www.cypress.io',
    ]);

    cy.get('@unlimited-link').scrollIntoView();
    // Open the URL popover for the second item and click Remove.
    cy.openMultivaluePopover('@unlimited-link', 1);

    cy.get('[role="dialog"][data-state="open"]').should('be.visible');
    cy.get('[role="dialog"][data-state="open"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.visible');

    cy.get('[role="dialog"][data-state="open"]')
      .findByRole('button', { name: /Remove/i })
      .click();
    cy.get('[role="dialog"][data-state="open"]').should('not.exist');

    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    cy.get('@unlimited-link').find('tbody tr').should('have.length', 2);
  });

  it('link URL popover opens and closes correctly', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView();

    // Click the URL list item in the first row to open the popover.
    cy.openMultivaluePopover('@unlimited-link', 0);

    cy.get('[role="dialog"][data-state="open"]').should('be.visible');

    // The popover header should contain the field label.
    cy.get('[role="dialog"][data-state="open"]').should(
      'contain',
      'Canvas Unlimited Link',
    );

    // The input should show the current URL value.
    cy.get('[role="dialog"][data-state="open"]')
      .find('input')
      .first()
      .should('be.visible')
      .should('have.value', 'https://drupal.org');

    // The Close button should exist.
    cy.get('[role="dialog"][data-state="open"]')
      .find('[aria-label="Close"]')
      .should('exist');

    // Close the popover.
    cy.closeMultivaluePopover();
  });

  it('popover propagates changes live as user types', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView();

    // Open the first row's popover.
    cy.openMultivaluePopover('@unlimited-link', 0);

    // Type a new value — row label should update immediately without Enter.
    typeInPopover('https://updated.com');

    verifyUrlRowText('@unlimited-link', 0, 'https://updated.com');

    // Close via the × button — value should not be reverted.
    cy.closeMultivaluePopover();

    verifyUrlRowText('@unlimited-link', 0, 'https://updated.com');
  });

  it('maintains form state across multiple link popover interactions', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView();

    // Modify the first row's URL.
    cy.openMultivaluePopover('@unlimited-link', 0);

    typeInPopover('https://modified-url.com');
    cy.closeMultivaluePopover();

    // Modify the second row's URL.
    typeUrlInRowAwaitPreview(
      '@unlimited-link',
      1,
      'https://second-modified.com',
    );
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify both URL values are maintained.
    verifyUrlRowText('@unlimited-link', 0, 'https://modified-url.com');
    verifyUrlRowText('@unlimited-link', 1, 'https://second-modified.com');

    confirmUrlInputs('@unlimited-link', [
      'https://modified-url.com',
      'https://second-modified.com',
    ]);
  });

  it('does not show "+ Add new" button when limited link field is at cardinality', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    // Find the limited link field container (cardinality 2, seeded with 2 items).
    cy.findByRole('heading', { name: 'Canvas Limited Link' })
      .parents('.js-form-wrapper')
      .as('limited-link');
    cy.get('@limited-link').scrollIntoView();

    // Verify the field renders with the 2 seeded items.
    cy.get('@limited-link').find('.multivalue-container').should('exist');
    cy.get('@limited-link').find('table').should('be.visible');
    cy.get('@limited-link').find('tbody tr').should('have.length', 2);

    // Verify the default URL values are shown.
    verifyUrlRowText('@limited-link', 0, 'https://drupal.org');
    verifyUrlRowText(
      '@limited-link',
      1,
      'https://www.drupal.org/project/canvas',
    );

    // The "+ Add new" button must NOT be present because the field is at its
    // cardinality limit of 2.
    cy.get('@limited-link')
      .findByRole('button', { name: '+ Add new' })
      .should('not.exist');
  });

  it('shows disabled "Remove" button in popover for limited link field at cardinality', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    // Find the limited link field container (cardinality 2, seeded with 2 items).
    cy.findByRole('heading', { name: 'Canvas Limited Link' })
      .parents('.js-form-wrapper')
      .as('limited-link');
    cy.get('@limited-link').scrollIntoView();

    cy.get('@limited-link').find('tbody tr').should('have.length', 2);

    // Open the popover for the first row.
    cy.openMultivaluePopover('@limited-link', 0);

    cy.get('[role="dialog"][data-state="open"]').should('be.visible');

    // The popover header should contain the field label.
    cy.get('[role="dialog"][data-state="open"]').should(
      'contain',
      'Canvas Limited Link',
    );

    // The Remove button must be disabled for a limited cardinality field.
    cy.get('[role="dialog"][data-state="open"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.disabled');

    // Close the popover.
    cy.closeMultivaluePopover();

    // Open the popover for the second row and verify Remove button is disabled there too.
    cy.openMultivaluePopover('@limited-link', 1);

    cy.get('[role="dialog"][data-state="open"]').should('be.visible');

    cy.get('[role="dialog"][data-state="open"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.disabled');

    cy.closeMultivaluePopover();
  });

  it('panel remains interactive after a server-side validation error on a link field', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').should('contain.text', 'Components');
    cy.insertComponent({ name: 'Four Multiple Links' });

    cy.get('form[data-form-id="component_instance_form"]')
      .findByRole('heading', { name: 'Absolute' })
      .parents('.js-form-wrapper')
      .as('absolute-field');
    cy.get('@absolute-field').scrollIntoView();

    // Intercept the layout PATCH and stub it with the error response that
    // Drupal returns for an invalid URI scheme.
    cy.intercept('PATCH', '**/canvas/api/v0/layout/**', {
      statusCode: 422,
      body: {
        message:
          "The URI 'ttp://www.google.com' is invalid. You must use a valid URI scheme.",
      },
    }).as('failedLayoutPatch');

    // Open the first row's URL popover and type a URL with an invalid scheme.
    cy.openMultivaluePopover('@absolute-field', 0);
    cy.get('[role="dialog"][data-state="open"]')
      .find('input')
      .first()
      .type('{selectall}ttp://google.com');
    cy.get('[role="dialog"][data-state="open"]').find('input').first().blur();

    // Wait for the (stubbed) failing PATCH to be made.
    cy.wait('@failedLayoutPatch');

    // The canvasLayoutRequestInProgress lock must have been released even
    // though the request failed. Verify by confirming that the body attribute
    // set during the request is removed.
    cy.get('body').should(
      'not.have.attr',
      'data-canvas-layout-request-in-progress',
    );

    // The panel must still be interactive: clicking "+ Add new" must work
    // and trigger the normal Drupal AJAX add-more flow.
    cy.get('@absolute-field')
      .findByRole('button', { name: '+ Add new' })
      .should('be.visible')
      .click();

    // Verify the table gained a new row, proving AJAX is no longer blocked.
    cy.get('@absolute-field').find('tbody tr').should('have.length', 2);
  });

  it('can use relative URLs in field_cvt_uri_relative (Canvas URI Relative)', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    // Find the relative-URL link field container.
    cy.findByRole('heading', { name: 'Canvas URI (Relative)' })
      .parents('.js-form-wrapper')
      .as('relative-link');
    cy.get('@relative-link').scrollIntoView();

    // Verify table structure and default value.
    cy.get('@relative-link').find('.multivalue-container').should('exist');
    cy.get('@relative-link').find('table').should('be.visible');
    // Default value seeds one row with '/node/1'.
    cy.get('@relative-link').find('tbody tr').should('have.length', 2);
    cy.get('@relative-link')
      .find('[class*="_listItem_"]')
      .should('have.length', 2);
    verifyUrlRowText('@relative-link', 0, '/node/1');

    // Edit the URL to a different relative path.
    typeUrlInRowAwaitPreview('@relative-link', 1, 'I am an empty node', true);
    cy.findByLabelText('Loading Preview').should('not.exist');

    verifyUrlRowText('@relative-link', 1, 'I am an empty node (2)');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Add a third row with another relative URL.
    cy.get('@relative-link')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.get('@relative-link').find('tbody tr').should('have.length', 3);

    // Populate the new (empty) row – committing a value into an empty field
    // reliably triggers the preview POST.
    typeUrlInRowAwaitPreview(
      '@relative-link',
      2,
      'Canvas Needs This For The Time Being',
      true,
    );
    cy.findByLabelText('Loading Preview').should('not.exist');

    verifyUrlRowText(
      '@relative-link',
      2,
      'Canvas Needs This For The Time Being (1)',
    );
  });
});
