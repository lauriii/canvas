// @todo Expand this test to include coverage for "Page Data" fields such as 'title' in https://drupal.org/i/3495752.
// @todo Expand this test to include coverage for adding a component with no properties in https://drupal.org/i/3498227.
describe('Publish button', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  afterEach(() => {
    cy.drupalUninstall();
  });

  it(`Adds a component, and attempts to publish changes using the publish button`, () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/2' });

    // Wait for an element in the page data panel to be present.
    cy.get('#edit-title-0-value').should('exist');

    // Confirm there is nothing in the canvas.
    cy.get('.xb--viewport-overlay [data-xb-component-id]').should('not.exist');

    // For good measure, also confirm the content of the hero component is not
    // in the canvas.
    cy.waitForElementContentNotInIframe('div', 'There goes my hero');
    cy.get('[data-xb-component-id="sdc.experience_builder.my-hero"]').should(
      'not.exist',
    );

    // Open the library panel.
    cy.openLibraryPanel();

    // This is the component to be dragged in.
    cy.get('[data-xb-component-id="sdc.experience_builder.my-hero"]').should(
      'exist',
    );

    cy.waitForElementInIframe('.xb--sortable-slot-empty-placeholder');
    cy.get(
      '[data-xb-component-id="sdc.experience_builder.my-hero"]',
    ).realClick();

    cy.log('The hero component is now in the iframe');

    // Publish the page with the new component.
    cy.findByTestId('xb-topbar').findByText('Publish').should('exist').click();
    cy.findByTestId('xb-topbar').findByText('Published').should('exist');

    // Reload the page to confirm the component has been added.
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/2' });

    // We should see the saved component.
    cy.waitForElementContentInIframe('div', 'There goes my hero');
  });
});
