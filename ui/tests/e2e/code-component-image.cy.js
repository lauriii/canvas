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

  it('Can add an optional image component with a preview but empty input', () => {
    cy.loadURLandWaitForXBLoaded();

    // Delete the two existing image components.
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');

    cy.waitForComponentNotInPreview('Image');

    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').findByText('Vanilla Image').click();
    // Check the default image src is set.
    cy.waitForElementInIframe(
      'img[src="https://placehold.co/1200x900@2x.png"]',
      '[data-xb-preview="lg"][data-test-xb-content-initialized="true"][data-xb-swap-active="true"]',
      10000,
    );

    cy.publishAllPendingChanges('XB Needs This For The Time Being');
    cy.visit('/node/1');
    cy.get('img[src="https://placehold.co/1200x900@2x.png"]').should('exist', {
      timeout: 10000,
    });
  });
});
