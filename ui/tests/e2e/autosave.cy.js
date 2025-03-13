describe('Auto-save is working', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Make a change, and ensure the change is still present on reloading the page', () => {
    cy.loadURLandWaitForXBLoaded();
    cy.clearLocalStorage();
    cy.waitForElementContentInIframe('div', 'hello, world!');
    cy.log('Click and delete the first Hero component.');
    cy.clickComponentInPreview('Hero', 0);
    cy.realPress('{del}');
    cy.waitForElementContentNotInIframe('div', 'hello, world!');

    cy.log(
      'Refresh the page, without clearing the auto-save and confirm the hero is still deleted',
    );
    cy.loadURLandWaitForXBLoaded({ clearAutoSave: false });
    cy.waitForElementContentNotInIframe('div', 'hello, world!');
  });
});
