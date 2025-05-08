describe('ckeditor 5', () => {
  before(() => {
    cy.drupalXbInstall(['xb_test_article_fields']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });
  after(() => {
    cy.drupalUninstall();
  });
  it('works with page data form', () => {
    cy.loadURLandWaitForXBLoaded();

    // Delete the two existing image components to avoid a violation from
    // ComponentTreeMeetsRequirementsConstraintValidator which disallows the
    // use of props with adapters. This is unrelated to CKEditor 5, but
    // necessary for publishing to work violation free.
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');

    cy.waitForComponentNotInPreview('Image');
    cy.publishAllPendingChanges();

    // Now that the violation-causing images are removed, CKEditor5 tests begin.
    cy.findByTestId('xb-contextual-panel--page-data').click({ force: true });
    const wrap = '[data-drupal-selector="edit-field-xbt-textarea-wrapper"]';
    cy.get(wrap).findByTestId('text-format-select').select('minimal_html');

    cy.get(
      `${wrap} [data-cke-tooltip-text="Source"][aria-pressed="false"]`,
    ).click({ scrollBehavior: false, force: true });
    cy.get(
      `${wrap} [data-cke-tooltip-text="Source"][aria-pressed="true"]`,
    ).should('exist');
    cy.get(`${wrap} textarea[aria-label="Source code editing area"]`).type(
      '<em>some italic</em> <b>some bold</b>',
    );
    cy.get(
      `${wrap} [data-cke-tooltip-text="Source"][aria-pressed="true"]`,
    ).click();
    cy.get(
      `${wrap} [data-cke-tooltip-text="Source"][aria-pressed="false"]`,
    ).should('exist');

    cy.publishAllPendingChanges();
    cy.drupalRelativeURL('node/1/edit');
    cy.get('[data-drupal-selector="edit-field-xbt-textarea-0-value"]').should(
      'contain',
      '<p><em>some italic</em> <strong>some bold</strong></p>',
    );
  });
});
