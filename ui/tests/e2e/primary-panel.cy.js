describe('Primary panel', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Should ensure the library panel is scrollable', () => {
    // Stub the HTTP request to return many components to make scrolling necessary
    cy.intercept('GET', '**/xb-components', {
      statusCode: 200,
      body: Array(50)
        .fill()
        .reduce((acc, _, index) => {
          const paddedIndex = String(index + 1).padStart(2, '0');
          const id = `experience_builder:component_${paddedIndex}`;
          acc[id] = { id, name: `Component ${paddedIndex}` };
          return acc;
        }, {}),
    }).as('getComponents');

    cy.loadURLandWaitForXBLoaded();

    cy.openLibraryPanel();
    cy.wait('@getComponents');

    cy.get('[data-testid="xb-primary-panel"]')
      .realMouseWheel({ deltaY: 2500 })
      .then(() => {
        cy.get(
          '[data-xb-component-id="experience_builder:component_50"]',
        ).should('be.visible');
      });
  });

  it('previews components on hover', () => {
    cy.loadURLandWaitForXBLoaded();

    cy.openLibraryPanel();
    cy.get('.primaryPanelContent [data-state="open"]').contains('Components');

    const imageSelect =
      '.primaryPanelContent [data-xb-component-id="sdc.experience_builder.image"]';
    const heroSelect =
      '.primaryPanelContent [data-xb-component-id="sdc.experience_builder.my-hero"]';

    // Hover over "Image" and a preview should appear.
    cy.get(`${imageSelect} > span`).should('exist').realHover();
    cy.waitForElementInIframe(
      'img[alt="Boring placeholder"]',
      'iframe[data-preview-component-id="sdc.experience_builder.image"]',
    );

    // Hover over "My Hero" and a preview should appear and load correct CSS
    cy.get(`${heroSelect} > span`).should('exist').realHover();
    cy.waitForElementInIframe(
      'div.my-hero__container > .my-hero__actions > .my-hero__cta--primary',
      'iframe[data-preview-component-id="sdc.experience_builder.my-hero"]',
    );
    cy.getIframeBody(
      'iframe[data-preview-component-id="sdc.experience_builder.my-hero"]',
    )
      .find(
        'div.my-hero__container > .my-hero__actions > .my-hero__cta--primary',
      )
      .should('exist')
      .should(($cta) => {
        // Retry until the background-color is the expected one
        const bgColor = window.getComputedStyle($cta[0])['background-color'];
        expect(bgColor, 'The "My Hero" SDC is styled').to.equal(
          'rgb(0, 123, 255)',
        );
      });
  });
});
