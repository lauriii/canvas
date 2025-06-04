// cspell:ignore macbook
describe('Operate on components in global regions', () => {
  before(() => {
    cy.drupalXbInstall();
    cy.drupalEnableTheme('olivero');
    cy.drupalEnableThemeForXb('olivero');
    cy.viewport('macbook-13');
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Visits a global region URL directly', () => {
    cy.drupalRelativeURL(`xb/node/1/editor/region/breadcrumb`);
    cy.previewReady();

    cy.url().should('contain', `/xb/node/1/editor/region/breadcrumb`);
    // We should be in a region and so there should be an option to go back to the content region.
    cy.findByTestId('xb-topbar').findByLabelText('Back to Content region');
  });

  it(
    'Can hover global regions',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.loadURLandWaitForXBLoaded();
      cy.findByTestId('scale-to-fit').click();
      cy.findByLabelText('Select zoom level').realClick({ force: true });
      cy.get('[role="option"]').contains('50%').click();
      cy.get('#xbPreviewOverlay').realMouseWheel({ deltaY: -5 });
      cy.log(
        'Can hover a region in the library and see the overlay in the preview (checking for the nameTag)',
      );
      cy.get('.primaryPanelContent')
        .findByText('Content Above')
        .trigger('mouseover', { force: true, scrollBehavior: false });
      cy.get('.xb--viewport-overlay .xb--region-overlay__content_above')
        .should('have.length', 1)
        .and('be.visible');

      cy.get('.xb--viewport-overlay').within(() => {
        cy.findByText('Content Above').should('be.visible');
      });

      cy.get('.primaryPanelContent')
        .findByText('Breadcrumb')
        .trigger('mouseover', { force: true, scrollBehavior: false });
      cy.get('.xb--viewport-overlay .xb--region-overlay__breadcrumb')
        .should('have.length', 1)
        .and('be.visible');
      cy.get('.xb--viewport-overlay').within(() => {
        cy.findByText('Breadcrumb').should('be.visible');
      });

      cy.log(
        'Can hover a region in the preview and it is marked as hovered in the Library',
      );

      cy.get('.xb--viewport-overlay .xb--region-overlay__header').trigger(
        'mouseover',
        { force: true, scrollBehavior: false },
      );
      cy.get(
        '.xb--viewport-overlay .xb--region-overlay__header [data-testid="xb-name-tag"] #header-name',
      ).should('have.text', 'Header');

      cy.get('.primaryPanelContent')
        .findByText('Header')
        .parents() // Traverse up to find all ancestors
        .filter((index, element) => element.hasAttribute('data-hovered')) // Filter elements with the attribute
        .should('have.attr', 'data-hovered', 'true')
        .its('length')
        .should('be.gte', 1); // Ensure at least one such element exists
    },
  );

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

    cy.log('The overlay for the other regions should not be rendering');
    cy.get('.xb--viewport-overlay [class*="xb--region-overlay__"]').should(
      'have.length',
      1,
    );
  });

  it(
    'Can move components between global regions',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.loadURLandWaitForXBLoaded();
      cy.findByTestId('xb-primary-panel').as('layersTree');

      cy.focusRegion('Breadcrumb');
      cy.findByTestId('xb-topbar')
        .findByLabelText('Back to Content region')
        .should('exist');

      cy.log('Move "Breadcrumbs" component UP into the Highlighted Region');
      cy.sendComponentToRegion('Breadcrumbs', 'Highlighted');

      cy.log(
        'Because that was the only component in the region, the user should be navigated back up to the content region',
      );
      // spotlight isn't showing anymore
      cy.get('.spotlight').should('not.exist');
      // option to navigate back to content is gone.
      cy.findByTestId('xb-topbar')
        .findByLabelText('Back to Content region')
        .should('not.exist');

      cy.focusRegion('Highlighted');
      cy.log(
        '"Breadcrumbs" component should now be the LAST child in the Highlighted region',
      );
      cy.get('@layersTree')
        .findByLabelText('Breadcrumbs')
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
      cy.sendComponentToRegion('User account menu', 'Highlighted');

      cy.focusRegion('Highlighted');
      cy.log(
        '"User account menu" component should now be the FIRST child in the Highlighted region',
      );
      cy.get('@layersTree')
        .findByLabelText('User account menu')
        .then(($div) => {
          // Assert that the div is the first child of its new parent region.
          expect($div.is(':first-child')).to.be.true;
        });
    },
  );
});
