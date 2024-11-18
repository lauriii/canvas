describe('Media Library', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalSession();
    // A larger viewport makes it easier to debug in the test runner app.
    cy.viewport(2000, 1000);
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Can open the media library widget in a props form', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();
    cy.waitForElementInIframe(
      '[data-xb-component-id="experience_builder:image"]',
    );

    cy.get('[class*="contextualPanel"]').should('not.exist');

    cy.clickComponentInPreview('Image', 1);

    cy.get('[class*="contextualPanel"]').should('exist');

    cy.get('div[role="dialog"]').should('not.exist');
    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    cy.get('div[role="dialog"]').should('exist');
    cy.findByLabelText('Select The bones are their money').check();
    cy.get('button:contains("Insert selected")').click();
    cy.get('div[role="dialog"]').should('not.exist');
    cy.get(
      '[class*="contextualPanel"] input[aria-label="Remove The bones are their money"]',
    ).should('exist');
    cy.get(
      '[class*="contextualPanel"] article .js-media-library-item-preview img[alt="The bones equal dollars"]',
    ).should('exist');
    cy.waitForElementInIframe(
      '[data-xb-component-id="experience_builder:image"] img[alt="The bones equal dollars"]',
    );

    // Use the Media Library widget an additional time. This effectively
    // confirms that XBTemplateRenderer is not loading JS assets that already
    // exist on the page.
    cy.clickComponentInPreview('Image');

    cy.get('[class*="contextualPanel"]').should('exist');
    cy.get('div[role="dialog"]').should('not.exist');
    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    cy.get('div[role="dialog"]').should('exist');
    cy.findByLabelText('Select Sorry I resemble a dog').check();
    cy.get('button:contains("Insert selected")').click();
    cy.get('div[role="dialog"]').should('not.exist');
    cy.get(
      '[class*="contextualPanel"] input[aria-label="Remove Sorry I resemble a dog"]',
    ).should('exist');
    cy.get(
      '[class*="contextualPanel"] article .js-media-library-item-preview img[alt="My barber may have been looking at a picture of a dog"]',
    ).should('exist');
    cy.waitForElementInIframe(
      '[data-xb-component-id="experience_builder:image"] img[alt="My barber may have been looking at a picture of a dog"]',
    );
  });
});
