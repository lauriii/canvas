describe('Prop with autocomplete', () => {
  before(() => {
    cy.drupalCanvasInstall(['canvas_test_autocomplete']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('has a working autocomplete in the props form', () => {
    cy.loadURLandWaitForCanvasLoaded();
    cy.get('iframe[data-canvas-preview]').should('exist');
    cy.get(
      `#canvasPreviewOverlay .canvas--viewport-overlay .canvas--region-overlay__content`,
    )
      .findAllByLabelText('Hero')
      .eq(0)
      .click({ force: true });
    cy.get('[data-drupal-selector="edit-test-autocomplete"]').should('exist');
    cy.get('[data-drupal-selector="edit-test-autocomplete"]').type('Ban', {
      force: true,
    });
    cy.get('ul.ui-autocomplete').should('exist');
    cy.get('ul.ui-autocomplete li').should('have.text', 'Banana');
    cy.get('ul.ui-autocomplete li').click();
    cy.get('[data-drupal-selector="edit-test-autocomplete"]').should(
      'have.value',
      'banana',
    );
  });

  it('Auto-complete works with a config entity: does not rewrite non-link entity_autocomplete values with `entity:node/`', () => {
    cy.loadURLandWaitForCanvasLoaded();
    cy.get('iframe[data-canvas-preview]').should('exist');
    cy.get(
      `#canvasPreviewOverlay .canvas--viewport-overlay .canvas--region-overlay__content`,
    )
      .findAllByLabelText('Hero')
      .eq(0)
      .click({ force: true });

    // Confirm the autocomplete can resolve config entity references that do not
    // adhere to the `entity:node/` URI scheme.
    cy.get(
      '[data-drupal-selector="edit-test-image-style-autocomplete"]',
    ).should('exist');
    cy.get('[data-drupal-selector="edit-test-image-style-autocomplete"]').type(
      'Thumb',
      { force: true },
    );
    cy.get('ul.ui-autocomplete').should('exist');
    cy.get('ul.ui-autocomplete li')
      .contains('Thumbnail')
      .should('be.visible')
      .click();
    cy.get('[data-drupal-selector="edit-test-image-style-autocomplete"]')
      .invoke('val')
      .should('match', /\(thumbnail\)$/)
      .and('not.match', /^entity:node\//);
  });

  it('Works with link fields', () => {
    cy.loadURLandWaitForCanvasLoaded();
    cy.openLibraryPanel();
    cy.insertComponent({ name: 'Hero' });

    cy.findByLabelText('CTA 1 link')
      .as('linkField')
      .should('have.value', 'https://example.com')
      .click();
    // @see CanvasTestSetup, there is a node with title
    // 'Canvas With a block in the layout'
    cy.get('@linkField').clear();
    cy.get('@linkField').type('Canvas With a block');
    cy.get('ul.ui-autocomplete').should('exist');
    cy.get('ul.ui-autocomplete li').should(
      'have.text',
      'Canvas With a block in the layout',
    );
    cy.intercept('PATCH', '**/canvas/api/layout/node/1').as('patchPreview');
    cy.get('ul.ui-autocomplete li').click();
    cy.get('@linkField').should('have.attr', 'value', 'entity:node/3');

    cy.get('@linkField').should(
      'have.value',
      'Canvas With a block in the layout (3)',
    );
    cy.get('@linkField').blur();
    // Wait for the preview to update.
    cy.waitFor('@patchPreview');

    cy.waitForElementContentInIframe(
      '[data-component-id="canvas_test_sdc:my-hero"] a[href*="/the-one-with-a-block"]',
      'View',
    );
  });
});
