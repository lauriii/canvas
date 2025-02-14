describe('Routing', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Visits a component router URL directly', () => {
    // Ideally the UUID would get its value dynamically, but that value can
    // only be accessed reliably in a command callback, and visiting a url
    // can't happen in that scope.
    const uuid = 'static-static-card3rr';
    cy.intercept('GET', '**/xb/api/layout/node/1').as('getLayout');
    cy.intercept('POST', '**/xb/api/layout/node/1').as('getPreview');
    cy.intercept('PATCH', '**/xb/api/form/component-instance/node/1').as(
      'getPropsForm',
    );
    cy.drupalRelativeURL(`xb/node/1/editor/component/${uuid}`);

    cy.wait('@getLayout');
    cy.wait('@getPreview');
    cy.wait('@getPropsForm');
    cy.findByTestId(`xb-contextual-panel-${uuid}`).should('exist');
    cy.url().should('contain', `/xb/node/1/editor/component/${uuid}`);
  });

  it('Visits a preview router URL directly', () => {
    cy.drupalRelativeURL(`xb/node/1/preview/full`);

    cy.findByText('Exit Preview');

    cy.get('iframe[title="Page preview"]')
      .its('0.contentDocument.body')
      .should('not.be.empty')
      .then(cy.wrap)
      .within(() => {
        cy.get('.my-hero__heading').should('exist');
      });

    cy.url().should('contain', `/xb/node/1/preview/full`);
  });

  it('has the expected performance', () => {
    cy.intercept('POST', '**/xb/api/layout/node/1').as('getPreview');

    cy.visit('/xb/node/1');
    cy.wait('@getPreview').its('response.statusCode').should('eq', 200);

    // Assert that only one request was sent
    cy.get('@getPreview.all').should('have.length', 1);
  });
});
