const navigationButtonTestId = 'xb-navigation-button';
const navigationContentTestId = 'xb-navigation-content';
const navigationNewButtonTestId = 'xb-navigation-new-button';
const navigationNewPageButtonTestId = 'xb-navigation-new-page-button';

describe('Navigation functionality', () => {
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

  it('Has page title in the top bar', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });
    cy.findByTestId(navigationButtonTestId)
      .should('exist')
      .and('have.text', 'Homepage')
      .and('be.enabled');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/2' });
    cy.findByTestId(navigationButtonTestId)
      .should('exist')
      .and('have.text', 'Empty Page')
      .and('be.enabled');
  });

  it('Clicking the page title in the top bar opens the navigation', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });
    cy.findByTestId(navigationButtonTestId)
      .should('exist')
      .and('have.text', 'Homepage')
      .and('be.enabled');
    cy.findByTestId(navigationButtonTestId).click();
    cy.findByTestId(navigationContentTestId)
      .should('exist')
      .and('contain.text', 'Homepage')
      .and('contain.text', 'Empty Page');
  });

  it('Clicking "New page" creates a new page and navigates to it', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });

    cy.findByTestId(navigationButtonTestId).click();
    cy.findByTestId(navigationNewButtonTestId).click();
    cy.findByTestId(navigationNewPageButtonTestId).click();
    cy.url().should('not.contain', '/xb/xb_page/1');
    cy.url().should('contain', '/xb/xb_page/3');
  });
});
