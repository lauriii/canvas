describe('Operate on components in global regions', () => {
  before(() => {
    cy.drupalXbInstall();
    cy.drupalEnableTheme('olivero');
    cy.drupalEnableThemeForXb('olivero');
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Can focus on global regions and see their child components', () => {
    cy.loadURLandWaitForXBLoaded();
    cy.focusRegion('Content Above');
    cy.get('.spotlight').should('exist');
    cy.get('.spotlight')
      .children()
      .first()
      .should('have.css', 'pointer-events', 'all');

    cy.get('.spotlight')
      .findByTestId('xb-region-spotlight-highlight')
      .should('have.css', 'pointer-events', 'none');

    cy.log(
      'The overlay for the content region should not be rendering any component overlays inside it!',
    );
    cy.get('.xb--region-overlay__content').first().should('be.empty');
  });

  it('Can move components between global regions', () => {
    cy.loadURLandWaitForXBLoaded();
    cy.findByTestId('xb-primary-panel').as('layersTree');

    cy.focusRegion('Breadcrumb');

    cy.log('Move "Breadcrumbs" component UP into the Highlighted Region');
    cy.sendComponentToRegion('Breadcrumbs block', 'Highlighted');

    cy.returnToContentRegion();
    cy.focusRegion('Highlighted');
    cy.log(
      '"Breadcrumbs" component should now be the LAST child in the Highlighted region',
    );
    cy.get('@layersTree')
      .findByLabelText('Breadcrumbs block')
      .parent()
      .then(($div) => {
        // Assert that the div is the last child of its new parent region.
        expect($div.is(':last-child')).to.be.true;
      });
    cy.returnToContentRegion();
    cy.log(
      'Move "User account menu" component DOWN into the Highlighted Region',
    );
    cy.focusRegion('Secondary menu');
    cy.sendComponentToRegion('User account menu block', 'Highlighted');
    cy.returnToContentRegion();

    cy.focusRegion('Highlighted');
    cy.log(
      '"User account menu" component should now be the FIRST child in the Highlighted region',
    );
    cy.get('@layersTree')
      .findByLabelText('User account menu block')
      .parent()
      .then(($div) => {
        // Assert that the div is the first child of its new parent region.
        expect($div.is(':first-child')).to.be.true;
      });
  });

  it('Can interact with components in global regions', () => {
    cy.loadURLandWaitForXBLoaded();

    cy.get('#xbPreviewOverlay .xb--viewport-overlay')
      .first()
      .as('desktopPreviewOverlay');
    cy.get('.primaryPanelContent').as('layersTree');

    // Open the layers in the Tree.
    cy.expandComponentLayer('Two Column');
    cy.expandSlotLayer('Column One');
    cy.get('@layersTree').findAllByText('Image').should('be.visible');
    cy.get('@layersTree').findAllByText('Hero').should('be.visible');

    cy.get('@layersTree').findAllByText('Hero').click();

    cy.intercept('POST', '**/xb/api/layout/node/1').as('getPreview');
    cy.log(
      'Drag static hero component out of the content region into the highlighted region.',
    );
    cy.sendComponentToRegion('Hero', 'Highlighted');
    cy.wait('@getPreview');

    // One hero should remain in content region.
    cy.clickComponentInPreview('Hero');
    cy.focusRegion('Highlighted');
    // But a hero component should now be in highlighted region too.
    cy.clickComponentInPreview('Hero', 0, 'lg', 'highlighted');

    cy.log('Test region overlays.');
    let lgPreviewRect = {};
    // Enter the iframe to find an element in the preview iframe and hover over it.
    cy.getIframeBody()
      .find('[data-xb-uuid="static-static-card1ab"] h1')
      .first()
      .then(($h1) => {
        // While in the iframe, get the dimensions of the component so we can
        // compare the outline dimensions to it
        const $item = $h1.closest('[data-xb-uuid]');
        lgPreviewRect = $item[0].getBoundingClientRect();
      });

    cy.getComponentInPreview('Hero', 0, 'lg', 'highlighted').then(
      ($component) => {
        cy.wrap($component).trigger('mouseover');
      },
    );
    cy.getComponentInPreview('Hero', 0, 'lg', 'highlighted')
      .should(($outline) => {
        expect($outline).to.exist;
        // Ensure the width is set before moving on to then().
        expect($outline[0].getBoundingClientRect().width).to.not.equal(0);
      })
      .then(($outline) => {
        // The outline width and height should be the same as the dimensions of
        // the corresponding component in the iframe.
        const outlineRect = $outline[0].getBoundingClientRect();
        expect(outlineRect.width).to.be.closeTo(lgPreviewRect.width, 0.1);
        expect(outlineRect.height).to.be.closeTo(lgPreviewRect.height, 0.1);
        expect($outline).to.have.css('position', 'absolute');
      });

    // Click the component in the highlighted region to trigger the opening of the
    // right drawer.
    cy.clickComponentInPreview('Hero', 0, 'lg', 'highlighted');

    cy.editHeroComponent();
  });
});
