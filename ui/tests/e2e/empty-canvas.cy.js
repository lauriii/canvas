describe('Empty canvas', () => {
  beforeEach(() => {
    // Unlike most tests, we are installing drupal before each it() as that has
    // demonstrated to be the only reliable way to get tests after the first
    // passing consistently. This occurs regardless of which test runs first.
    cy.drupalXbInstall(['metatag']);
    cy.drupalLogin('xbUser', 'xbUser');
  });

  afterEach(() => {
    cy.drupalUninstall();
  });

  // @todo test 'xb/page', 'xb/page/2' once XB router isn't tied to URL path
  //   matching the /xb/{entity_type}/{entity_id} pattern and relies only on
  //   what exists in `drupalSettings.xb` instead.
  //   Fix after https://www.drupal.org/project/experience_builder/issues/3489775
  it(`xb/node/2 can add a component to an empty canvas`, () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });

    // Wait for an element in the page data panel to be present.
    cy.get('#edit-title-0-value').should('exist');

    // Confirm there is nothing in the canvas.
    cy.get('.xb--viewport-overlay [data-xb-component-id]').should('not.exist');

    // For good measure, also confirm the content of the hero component is not
    // in the canvas.
    cy.waitForElementContentNotInIframe('div', 'There goes my hero');

    cy.get('[data-xb-component-id="sdc.xb_test_sdc.my-hero"]').should(
      'not.exist',
    );
    cy.openLibraryPanel();

    // This is the component to be dragged in.
    cy.get('[data-xb-component-id="sdc.xb_test_sdc.my-hero"]').should('exist');

    cy.waitForElementInIframe('.xb--region-empty-placeholder');
    cy.get('[data-xb-component-id="sdc.xb_test_sdc.my-hero"]').realClick();

    cy.log('The hero component is now in the iframe');

    // The overlay now has a component.
    cy.get('.xb--viewport-overlay [data-xb-component-id]').should(
      'have.length',
      1,
    );
    cy.waitForElementContentInIframe('div', 'There goes my hero');
    cy.getIframeBody().within(() => {
      cy.get('[data-component-id="xb_test_sdc:my-hero"]').should(
        'have.length',
        1,
      );
    });
  });

  it(`xb/xb_page/2 can add a component to an empty canvas`, () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/2' });

    // Wait for an element in the page data panel to be present.
    cy.get('#edit-title-0-value').should('exist');

    cy.get('#edit-seo-settings').should('exist');
    cy.get('#edit-seo-settings #edit-image-wrapper').should('exist');
    cy.get('#edit-seo-settings #edit-metatags-0-basic-title').should('exist');
    cy.get('#edit-seo-settings #edit-description-wrapper').should('exist');

    // Confirm there is nothing in the canvas.
    cy.get('.xb--viewport-overlay [data-xb-component-id]').should('not.exist');

    // For good measure, also confirm the content of the hero component is not
    // in the canvas.
    cy.waitForElementContentNotInIframe('div', 'There goes my hero');

    cy.get('[data-xb-component-id="sdc.xb_test_sdc.my-hero"]').should(
      'not.exist',
    );
    cy.openLibraryPanel();

    // This is the component to be dragged in.
    cy.get('[data-xb-component-id="sdc.xb_test_sdc.my-hero"]').should('exist');

    cy.waitForElementInIframe('.xb--region-empty-placeholder');

    cy.get('[data-xb-component-id="sdc.xb_test_sdc.my-hero"]').realClick();

    cy.log('The hero component is now in the iframe');

    // The overlay now has a component.
    cy.get('.xb--viewport-overlay [data-xb-component-id]').should(
      'have.length',
      1,
    );
    cy.waitForElementContentInIframe('div', 'There goes my hero');
    cy.getIframeBody().within(() => {
      cy.get('[data-component-id="xb_test_sdc:my-hero"]').should(
        'have.length',
        1,
      );
    });
  });
});
