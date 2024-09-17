describe('Contextual menu functionality', {testIsolation: false}, () => {

  before(() => {
    cy.drupalXbInstall();
  });

  after(() => {
    cy.drupalUninstall();
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  it('should open the context menu on right-click', () => {
    cy.loadURLandWaitForXBLoaded();
    // Wait for the preview iframe to load and render something that confirms it is ready.
    cy.get('iframe[data-xb-preview]').should('exist');
    // Right-click on the element that should trigger the context menu
    cy.getIframeBody().findByText('hello, world!').first().trigger('contextmenu');

    cy.get('[data-radix-scroll-area-viewport]')
      .should('exist')
      .and('be.visible');
    // Assert that each menu item is inside the DropdownMenu.Content component
    cy.get('[data-radix-scroll-area-viewport]')
      .within(() => {
        cy.findByText('Edit').should('be.visible');
        cy.findByText('Duplicate').should('be.visible');
        cy.findByText('Move').should('be.visible');
        cy.findByText('Delete').click();
      });
    cy.getIframeBody().findByText('hello, world!').should('not.exist');
  });
  it('should open the context menu on right-click in primary content menu', () => {
    cy.loadURLandWaitForXBLoaded();
    // Wait for the preview iframe to load and render something that confirms it is ready.
    cy.get('iframe[data-xb-preview]').should('exist');
    // Right-click on the element in primary content menu that should trigger the context menu.
    cy.get('[data-xb-uuid="root"]').findByText('Two Column').first().trigger('contextmenu');

    cy.get('[data-radix-scroll-area-viewport]')
      .should('exist')
      .and('be.visible');
    // Assert that each menu item is inside the DropdownMenu.Content component
    cy.get('[data-radix-scroll-area-viewport]')
      .within(() => {
        cy.findByText('Edit').should('be.visible');
        cy.findByText('Duplicate').should('be.visible');
        cy.findByText('Move').should('be.visible');
        cy.findByText('Delete').click();
      });
    cy.getIframeBody().findByText('Two Column').should('not.exist');
  });

  it('should duplicate the element on clicking the "Duplicate" button', () => {
    cy.loadURLandWaitForXBLoaded();
    cy.getIframeBody().find('[data-component-id="experience_builder:two_column"]').should('have.length', 1);

    // Right-click on the element that should trigger the context menu
    cy.get('.primaryMenuContent').findByText('Two Column').trigger('contextmenu');

    cy.get('[data-radix-scroll-area-viewport]')
      .should('exist')
      .and('be.visible');
    cy.get('[data-radix-scroll-area-viewport]')
      .within(() => {
        // Click on the "Duplicate" button
        cy.findByText('Duplicate').click();
      });
    cy.get('.primaryMenuContent').findAllByText('Two Column').should('have.length', 2);
  });

});
