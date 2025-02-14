describe('Experience Builder overlay UI interactions', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Can zoom the canvas with the Zoom Controls', () => {
    cy.loadURLandWaitForXBLoaded();
    cy.get('#xbPreviewOverlay .xb--viewport-overlay')
      .first()
      .as('desktopPreviewOverlay');

    cy.clickComponentInLayersView('Two Column');

    cy.disableCanvasPanning();
    cy.hidePanels();

    cy.log(
      'Selecting a "parent" component should show its label, but not the label(s) of its children',
    );
    cy.get('@desktopPreviewOverlay').within(() => {
      cy.findByText('Two Column').should('be.visible');
      cy.findAllByText('Hero').should('have.length', 3).and('not.be.visible');
      cy.findAllByText('Image').should('have.length', 2).and('not.be.visible');
    });

    cy.clickComponentInPreview('Hero');

    cy.log('After selecting a "child" component it should show its label.');
    cy.get('@desktopPreviewOverlay').within(() => {
      cy.findByText('Two Column').should('not.be.visible');
      cy.findAllByText('Hero').should('have.length', 3);
      cy.findAllByText('Hero').filter(':visible').should('have.length', 1);
      cy.findAllByText('Image').should('have.length', 2).and('not.be.visible');
    });

    cy.log(
      'Now hover a different component. The selected name should show, the hovered name should ALSO show.',
    );
    cy.get('@desktopPreviewOverlay').within(() => {
      cy.findAllByLabelText('Image')
        .eq(1)
        .realHover({ scrollBehavior: 'center' });
      cy.findAllByText('Hero').filter(':visible').should('have.length', 1);

      cy.findAllByText('Image').should('have.length', 2);
      cy.findAllByText('Image').filter(':visible').should('have.length', 1);
    });

    cy.showPanels();
    cy.reEnableCanvasPanning();
  });
});
