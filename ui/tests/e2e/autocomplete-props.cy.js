describe('Prop with autocomplete', () => {
  before(() => {
    cy.drupalXbInstall(['xb_test_autocomplete']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });
  after(() => {
    cy.drupalUninstall();
  });

  it('has a working autocomplete in the props form', () => {
    cy.loadURLandWaitForXBLoaded();
    cy.get('iframe[data-xb-preview]').should('exist');
    cy.get(
      `#xbPreviewOverlay .xb--viewport-overlay[data-xb-viewport-size="lg"]  .xb--region-overlay__content`,
    )
      .findAllByLabelText('Hero')
      .eq(0)
      .click({ force: true });
    cy.get('[data-drupal-selector="edit-test-autocomplete"]').should('exist');
    cy.get('[data-drupal-selector="edit-test-autocomplete"]').type('Ban');
    cy.get('ul.ui-autocomplete').should('exist');
    cy.get('ul.ui-autocomplete li').should('have.text', 'Banana');
    cy.get('ul.ui-autocomplete li').click();
    cy.get('[data-drupal-selector="edit-test-autocomplete"]').should(
      'have.value',
      'banana',
    );
  });
});
