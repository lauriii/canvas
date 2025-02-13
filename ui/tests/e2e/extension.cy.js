describe('extending experience builder', () => {
  before(() => {
    cy.drupalXbInstall();
    cy.drupalInstallModule('xb_test_extension');
  });

  after(() => {
    cy.drupalUninstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  it('Insert, focus, delete a component', () => {
    cy.loadURLandWaitForXBLoaded();
    cy.openLibraryPanel();
    const availableComponents = [];
    cy.get('.primaryPanelContent [data-state="open"]').contains('Components');

    // Get the components list from the sidebar so it can be compared to the
    // component select dropdown provided by the extension.
    cy.get('.primaryPanelContent [data-state="open"] [data-xb-name]').then(
      ($components) => {
        $components.each((index, item) => {
          availableComponents.push(item.textContent);
        });
      },
    );

    cy.findByTestId('ex-select-component').then(($select) => {
      const extensionComponents = [];
      // Get all the items with values in the extension component list, which
      // will be compared to the component list from the XB UI.
      $select.find('option').each((index, item) => {
        if (item.value) {
          extensionComponents.push(item.textContent);
        }
      });

      // Comparing these two arrays as strings works reliably was opposed to
      // deep equal.
      expect(
        extensionComponents.sort().join(),
        'The extension provides a components dropdown with every available component in the XB UI',
      ).to.equal(availableComponents.sort().join());
    });

    cy.log(
      'Confirm that an extension can select an item in the layout, focus it, then delete it',
    );
    cy.waitForElementContentInIframe('div', 'hello, world!');
    const heroUuid = 'static-static-card1ab';
    cy.findByTestId('ex-select-in-layout').select(heroUuid);
    cy.findByTestId('ex-selected-element').should('be.empty');
    cy.findByTestId('ex-focus').click();
    cy.findByTestId('ex-selected-element').should('have.text', heroUuid);
    cy.findByTestId('xb-contextual-panel').should('exist');
    cy.findByTestId('ex-delete').click();
    cy.waitForElementContentNotInIframe('div', 'hello, world!');
  });
});
