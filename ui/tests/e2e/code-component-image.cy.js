describe('Image code component', () => {
  before(() => {
    cy.drupalXbInstall(['xb_test_code_components']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it(
    'Can add an optional image component with a preview but empty input',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
      cy.openLibraryPanel();
      cy.get('.primaryPanelContent').findByText('Vanilla Image').click();
      // Check the default image src is set.
      cy.waitForElementInIframe(
        'img[src="https://placehold.co/1200x900@2x.png"]',
        '[data-test-xb-content-initialized="true"][data-xb-swap-active="true"]',
        10000,
      );
      cy.get('[data-testid="xb-publish-review"]:not([disabled])', {
        timeout: 20000,
      }).should('exist');
      cy.publishAllPendingChanges('I am an empty node');
      cy.visit('/node/2');
      cy.get('img[src="https://placehold.co/1200x900@2x.png"]').should(
        'exist',
        {
          timeout: 10000,
        },
      );
    },
  );
});
