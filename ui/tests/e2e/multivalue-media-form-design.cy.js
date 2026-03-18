/**
 * Tests the multivalue media field UI for the media_library_widget.
 */

// @todo Remove canvas_dev_mode condition as part of https://www.drupal.org/node/3577946
describe('Multivalue Media Form Design (canvas_dev_mode)', () => {
  before(() => {
    cy.drupalCanvasInstall(['canvas_test_article_fields', 'canvas_dev_mode']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('renders multivalue media field with vertical list UI', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    cy.findByTestId('canvas-contextual-panel--page-data').should(
      'have.attr',
      'data-state',
      'active',
    );

    cy.get('[data-drupal-selector="edit-field-cvt-unlimited-media"]').as(
      'unlimited-media',
    );

    cy.get('@unlimited-media')
      .find('.js-media-library-selection')
      .should('exist');

    // There should be 2 seeded media items.
    cy.get('@unlimited-media')
      .find('.js-media-library-item')
      .should('have.length', 2);

    cy.get('@unlimited-media')
      .findByAltText('The bones equal dollars')
      .should('exist');
    cy.get('@unlimited-media')
      .findByAltText('My barber may have been looking at a picture of a dog')
      .should('exist');

    // Each item should have a drag handle for reordering.
    cy.get('@unlimited-media')
      .find('[aria-label="Drag to reorder"]')
      .should('have.length', 2);

    // Each item should have an inline remove button.
    cy.get('@unlimited-media')
      .find('[data-canvas-media-remove-button]')
      .should('have.length', 2);

    cy.get('@unlimited-media')
      .findByRole('button', { name: 'Add media', timeout: 10000 })
      .should('be.visible');

    // Canvas replaces Drupal's weight toggle with drag-to-reorder.
    cy.get('@unlimited-media')
      .find('.js-media-library-widget-toggle-weight')
      .should('not.exist');
  });

  it('can remove a media item with the inline delete button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.get('[data-drupal-selector="edit-field-cvt-unlimited-media"]').as(
      'unlimited-media',
    );

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    cy.get('@unlimited-media')
      .find('.js-media-library-item')
      .should('have.length', 2);

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    cy.get('@unlimited-media')
      .find('.js-media-library-item')
      .first()
      .find('[data-canvas-media-remove-button]')
      .click();

    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    cy.get('@unlimited-media')
      .find('.js-media-library-item')
      .should('have.length', 1);
  });

  it('can add a new media item via the Add media button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.get('[data-drupal-selector="edit-field-cvt-unlimited-media"]').as(
      'unlimited-media',
    );

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    cy.get('@unlimited-media')
      .find('.js-media-library-item')
      .should('have.length', 2);

    cy.get('@unlimited-media')
      .findByRole('button', { name: 'Add media', timeout: 10000 })
      .should('not.be.disabled')
      .click();

    cy.findByRole('dialog', { timeout: 10000 }).as('dialog');
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);

    cy.get('@dialog')
      .findByLabelText('Select The bones are their money')
      .check();

    cy.intercept('POST', '**/canvas/api/v0/layout/**').as('updatePreview');

    cy.get('@dialog').findByRole('button', { name: 'Insert selected' }).click();

    cy.findByRole('dialog').should('not.exist');

    cy.wait('@updatePreview', { timeout: 10000 });
    cy.findByLabelText('Loading Preview').should('not.exist');

    cy.get('@unlimited-media')
      .find('.js-media-library-item')
      .should('have.length', 3);

    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
  });

  it('can reorder items with drag handles', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    cy.get('[data-drupal-selector="edit-field-cvt-unlimited-media"]').as(
      'unlimited-media',
    );

    cy.get('@unlimited-media')
      .find('.js-media-library-item')
      .should('have.length', 2);

    cy.get('@unlimited-media')
      .find('[aria-label="Drag to reorder"]')
      .should('have.length', 2);

    cy.get('@unlimited-media')
      .find('[aria-label="Drag to reorder"]')
      .eq(0)
      .scrollIntoView();

    // Use keyboard drag and drop to reorder the items.
    const itemSelector =
      '[data-drupal-selector="edit-field-cvt-unlimited-media-wrapper"]';

    const dragSelector = `${itemSelector} [data-item-index="0"] [aria-label="Drag to reorder"]`;
    // Confirm initial order of items.
    cy.get(
      `${itemSelector} [data-item-index="0"] .canvas-media-preview-label`,
    ).should('include.text', 'The bones are their money');
    cy.get(
      `${itemSelector} [data-item-index="1"] .canvas-media-preview-label`,
    ).should('include.text', 'Sorry I resemble a dog');

    cy.get(dragSelector).focus();
    cy.get(dragSelector).realPress('Enter', { position: 'center' });
    cy.get(dragSelector).should('have.attr', 'data-canvas-is-dragging', 'true');
    cy.get(dragSelector).realPress('ArrowDown', { position: 'center' });
    cy.get(dragSelector).realPress('Enter', { position: 'center' });

    cy.get(
      `${itemSelector} [data-item-index="0"] .canvas-media-preview-label`,
    ).should('include.text', 'The bones are their money');
    cy.get(
      `${itemSelector} [data-item-index="1"] .canvas-media-preview-label`,
    ).should('include.text', 'Sorry I resemble a dog');
  });

  it('shows cardinality message for limited media field', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    // field_cvt_limited_media has cardinality 2 and is seeded with 2 items.
    cy.get('[data-drupal-selector="edit-field-cvt-limited-media"]').as(
      'limited-media',
    );

    cy.get('@limited-media')
      .find('.js-media-library-item')
      .should('have.length', 2);

    cy.get('@limited-media')
      .find('.description')
      .should(
        'contain.text',
        'The maximum number of media items have been selected.',
      );

    cy.intercept('POST', '**/canvas/api/v0/layout/**').as('updatePreview');

    cy.get('@limited-media')
      .find('.js-media-library-item')
      .first()
      .find('[data-canvas-media-remove-button]')
      .click();

    cy.selectorShouldHaveUpdatedFormBuildId(
      '[data-testid="canvas-page-data-form"]',
    );
    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    cy.get('@limited-media')
      .find('.js-media-library-item')
      .should('have.length', 1);

    cy.get('@limited-media')
      .find('.description')
      .should('contain.text', 'One media item remaining.');
  });
});
