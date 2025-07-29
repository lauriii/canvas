describe('♾️ Link component', () => {
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
    'A link prop renders with the canonical alias if existing',
    { retries: { openMode: 0, runMode: 1 } },
    () => {
      cy.drupalLogin('xbUser', 'xbUser');
      cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
      cy.openLibraryPanel();
      cy.get('.primaryPanelContent').should('contain.text', 'Components');
      cy.get('.primaryPanelContent')
        .findByText('My Code Component Link')
        .click();
      const iframeSelector =
        '[data-test-xb-content-initialized="true"][data-xb-swap-active="true"]';
      cy.waitForElementInIframe('a[href*="/llamas"]', iframeSelector, 10000);
      cy.get('[data-testid*="xb-component-form-"]').as('inputForm');
      cy.get('@inputForm').recordFormBuildId();
      // Log all ajax form requests to help with debugging.
      cy.intercept('PATCH', '**/xb/api/v0/form/component-instance/**').as(
        'patch',
      );
      cy.get('@inputForm').findByLabelText('Link').clear();
      cy.get('@inputForm')
        .findByLabelText('Link')
        .type('/node/3?param=value#fragment');
      cy.get('@inputForm').findByLabelText('Link').blur();
      cy.waitForElementInIframe(
        'a[href*="/the-one-with-a-block?param=value#fragment"]',
        iframeSelector,
        10000,
      );
    },
  );
});
