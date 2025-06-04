describe('Block form', () => {
  before(() => {
    cy.drupalXbInstall();
    cy.drupalEnableTheme('olivero');
    cy.drupalEnableThemeForXb('olivero');
    cy.viewport(2000, 1320);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/3' });
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Block settings form with details element', () => {
    cy.getComponentInPreview('Administration').within(() => {
      cy.get('[data-xb-uuid="cea4c5b3-7921-4c6f-b388-da921bd1496d"]');
    });
    cy.clickComponentInPreview('Administration');

    // Confirm that an element in the block settings form is present.
    cy.get('[data-testid="xb-contextual-panel"] button:contains("Menu levels")')
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
    cy.get('[data-testid="xb-contextual-panel"]')
      .findByLabelText('Initial visibility level')
      .should('exist');

    // Click the disclosure button again and confirm the level edit elem
    // no longer present.
    cy.get('@menuLevelDisclose').realClick({ scrollBehavior: false });
    cy.get('[data-testid="xb-contextual-panel"]')
      .findByLabelText('Initial visibility level')
      .should('not.be.visible');
  });

  it('Block settings form values are stored and the preview is updated', () => {
    // Delete the image that uses an adapted source.
    cy.clickComponentInPreview('Image', 1);
    cy.realType('{del}');

    cy.focusRegion('Header');

    cy.clickComponentInPreview('Site branding', 0, 'header');
    cy.waitForElementContentInIframe('div.site-branding__inner', 'Drupal');

    cy.findByLabelText('Site name').as('siteName');
    cy.get('@siteName').should('be.checked');
    cy.get('@siteName').realClick();
    cy.get('@siteName').should('not.be.checked');
    cy.waitForElementContentNotInIframe('div.site-branding__inner', 'Drupal');

    cy.get('@siteName').realClick();
    cy.get('@siteName').should('be.checked');
    cy.waitForElementContentInIframe('div.site-branding__inner', 'Drupal');

    // Turn off the site name again.
    cy.get('@siteName').realClick();
    cy.get('@siteName').should('not.be.checked');
    cy.waitForElementContentNotInIframe('div.site-branding__inner', 'Drupal');

    // Now move this component to the content region so it is stored in the node.
    cy.sendComponentToRegion('Site branding', 'Content');

    // The component should now be in the content region.
    cy.getComponentInPreview('Site branding').should('exist');
    cy.waitForElementContentNotInIframe('div.site-branding__inner', 'Drupal');

    // Publish the page with the new component.
    cy.intercept('POST', '**/xb/api/v0/auto-saves/publish').as('publish');

    // Extra long timeout here, because the poll to get changes is every 10 seconds.
    cy.debugPause('Manually check changes in the XB UI');
    cy.publishAllPendingChanges([
      'XB With a block in the layout',
      'Header region',
    ]);

    // Add logged output of any validation errors.
    cy.wait('@publish').then(console.log);
    cy.findByTestId('xb-topbar')
      .findByText('No changes', { selector: 'button' })
      .should('exist');

    // Reload the page to confirm the component has been added.
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/3', clearAutoSave: false });

    // We should see the saved component.
    cy.clickComponentInPreview('Site branding');
    cy.waitForElementContentNotInIframe('div.site-branding__inner', 'Drupal');

    // And it should have the saved configuration.
    cy.findByLabelText('Site name').as('siteName');
    cy.get('@siteName').should('not.be.checked');
  });
});
