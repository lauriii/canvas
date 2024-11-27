const oliveroCssLink = 'link[href*="header-search-narrow.css"]';

describe('Contain CSS', () => {
  before(() => {
    cy.drupalXbInstall();
    cy.drupalEnableTheme('olivero');
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Olivero CSS should not present in the XB UI', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.get(oliveroCssLink).should('not.exist');
  });
});
