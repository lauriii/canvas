describe('Empty canvas', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('can add a component to an empty canvas', () => {
    cy.loadURLandWaitForXBLoaded('xb/node/2');

    // Confirm there is nothing in the canvas.
    cy.get('.xb--viewport-overlay [data-xb-component-id]').should('not.exist');

    // For good measure, also confirm the content of the hero component is not
    // in the canvas.
    cy.waitForElementContentNotInIframe('div', 'There goes my hero');

    cy.get('[data-xb-component-id="sdc.experience_builder.my-hero"]').should(
      'not.exist',
    );
    cy.openLibraryPanel();

    // This is the component to be dragged in.
    cy.get('[data-xb-component-id="sdc.experience_builder.my-hero"]').should(
      'exist',
    );

    // Get the layout destination so the Hero component can be dragged to it.
    cy.get('.xb--viewport-overlay > div')
      .first()
      .then(($destination) => {
        cy.get(
          '[data-xb-component-id="sdc.experience_builder.my-hero"]',
        ).realDnd($destination, { scrollBehavior: false });
      });

    // The Hero component is now in the iframe.
    cy.waitForElementContentInIframe('div', 'There goes my hero');

    // The two overlays now have one component.
    cy.get('.xb--viewport-overlay [data-xb-component-id]').should(
      'have.length',
      2,
    );
  });
});
