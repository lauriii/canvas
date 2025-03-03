describe('extending experience builder', () => {
  before(() => {
    cy.drupalXbInstall();
    cy.drupalInstallModule('xb_test_extension');
    cy.drupalInstallModule('xb_dev_mode');
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

    cy.get('.primaryPanelContent [data-state="open"]').contains('Components');

    // Get the components list from the sidebar so it can be compared to the
    // component select dropdown provided by the extension.
    cy.get('.primaryPanelContent [data-state="open"] [data-xb-name]').then(
      ($components) => {
        const availableComponents = [];

        $components.each((index, item) => {
          availableComponents.push(item.textContent.trim());
        });

        cy.findByLabelText('Extensions').click();
        cy.findByText('XB Test Extension').click();

        cy.findByTestId('ex-select-component').then(($select) => {
          const extensionComponents = [];
          // Get all the items with values in the extension component list, which
          // will be compared to the component list from the XB UI.
          $select.find('option').each((index, item) => {
            if (item.value) {
              extensionComponents.push(item.textContent.trim());
            }
          });

          // Check if every available component is included in the extension components
          const allAvailableComponentsExist = availableComponents.every(
            (component) => extensionComponents.includes(component),
          );

          expect(
            allAvailableComponentsExist,
            'All library components exist in the extension component dropdown',
          ).to.be.true;
        });
      },
    );

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
