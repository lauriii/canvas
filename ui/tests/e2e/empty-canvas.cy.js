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

  // @todo test 'xb/page', 'xb/page/2' once XB router isn't tied to URL path
  //   matching the /xb/{entity_type}/{entity_id} pattern and relies only on
  //   what exists in `drupalSettings.xb` instead.
  //   Fix after https://www.drupal.org/project/experience_builder/issues/3489775
  const testCases = ['xb/node/2', 'xb/xb_page/2'];

  testCases.forEach((testCase) => {
    it(`${testCase} can add a component to an empty canvas`, () => {
      cy.loadURLandWaitForXBLoaded({ url: testCase });

      // Confirm there is nothing in the canvas.
      cy.get('.xb--viewport-overlay [data-xb-component-id]').should(
        'not.exist',
      );

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
          ).realDnd($destination);
        });
      // eslint-disable-next-line cypress/no-unnecessary-waiting
      cy.wait(10000);
      cy.log('The hero component is now in the iframe');
      cy.getIframeBody().within(() => {
        cy.get('[data-xb-component-id="experience_builder:my-hero"]').should(
          'have.length',
          1,
        );
      });
      cy.waitForElementContentInIframe('div', 'There goes my hero');

      // The two overlays now have one component.
      cy.get('.xb--viewport-overlay [data-xb-component-id]').should(
        'have.length',
        2,
      );
    });
  });
});
