describe('Publish review functionality', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Performs interaction with publish review button', () => {
    cy.drupalEnableTheme('olivero');
    cy.drupalEnableThemeForXb('olivero');
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();

    const reviewButtonSelector = 'button[data-testid="xb-publish-review"]';
    // Assert that the publish review button exists.
    cy.get(reviewButtonSelector)
      .should('exist')
      .and('have.text', 'Review 2 changes')
      .and('be.enabled');

    // Clicking on the review button should open the review panel
    cy.get(reviewButtonSelector).click();
    const reviewsContainerTestId = 'xb-publish-reviews-content';
    cy.findByTestId(reviewsContainerTestId).should('exist');
    cy.findByText('Unpublished changes').should('exist');
    cy.findByTestId(reviewsContainerTestId)
      .get('[data-testid="pending-change-row"')
      .should('have.length', 2);

    // Clicking outside should close the panel
    cy.get('html').click();
    cy.findByTestId(reviewsContainerTestId).should('not.exist');
  });
});
