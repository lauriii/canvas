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
    cy.get('#cea4c5b3-7921-4c6f-b388-da921bd1496d-name').should((blockName) => {
      expect(blockName).to.have.text('Administration block');
    });
    cy.clickComponentInPreview('Administration block');

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
      .should('not.exist');
  });

  it('Block settings form values are stored and the preview is updated', () => {
    // Delete the image that uses an adapted source.
    cy.clickComponentInPreview('Image', 1);
    cy.realType('{del}');

    cy.focusRegion('Header');

    cy.clickComponentInPreview('Site branding block', 0, 'lg', 'header');
    cy.waitForElementContentInIframe('div.site-branding__inner', 'Drupal');

    cy.findByLabelText('Site name').as('siteName');
    cy.get('@siteName').assertToggleState(true);
    cy.get('@siteName').toggleToggle();
    cy.get('@siteName').assertToggleState(false);
    cy.waitForElementContentNotInIframe('div.site-branding__inner', 'Drupal');

    cy.get('@siteName').toggleToggle();
    cy.get('@siteName').assertToggleState(true);
    cy.waitForElementContentInIframe('div.site-branding__inner', 'Drupal');

    // Turn off the site name again.
    cy.get('@siteName').toggleToggle();

    // Now move this component to the content region so it is stored in the node.
    cy.sendComponentToRegion('Site branding block', 'Content');
    cy.returnToContentRegion();

    // The component should now be in the content region.
    cy.getComponentInPreview('Site branding block').should('exist');

    // Publish the page with the new component.
    cy.intercept('POST', '**/xb/api/autosaves/publish').as('publish');

    // Extra long timeout here, because the poll to get changes is every 10 seconds.
    cy.findByText('Review 1 change', { timeout: '11000' }).click();

    cy.findByTestId('xb-publish-reviews-content').within(() => {
      cy.findByText('XB With a block in the layout');
      cy.findByText('Publish all changes').click();
      cy.findByText('Publishing').should('exist');
      cy.findByText('Publishing').should('not.exist');
    });

    // Add logged output of any validation errors.
    cy.wait('@publish').then(console.log);
    cy.findByTestId('xb-topbar')
      .findByText('No changes', { selector: 'button' })
      .should('exist');

    // Reload the page to confirm the component has been added.
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/3' });

    // We should see the saved component.
    cy.clickComponentInPreview('Site branding block');
    cy.waitForElementContentNotInIframe('div.site-branding__inner', 'Drupal');

    // And it should have the saved configuration.
    cy.findByLabelText('Site name').as('siteName');
    cy.get('@siteName').assertToggleState(false);
  });
});
