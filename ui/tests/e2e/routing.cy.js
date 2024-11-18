describe('Routing', { testIsolation: false }, () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Visits a router URL directly', () => {
    // Ideally the UUID would get its value dynamically, but that value can
    // only be accessed reliably in a command callback, and visiting a url
    // can't happen in that scope.
    const uuid = 'dynamic-dynamic-card3rr';
    cy.intercept('GET', '**/api/layout/node/1').as('getLayout');
    cy.intercept('POST', '**/api/preview/node/1').as('getPreview');
    cy.intercept('GET', '**/xb-field-form/node/1?**').as('getPropsForm');
    cy.drupalRelativeURL(`xb/node/1/component/${uuid}`);

    cy.wait('@getLayout');
    cy.wait('@getPreview');
    cy.wait('@getPropsForm');
    cy.findByTestId(`xb-contextual-panel-${uuid}`).should('exist');
    cy.url().should('contain', `/xb/node/1/component/${uuid}`);
  });

  it('has the expected performance', () => {
    cy.intercept('POST', '**/api/preview/node/1').as('getPreview');

    cy.visit('/xb/node/1');
    cy.wait('@getPreview').its('response.statusCode').should('eq', 200);

    // Assert that only one request was sent
    cy.get('@getPreview.all').should('have.length', 1);
  });
});
