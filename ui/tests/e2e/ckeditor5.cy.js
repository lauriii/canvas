describe('ckeditor 5', () => {
  before(() => {
    cy.drupalXbInstall(
      ['xb_test_article_fields', 'filter', 'editor', 'ckeditor5'],
      ['administer filters', 'use text format minimal_html'],
    );
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });
  after(() => {
    cy.drupalUninstall();
  });
  it('works with page data form', () => {
    cy.drupalRelativeURL('admin/config/content/formats');
    cy.loadURLandWaitForXBLoaded({ clearAutoSave: false });

    // Delete the two existing image components to avoid a violation from
    // ComponentTreeMeetsRequirementsConstraintValidator which disallows the
    // use of props with adapters. This is unrelated to CKEditor 5, but
    // necessary for publishing to work violation free.
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');

    cy.waitForComponentNotInPreview('Image');
    cy.findByText('Review 1 change', { timeout: 20000 }).click();
    cy.intercept('**/xb/api/auto-saves/publish').as('publishing');
    cy.intercept('**/xb/api/auto-saves/pending').as('pending');
    cy.intercept('**/xb/api/layout/node/1').as('layout');
    cy.findByText('Publish all changes').click();
    cy.wait('@publishing');

    // Now that the violation-causing images are removed, CKEditor5 tests begin.
    cy.findByTestId('xb-contextual-panel--page-data').click({ force: true });
    const wrap = '[data-drupal-selector="edit-field-xbt-textarea-wrapper"]';
    cy.get(wrap)
      .findByRole('button', { name: 'Text format', exact: false })
      .click();
    cy.findByRole('dialog')
      .findByRole('combobox', { name: 'Text format', exact: false })
      .click();
    cy.findByRole('option', { name: 'minimal_html', exact: false }).click();

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

    // This wait is needed for this test to pass reliably. Unsure why.
    // Waiting on form build id has been attempted.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(1000);
    cy.findByText('Review 1 change', { timeout: 20000 }).click();
    cy.findByText('Publish all changes').click();
    cy.wait('@publishing');
    cy.drupalRelativeURL('node/1/edit');
    cy.get('[data-drupal-selector="edit-field-xbt-textarea-0-value"]').should(
      'contain',
      '<p><em>some italic</em> <strong>some bold</strong></p>',
    );
  });
});
