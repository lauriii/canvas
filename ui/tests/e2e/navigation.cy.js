const navigationButtonTestId = 'xb-navigation-button';
const navigationContentTestId = 'xb-navigation-content';
const navigationResultsTestId = 'xb-navigation-results';
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

  it('Verify that search works', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });
    cy.findByTestId(navigationButtonTestId).click();
    cy.findByLabelText('Search content').clear();
    cy.findByLabelText('Search content').type('ome');
    cy.findByTestId(navigationResultsTestId)
      .findAllByRole('listitem')
      .should(($children) => {
        const count = $children.length;
        expect(count).to.be.eq(1);
        expect($children.text()).to.contain('Homepage');
        expect($children.text()).to.not.contain('Empty Page');
      });
    cy.findByLabelText('Search content').clear();
    cy.findByLabelText('Search content').type('NonExistentPage');
    cy.findByTestId(navigationResultsTestId)
      .findAllByRole('listitem')
      .should(($children) => {
        const count = $children.length;
        expect(count).to.be.eq(0);
      });
    cy.findByTestId(navigationResultsTestId)
      .findByText('No pages found', { exact: false })
      .should('exist');
    cy.findByLabelText('Search content').clear();
    cy.findByTestId(navigationResultsTestId)
      .findAllByRole('listitem')
      .should(($children) => {
        const count = $children.length;
        expect(count).to.be.eq(3);
        expect($children.text()).to.contain('Homepage');
        expect($children.text()).to.contain('Empty Page');
      });
  });

  it('Clicking "New page" creates a new page and navigates to it', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });

    cy.findByTestId(navigationButtonTestId).click();
    cy.findByTestId(navigationNewButtonTestId).click();
    cy.findByTestId(navigationNewPageButtonTestId).click();
    cy.url().should('not.contain', '/xb/xb_page/1');
    cy.url().should('contain', '/xb/xb_page/4');
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

  it(
    'Duplicate pages through navigation',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });

      cy.findByTestId(navigationButtonTestId).click();
      cy.findByText('Empty Page').realHover();
      cy.findByLabelText('Page options for Empty Page').click();
      cy.findByRole('menuitem', {
        name: 'Duplicate page',
        exact: false,
      }).click();

      cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });
      cy.findByTestId(navigationButtonTestId).click();
      cy.findByTestId(navigationContentTestId)
        .should('exist')
        .and('contain.text', 'Empty Page (Copy)');
    },
  );

  it(
    'Deleting pages through navigation',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      // Intercept the DELETE request
      cy.intercept('DELETE', '**/xb/api/v0/content/xb_page/*').as('deletePage');

      // Intercept the GET request to the list endpoint
      cy.intercept('GET', '**/xb/api/v0/content/xb_page').as('getList');

      cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1' });

      cy.findByTestId(navigationButtonTestId).click();
      cy.wait('@getList').its('response.statusCode').should('eq', 200);
      cy.findByText('Empty Page').realHover();
      cy.findByLabelText('Page options for Empty Page').click();
      cy.findByRole('menuitem', { name: 'Delete page', exact: false }).click();
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
    },
  );

  it(
    'Set the homepage within XB and check deleting the current page will navigate to the homepage',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/4' });
      cy.intercept('DELETE', '**/xb/api/v0/content/xb_page/*').as('deletePage');
      cy.intercept('GET', '**/xb/api/v0/content/xb_page').as('getList');
      cy.intercept('POST', '**/xb/api/v0/staged-update/auto-save').as(
        'setHomepage',
      );
      cy.intercept(
        'GET',
        '**/xb/api/v0/config/auto-save/staged_config_update/*',
        'getHomepage',
      );
      cy.log('loaded xb/xb_page/4');
      cy.findByTestId(navigationButtonTestId).click();
      cy.findByTestId(navigationContentTestId)
        .should('exist')
        .and('contain.text', 'Homepage')
        .and('contain.text', 'Untitled page');
      cy.url().should('contain', '/xb/xb_page/4');
      cy.get('[data-xb-page-id="1"]').realHover();
      cy.findByLabelText('Page options for Homepage').click();
      // Confirm the delete option is available since this isn't the homepage yet.
      cy.findByRole('menuitem', {
        name: 'Delete page',
        exact: false,
      }).should('exist');
      cy.findByRole('menuitem', {
        name: 'Set as homepage',
        exact: false,
      }).click();
      // Wait for the POST request to be made and assert it
      cy.wait('@setHomepage').its('response.statusCode').should('eq', 201);
      // Wait for the GET request to the config endpoint which should be triggered by setting the homepage.
      cy.wait('@getList').its('response.statusCode').should('eq', 200);

      // Delete the untitled page.
      cy.get('[data-xb-page-id="4"]').realHover();
      cy.findByLabelText('Page options for Untitled page').click();
      cy.findByRole('menuitem', { name: 'Delete page', exact: false }).click();
      cy.contains('button', 'Delete page').click();
      // Wait for the DELETE request to be made and assert it
      cy.wait('@deletePage').its('response.statusCode').should('eq', 204);
      // Wait for the GET request to the list endpoint which should be triggered by the deletion of a page.
      cy.wait('@getList').its('response.statusCode').should('eq', 200);
      cy.url().should('not.contain', '/xb/xb_page/4');

      // Confirm we are now on the homepage.
      cy.url().should('contain', '/xb/xb_page/1');
      cy.findByTestId(navigationButtonTestId).click();
      cy.findByTestId(navigationContentTestId)
        .should('exist')
        .and('contain.text', 'Homepage')
        .and('not.contain.text', 'Untitled page');
      cy.get('[data-xb-page-id="1"]').realHover();
      cy.findByLabelText('Page options for Homepage').click();
      // The page set as Homepage should not have the "Set as homepage" option anymore.
      cy.findByRole('menuitem', {
        name: 'Set as homepage',
        exact: false,
      }).should('not.exist');
      // The page set as Homepage should not have the "Delete page" option anymore.
      cy.findByRole('menuitem', {
        name: 'Delete page',
        exact: false,
      }).should('not.exist');
    },
  );

  it('Clicking the back button navigates to last visited page', () => {
    const BASE_URL = `${Cypress.config().baseUrl}/`;
    // TRICKY: use a query string rather than a path, because this test site has zero content on offer.
    const LAST_VISITED_URL = `${BASE_URL}user/2?whatever`;
    // Visit the base URL.
    cy.visit(BASE_URL);

    // Store the current URL
    cy.url().then((previousUrl) => {
      cy.loadURLandWaitForXBLoaded();
      cy.findByLabelText('Exit Experience Builder').click();
      // Check if the URL is the previous URL
      cy.url().should('eq', previousUrl);
    });

    // Ensure the "last visited URL" actually is what it is.
    cy.visit(LAST_VISITED_URL);

    // Store the current URL
    cy.url().then((previousUrl) => {
      cy.loadURLandWaitForXBLoaded();

      cy.findByLabelText('Exit Experience Builder').should(
        'have.attr',
        'href',
        LAST_VISITED_URL,
      );

      cy.findByLabelText('Exit Experience Builder').click();

      // Check if the URL is the previous URL
      cy.url().should('eq', previousUrl);
    });
  });
});
