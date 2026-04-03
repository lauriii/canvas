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
      // @todo remove once https://drupal.org/i/3577946 is fixed.
      // Required for new multi-value form UI.
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
  const typeUrlInRow = (
    fieldAlias,
    rowIndex,
    title,
    useAutocomplete = false,
  ) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .eq(rowIndex)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();
    cy.get('[role="dialog"]').find('input').first().clear();
    if (useAutocomplete) {
      cy.get('[role="dialog"]').find('input').first().type(title);
      // Wait for the autocomplete suggestion matching the title to appear, then
      // click it so the resolved URL is committed and propagated to the right panel.
      cy.get('.ui-menu-item-wrapper')
        .contains(title)
        .should('be.visible')
        .click();
    } else {
      // For plain URLs that don't trigger autocomplete, press Enter to commit.
      cy.get('[role="dialog"]').find('input').first().type(`${title}{enter}`);
    }
    cy.get('[role="dialog"]').should('not.exist');
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

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    // Populate the empty second row URL using the popover interface.
    typeUrlInRow('@unlimited-link', 1, 'https://www.example.com');

    cy.wait('@updatePreview');
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

    const interceptPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
    };

    const waitForPreview = () => {
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    // Register intercept before action, then populate the empty second item first.
    interceptPreview();
    typeUrlInRow('@unlimited-link', 1, 'https://www.example.com');
    waitForPreview();

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

    // Register intercept before action, then populate the new item.
    interceptPreview();
    typeUrlInRow('@unlimited-link', 2, 'https://www.cypress.io');
    waitForPreview();
    cy.waitForAjax();

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

    const interceptPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
    };

    const waitForPreview = () => {
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    // Register intercept before action, then populate the empty second item.
    interceptPreview();
    typeUrlInRow('@unlimited-link', 1, 'https://www.example.com');
    waitForPreview();

    // Add a third item.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    // Register intercept before action, then populate the third item.
    interceptPreview();
    typeUrlInRow('@unlimited-link', 2, 'https://www.cypress.io');
    waitForPreview();
    cy.waitForAjax();

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
      'https://www.cypress.io',
    ]);

    cy.log('Move "item 3" to position 2 using custom drag handle');

    // Ensure the drop target is in the viewport.
    cy.get('@unlimited-link').find('tbody tr').eq(0).scrollIntoView();

    const dndDefaults = {
      position: 'center',
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
    cy.get('@unlimited-link').scrollIntoView({ offset: { top: -400 } });

    cy.get(
      '[data-drupal-selector="edit-field-cvt-unlimited-link"] tr.draggable:nth-child(3) [title="Change order"]',
    ).realDnd(
      '[data-drupal-selector="edit-field-cvt-unlimited-link"] tr.draggable:nth-child(2) [title="Change order"]',
      dndDefaults,
    );

    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.waitForAjax();
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(1000);

    // Wait for the DOM to reflect the new row order before asserting all values.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(1)
      .find('[class*="_listItem_"]')
      .eq(0)
      .find('[class*="_itemText_"]')
      .should('have.text', 'https://www.cypress.io');

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.cypress.io',
      'https://www.example.com',
    ]);
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

    const interceptPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
    };

    const waitForPreview = () => {
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    // Register intercept before action, then populate the empty second item.
    interceptPreview();
    typeUrlInRow('@unlimited-link', 1, 'https://www.example.com');
    waitForPreview();

    // Add a third item.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    // Register intercept before action, then populate the third item.
    interceptPreview();
    typeUrlInRow('@unlimited-link', 2, 'https://www.cypress.io');
    waitForPreview();
    cy.waitForAjax();

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
      'https://www.cypress.io',
    ]);

    // Open the URL popover for the second item and click Remove.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(1)
      .find('[class*="_listItem_"]')
      .eq(0)
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
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').should('be.visible');

    // The popover header should contain the field label.
    cy.get('[role="dialog"]').should('contain', 'Canvas Unlimited Link');

    // The input should show the current URL value.
    cy.get('[role="dialog"]')
      .find('input')
      .first()
      .should('be.visible')
      .should('have.value', 'https://drupal.org');

    // The Close button should exist.
    cy.get('[role="dialog"]').find('[aria-label="Close"]').should('exist');

    // Close the popover.
    cy.get('[role="dialog"]').find('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
  });

  it('popover discards uncommitted changes when closed without Enter', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');
    cy.get('@unlimited-link').scrollIntoView();

    const originalUrl = 'https://drupal.org';

    // Open the URL popover, type something, then close without pressing Enter.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').find('input').first().clear();
    cy.get('[role="dialog"]')
      .find('input')
      .first()
      .type('https://this-should-not-be-saved.com');

    cy.get('[role="dialog"]').find('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');

    // The original URL should be restored.
    verifyUrlRowText('@unlimited-link', 0, originalUrl);
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
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').find('input').first().clear();
    cy.get('[role="dialog"]')
      .find('input')
      .first()
      .type('https://modified-url.com{enter}');

    cy.get('[role="dialog"]').should('not.exist');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    // Modify the second row's URL.
    typeUrlInRow('@unlimited-link', 1, 'https://second-modified.com');

    cy.wait('@updatePreview');
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
    cy.get('@limited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').should('be.visible');

    // The popover header should contain the field label.
    cy.get('[role="dialog"]').should('contain', 'Canvas Limited Link');

    // The Remove button must be disabled for a limited cardinality field.
    cy.get('[role="dialog"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.disabled');

    // Close the popover.
    cy.get('[role="dialog"]').find('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');

    // Open the popover for the second row and verify Remove button is disabled there too.
    cy.get('@limited-link')
      .find('tbody tr')
      .eq(1)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').should('be.visible');

    cy.get('[role="dialog"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.disabled');

    cy.get('[role="dialog"]').find('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
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

    const interceptPreview = (alias) => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as(alias);
    };

    const waitForPreview = (alias) => {
      cy.get(document.activeElement).blur();
      cy.wait(`@${alias}`);
      cy.findByLabelText('Loading Preview').should('not.exist');
    };

    // Register intercept before action, then edit the URL to a different relative path.
    interceptPreview('updatePreview');
    typeUrlInRow('@relative-link', 1, 'I am an empty node', true);
    waitForPreview('updatePreview');

    verifyUrlRowText('@relative-link', 1, 'I am an empty node (2)');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Add a third row with another relative URL.
    cy.get('@relative-link')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.get('@relative-link').find('tbody tr').should('have.length', 3);

    // Register intercept before populating the new (empty) row – committing a
    // value into an empty field reliably triggers the preview POST.
    interceptPreview('updatePreview');
    typeUrlInRow(
      '@relative-link',
      2,
      'Canvas Needs This For The Time Being',
      true,
    );
    waitForPreview('updatePreview');

    verifyUrlRowText(
      '@relative-link',
      2,
      'Canvas Needs This For The Time Being (1)',
    );
  });
});
