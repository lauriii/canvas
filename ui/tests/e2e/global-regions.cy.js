describe('Operate on components in global regions', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Can interact with components in global regions', () => {
    cy.drupalEnableTheme('olivero');
    cy.drupalEnableThemeForXb('olivero');
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();

    cy.get('#xbPreviewOverlay .xb--viewport-overlay')
      .first()
      .as('desktopPreviewOverlay');
    cy.get('.primaryPanelContent').as('layersTree');

    // Open the layers in the Tree.
    cy.get('@layersTree')
      .findByText('Two Column')
      .parents('.treeItem')
      .findByLabelText('Expand component tree')
      .click();
    cy.get('@layersTree')
      .findAllByText('Column One')
      .first()
      .parents('.treeItem')
      .findByLabelText('Expand component tree')
      .click();
    cy.get('@layersTree').findAllByText('Image').should('be.visible');
    cy.get('@layersTree').findAllByText('Hero').should('be.visible');

    cy.get('@layersTree').findAllByText('Hero').click();

    cy.log(
      'Drag static hero component out of the content region into the highlighted region.',
    );
    cy.get('.treeItem[data-xb-uuid="static-static-card1ab"]').realDnd(
      '.rootDropZone[data-xb-type="region"][data-xb-uuid="highlighted"]',
    );

    // One hero should remain in content region.
    cy.clickComponentInPreview('Hero');
    // But a hero component should now be in highlighted region too.
    cy.clickComponentInPreview('Hero', 0, 'lg', 'highlighted');

    cy.log('Test region overlays.');
    let lgPreviewRect = {};
    // Enter the iframe to find an element in the preview iframe and hover over it.
    cy.getIframeBody()
      .find('[data-xb-uuid="static-static-card1ab"] h1')
      .first()
      .then(($h1) => {
        cy.wrap($h1).trigger('mouseover');
        // While in the iframe, get the dimensions of the component so we can
        // compare the outline dimensions to it
        const $item = $h1.closest('.xb--sortable-item');
        lgPreviewRect = $item[0].getBoundingClientRect();
      });

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
