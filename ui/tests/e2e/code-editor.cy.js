describe('Code editor', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();
  });

  after(() => {
    cy.drupalUninstall();
  });

  it(
    'Should add a new component',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.openLibraryPanel();
      cy.findByRole('button', { name: 'Add new', exact: false }).click();
      cy.findByLabelText('Component name').should('exist');
      cy.findByLabelText('Component name').type('Bobby Tables');
      cy.findByRole('button', { name: 'Add' }).click();
      cy.findByLabelText('Back to Content region').should(
        'contain',
        'Bobby Tables',
      );
    },
  );
});
