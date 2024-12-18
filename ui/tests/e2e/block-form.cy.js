describe('Block form with details elements', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.viewport(2000, 1320);
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Block settings form with details element', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/3' });

    cy.get('#cea4c5b3-7921-4c6f-b388-da921bd1496d-name').should((blockName) => {
      expect(blockName).to.have.text('Administration');
    });
    cy.get(
      '[data-xb-viewport-size="lg"] [data-xb-component-id="block.system_menu_block.admin"] .xb--component-controls button',
    ).realClick({
      scrollBehavior: false,
    });

    // Confirm that an element in the block settings form is present.
    cy.get('[data-testid="xb-contextual-panel"] #edit-menu-levels > button')
      .as('menuLevelDisclose')
      .should('exist');

    // The level edit element should not be present as it is concealed in a
    // collapsible element.
    cy.get('[data-testid="xb-contextual-panel"] #edit-level').should(
      'not.exist',
    );

    // Click the disclosure button and confirm the level edit element is now
    // present.
    cy.get('@menuLevelDisclose').realClick({ scrollBehavior: false });
    cy.get('[data-testid="xb-contextual-panel"] #edit-level').should('exist');

    // Click the disclosure button again and confirm the level edit elem
    // no longer present.
    cy.get('@menuLevelDisclose').realClick({ scrollBehavior: false });
    cy.get('[data-testid="xb-contextual-panel"] #edit-level').should(
      'not.exist',
    );
  });
});
