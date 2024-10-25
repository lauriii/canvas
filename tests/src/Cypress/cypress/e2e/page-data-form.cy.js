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
    // Open the right sidebar by clicking on a component.
    cy.clickComponentInPreview('Hero');
    // Open the page data form by clicking on the "Page data" tab in the sidebar.
    cy.findByTestId('xb-contextual-panel--page-data').click();
    cy.findByTestId('xb-page-data-form')
      .findByLabelText('Title')
      .should('have.value', 'XB Needs This For The Time Being');
  });
});
