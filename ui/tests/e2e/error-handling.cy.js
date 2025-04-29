describe('Error handling', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Handles and resets errors', () => {
    // Intercept the request to the preview endpoint and return a 418 status
    // code. This will cause the error boundary to display an error message.
    // Note the times: 1 option, which ensures the request is only intercepted
    // once.
    cy.intercept(
      { url: '**/xb/api/v0/layout/node/1', times: 1, method: 'GET' },
      { statusCode: 418 },
    );
    cy.drupalRelativeURL('xb/node/1');

    cy.findByTestId('xb-error-alert')
      .should('exist')
      .invoke('text')
      .should('include', 'An unexpected error has occurred');

    // Click the reset button to clear the error, and confirm the error message
    // is no longer present.
    cy.findByTestId('xb-error-reset').click();
    cy.contains('An unexpected error has occurred').should('not.exist');
  });
});
