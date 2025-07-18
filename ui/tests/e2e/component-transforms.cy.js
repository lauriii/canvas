describe('Component transforms', () => {
  before(() => {
    // Enable xb_test_storage_prop_shape_alter after nodes have been created in
    // XBTestSetup. The stored nodes make use of a link item, but by enabling
    // this module, the my-hero component will now make use of an uri item.
    // We should still be able to edit existing data where the source type is
    // link rather than uri.
    cy.drupalXbInstall(['xb_test_storage_prop_shape_alter']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Applies transforms based on the stored component instance, not the component metadata for new instances', () => {
    cy.loadURLandWaitForXBLoaded();

    // Click a Hero component to open the component form.
    cy.clickComponentInPreview('Hero');

    cy.intercept('PATCH', '**/xb/api/layout/node/1');

    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('CTA 1 link')
      .as('componentFormCTA1Link');

    cy.get('@componentFormCTA1Link').clear({ force: true });
    // Ensure the input is invalid.
    const newUri = 'https://example.com/abdedfg';
    cy.get('@componentFormCTA1Link').type(newUri, { force: true });

    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('CTA 1 text')
      .as('componentFormCTA1Text');

    const linkText = 'Read more!';
    cy.get('@componentFormCTA1Text').clear();
    cy.get('@componentFormCTA1Text').type(linkText);

    // Ensure the new value shows in the preview.
    cy.waitForElementContentInIframe(
      `div[data-component-id="xb_test_sdc:my-hero"] a[href="${newUri}"]`,
      linkText,
    );
  });
});
