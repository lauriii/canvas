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
    cy.findByTestId('xb-topbar').findByText('Draft');
  });

  it('Updating the page title in the page data form updates title in the navigation list.', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });

    cy.findByTestId(navigationButtonTestId).click();
    cy.findByTestId(navigationContentTestId)
      .should('exist')
      .and('contain.text', 'Homepage')
      .and('contain.text', 'Empty Page');

    // Open the page data form by clicking on the "Page data" tab in the sidebar.
    cy.findByTestId('xb-contextual-panel--page-data').click();
    cy.findByTestId('xb-page-data-form')
      .findByLabelText('Title')
      .should('have.value', 'Homepage');

    // Type a new value into the title field.
    cy.findByTestId('xb-page-data-form')
      .findByLabelText('Title')
      .as('titleField');
    cy.get('@titleField').focus();
    cy.get('@titleField').clear();
    cy.get('@titleField').type('My Awesome Site');
    cy.get('@titleField').should('have.value', 'My Awesome Site');
    cy.get('@titleField').blur();

    cy.findByTestId(navigationButtonTestId)
      .should('exist')
      .and('have.text', 'My Awesome Site')
      .and('be.enabled');

    cy.findByTestId(navigationButtonTestId).click();
    cy.findByTestId(navigationContentTestId)
      .should('exist')
      .and('contain.text', 'My Awesome Site')
      .and('contain.text', 'Empty Page');
  });

  it('Clicking page title navigates to edit page', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });

    cy.findByTestId(navigationButtonTestId).click();
    cy.contains('div', '/test-page').click();
    cy.url().should('contain', '/xb/xb_page/2');
    cy.findByTestId(navigationButtonTestId).click();
    cy.contains('div', '/homepage').click();
    cy.url().should('contain', '/xb/xb_page/1');
  });

  it('Deleting pages through navigation', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });

    // Intercept the DELETE request
    cy.intercept('DELETE', '**/xb/api/content/xb_page/*').as('deletePage');

    // Intercept the GET request to the list endpoint
    cy.intercept('GET', '**/xb/api/content/xb_page').as('getList');

    cy.findByTestId(navigationButtonTestId).click();
    cy.findByText('Empty Page').realHover();
    cy.findByLabelText('Page options for Empty Page').click();
    cy.contains('div.rt-BaseMenuItem', 'Delete page').click();
    cy.contains('button', 'Delete page').click();

    // Wait for the DELETE request to be made and assert it
    cy.wait('@deletePage').its('response.statusCode').should('eq', 204);

    // Wait for the GET request to the list endpoint which should be triggered by the deletion of a page.
    cy.wait('@getList').its('response.statusCode').should('eq', 200);

    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });
    cy.findByTestId(navigationButtonTestId).click();
    cy.findByTestId(navigationContentTestId)
      .should('exist')
      .and('contain.text', 'Homepage')
      .and('not.contain.text', 'Test page');
  });

  it('Clicking the back button navigates to last visited page', () => {
    const BASE_URL = `${Cypress.config().baseUrl}/`;
    const CONFIG_PAGE_URL = `${BASE_URL}admin/config`;
    // visit the base URL
    cy.visit(BASE_URL);

    // Store the current URL
    cy.url().then((previousUrl) => {
      cy.loadURLandWaitForXBLoaded();
      cy.findByLabelText('Exit Experience Builder').click();
      // Check if the URL is the previous URL
      cy.url().should('eq', previousUrl);
    });

    // visit the base URL
    cy.visit(CONFIG_PAGE_URL);

    // Store the current URL
    cy.url().then((previousUrl) => {
      cy.loadURLandWaitForXBLoaded();

      cy.findByLabelText('Exit Experience Builder').should(
        'have.attr',
        'href',
        CONFIG_PAGE_URL,
      );

      cy.findByLabelText('Exit Experience Builder').click();

      // Check if the URL is the previous URL
      cy.url().should('eq', previousUrl);
    });
  });
});
