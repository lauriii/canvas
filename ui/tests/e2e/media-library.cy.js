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
    cy.getComponentInPreview('Image', 0);

    cy.findByTestId('xb-contextual-panel--page-data').should(
      'have.attr',
      'data-state',
      'active',
    );

    // There are two images here, the second one is making use of an image
    // adapter which we don't support yet. We have to use the first one instead.
    cy.clickComponentInPreview('Image', 0);

    cy.findByTestId('xb-contextual-panel--settings').should(
      'have.attr',
      'data-state',
      'active',
    );

    cy.get('div[role="dialog"]').should('not.exist');
    // Click the remove button to reveal the open button.
    cy.get(`[class*="contextualPanel"]`)
      .findByLabelText('Remove Default placeholder image')
      .click();
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
    cy.waitForElementInIframe('img[alt="The bones equal dollars"]');

    // Use the Media Library widget an additional time. This effectively
    // confirms that XBTemplateRenderer is not loading JS assets that already
    // exist on the page. Click to the second image to change the form, then
    // click back again.
    cy.clickComponentInPreview('Image', 1);
    cy.clickComponentInPreview('Image');

    const iterations = [
      {
        removeText: 'Remove The bones are their money',
        selectNewText: 'Select Sorry I resemble a dog',
        removeAriaLabel: 'Remove Sorry I resemble a dog',
        expectedAlt: 'My barber may have been looking at a picture of a dog',
      },
      {
        removeText: 'Remove Sorry I resemble a dog',
        selectNewText: 'Select The bones are their money',
        removeAriaLabel: 'Remove The bones are their money',
        expectedAlt: 'The bones equal dollars',
      },
      {
        removeText: 'Remove The bones are their money',
        selectNewText: 'Select Sorry I resemble a dog',
        removeAriaLabel: 'Remove Sorry I resemble a dog',
        expectedAlt: 'My barber may have been looking at a picture of a dog',
      },
    ];

    iterations.forEach((step) => {
      cy.get('[class*="contextualPanel"]').should('exist');
      cy.get('div[role="dialog"]').should('not.exist');
      cy.get('[class*="contextualPanel"]')
        .findByLabelText(step.removeText)
        .click();
      cy.get(
        '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
      )
        .first()
        .click();
      cy.get('div[role="dialog"]').should('exist');
      cy.findByLabelText(step.selectNewText).check();
      cy.get('button:contains("Insert selected")').click();
      cy.get('div[role="dialog"]').should('not.exist');
      cy.get(
        `[class*="contextualPanel"] input[aria-label="${step.removeAriaLabel}"]`,
      ).should('exist');
      cy.get(
        `[class*="contextualPanel"] article .js-media-library-item-preview img[alt="${step.expectedAlt}"]`,
      ).should('exist');
      cy.waitForElementInIframe(`img[alt="${step.expectedAlt}"]`);
    });

    cy.pause();
  });
});
