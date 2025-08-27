// @cspell:ignore Meatspace
describe('⚡️ Real time code component previews', () => {
  beforeEach(() => {
    cy.drupalXbInstall(['xb_test_code_components']);
    cy.drupalSession();
    // A larger viewport makes it easier to debug in the test runner app.
    cy.viewport(2000, 1000);
  });

  afterEach(() => {
    cy.drupalUninstall();
  });

  it(
    'Scalar code component props update the preview in real time',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.drupalLogin('xbUser', 'xbUser');
      cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
      cy.openLibraryPanel();
      // Wait for the component list to load.
      cy.get('.primaryPanelContent').should('contain.text', 'Components');
      // Add the 'With props' code component.
      cy.get('.primaryPanelContent').findByText('With props').click();
      // Check the default values display in the preview.
      cy.waitForElementContentInIframe(
        'div',
        'Component With props, Hello XB, 40 years old.',
      );
      cy.get('[data-testid*="xb-component-form-"]').as('inputForm');
      let previewHasUpdated = false;
      // Catch the PATCH request to the API Layout controller.
      cy.intercept(
        {
          method: 'PATCH',
          url: '**/xb/api/v0/layout/node/2',
          // Should only happen once, polled until the user-entry has settled
          times: 1,
        },
        () => {
          // This should only fire after the preview has been updated.
          expect(previewHasUpdated).to.equal(true);
        },
      ).as('patch');
      // Clear and update the value of the name prop.
      cy.get('@inputForm').findByLabelText('name').clear();
      cy.get('@inputForm')
        .findByLabelText('name')
        .type('A Laughing Death in Meatspace');
      // The value should update in the preview in real-time before the PATCH
      // request has fired or completed.
      cy.waitForElementContentInIframe(
        'div',
        'Component With props, Hello A Laughing Death in Meatspace, 40 years old.',
      );
      previewHasUpdated = true;
      cy.wait('@patch').then(() => {
        // This should only fire after the preview has been updated.
        expect(previewHasUpdated).to.equal(true);
      });
    },
  );
});
