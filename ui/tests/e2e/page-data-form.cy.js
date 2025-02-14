describe('Page data form', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Loads and displays the article node form', () => {
    cy.get('#xbPreviewOverlay .xb--viewport-overlay')
      .first()
      .as('desktopPreviewOverlay');
    cy.get('.primaryPanelContent').as('layersTree');
    cy.get('@layersTree').findByText('Two Column').should('exist');
    // Open the right sidebar by clicking on a component.
    cy.clickComponentInPreview('Hero');
    // Open the page data form by clicking on the "Page data" tab in the sidebar.
    cy.findByTestId('xb-contextual-panel--page-data').click();
    cy.findByTestId('xb-page-data-form')
      .findByLabelText('Title')
      .should('have.value', 'XB Needs This For The Time Being');

    // Type a new value into the title field.
    cy.findByTestId('xb-page-data-form')
      .findByLabelText('Title')
      .as('titleField');
    cy.get('@titleField').focus();
    cy.get('@titleField').clear();
    cy.get('@titleField').type('This is a new title');
    cy.get('@titleField').should('have.value', 'This is a new title');
    cy.get('button[aria-label="Undo"]').should('be.enabled');
    cy.get('button[aria-label="Redo"]').should('be.disabled');
    cy.get('button[aria-label="Undo"]').click();
    cy.get('@titleField').should(
      'have.value',
      'XB Needs This For The Time Being',
    );
    cy.get('button[aria-label="Undo"]').should('be.disabled');
    cy.get('@layersTree').findByText('Two Column').should('exist');
    cy.get('button[aria-label="Redo"]').should('be.enabled');

    cy.intercept('POST', '**/xb/api/layout/node/1').as('getPreview');
    cy.intercept('PATCH', '**/xb/api/layout/node/1').as('patchPreview');
    // Switch back to component inputs form.
    cy.clickComponentInPreview('Hero');
    cy.findByTestId('xb-contextual-panel--settings').click();
    cy.get(
      '[class*="contextualPanel"] [data-drupal-selector="component-inputs-form"]',
    )
      .findByLabelText('Heading')
      .as('heroTitle');
    cy.get('@heroTitle').should('have.value', 'hello, world!');
    cy.get('@heroTitle').focus();
    cy.get('@heroTitle').clear();
    cy.get('@heroTitle').type('This is a new hero title');
    cy.wait('@patchPreview');
    // Editing a component field should push that onto the undo state.
    cy.get('button[aria-label="Undo"]').should('be.enabled');

    // Changing a field on the components prop form should invalidate the redo
    // state for the page data form.
    cy.get('button[aria-label="Redo"]').should('be.disabled');
    cy.get('@heroTitle').should('have.value', 'This is a new hero title');
    cy.get('button[aria-label="Undo"]').click();
    cy.get('@heroTitle').should('have.value', 'hello, world!');
    cy.get('button[aria-label="Undo"]').should('be.disabled');
    cy.get('@layersTree').findByText('Two Column').should('exist');
    cy.get('button[aria-label="Redo"]').should('be.enabled');
    cy.get('button[aria-label="Redo"]').click();
    cy.get('@heroTitle').should('have.value', 'This is a new hero title');
    cy.get('button[aria-label="Undo"]').should('be.enabled');
    cy.get('button[aria-label="Redo"]').should('be.disabled');

    cy.get('button[aria-label="Undo"]').click();
    cy.get('@heroTitle').should('have.value', 'hello, world!');
    cy.get('button[aria-label="Redo"]').should('be.enabled');

    // Changing a field on the data form, should invalidate the redo state for
    // the layoutModel.
    cy.findByTestId('xb-contextual-panel--page-data').click();
    cy.findByTestId('xb-page-data-form')
      .findByLabelText('Title')
      .should('have.value', 'XB Needs This For The Time Being');

    cy.get('@titleField').focus();
    cy.get('@titleField').clear();
    cy.get('@titleField').should('have.value', '');
    cy.get('@titleField').type('This is a new title');
    cy.get('@titleField').should('have.value', 'This is a new title');
    cy.get('button[aria-label="Undo"]').should('be.enabled');
    cy.get('button[aria-label="Redo"]').should('be.disabled');
  });
});
