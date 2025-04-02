import { queries } from '@testing-library/dom';

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

const testMediaLibraryInComponentInstanceForm = (cy) => {
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

  cy.get('[data-testid*="xb-component-form-"]').as('inputForm');
  iterations.forEach((step) => {
    cy.get('[class*="contextualPanel"]').should('exist');
    cy.get('div[role="dialog"]').should('not.exist');
    cy.get('@inputForm').recordFormBuildId();
    cy.get('[class*="contextualPanel"]')
      .findByLabelText(step.removeText)
      .click();
    cy.get('@inputForm').shouldHaveUpdatedFormBuildId();
    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    cy.get('div[role="dialog"]').should('exist');
    cy.findByLabelText(step.selectNewText).check();
    cy.get('button:contains("Insert selected")').click();
    cy.get('div[role="dialog"]').should('not.exist');
    cy.get('@inputForm').shouldHaveUpdatedFormBuildId();
    cy.get(
      `[class*="contextualPanel"] input[aria-label="${step.removeAriaLabel}"]`,
    ).should('exist');
    cy.get(
      `[class*="contextualPanel"] article .js-media-library-item-preview img[alt="${step.expectedAlt}"]`,
    ).should('exist');
    cy.waitForElementInIframe(`img[alt="${step.expectedAlt}"]`);
  });
};

const testMediaLibraryInEntityForm = (cy, loadOptions = {}, title) => {
  cy.drupalLogin('xbUser', 'xbUser');
  // Node 1 includes prop sources that make use of adapters, we need to
  // make sure there are no auto-save entries for that node before we attempt
  // to publish. This test interacts with that node in the "Can open the media
  // library widget in an article props form" case which causes an invalid entry
  // in auto-save that prevents publishing.
  cy.clearAutoSave('node', 1);

  cy.loadURLandWaitForXBLoaded(loadOptions);

  cy.findByTestId('xb-contextual-panel--page-data').should(
    'have.attr',
    'data-state',
    'active',
  );
  cy.findByTestId('xb-page-data-form').as('entityForm');
  // Log all ajax form requests to help with debugging.
  cy.intercept('POST', '**/xb/api/form/content-entity/**');
  // Make a record of the starting form build ID for the form
  cy.get('@entityForm').recordFormBuildId();

  // Perform media operations.
  iterations.forEach((step, ix) => {
    cy.findByRole('dialog').should('not.exist');
    cy.get('@entityForm').findByRole(step.expectedAlt).should('not.exist');
    if (ix > 0) {
      cy.intercept('POST', '**/xb/api/layout/**').as('updatePreview');
      cy.get('@entityForm')
        .findByRole('button', { name: step.removeText })
        .should('exist')
        .click();
      // Wait for the preview to finish loading.
      cy.wait('@updatePreview');
      cy.findByLabelText('Loading Preview').should('not.exist');
      cy.get('@entityForm').shouldHaveUpdatedFormBuildId();
    }
    cy.get('@entityForm')
      .findByRole('button', { name: 'Add media', timeout: 10000 })
      .should('not.be.disabled')
      .click();
    // The first time the media dialog opens there are a lot of CSS files to
    // load, and it can take more than the default timeout of 4s.
    cy.findByRole('dialog', { timeout: 10000 }).as('dialog');
    cy.get('@entityForm').shouldHaveUpdatedFormBuildId();
    cy.get('@dialog').findByLabelText(step.selectNewText).check();
    cy.intercept('POST', '**/xb/api/layout/**').as('updatePreview');
    cy.get('@dialog')
      .findByRole('button', {
        name: 'Insert selected',
      })
      .click();
    cy.findByRole('dialog').should('not.exist');
    // Wait for the preview to finish loading.
    cy.wait('@updatePreview', { timeout: 10000 });
    cy.findByLabelText('Loading Preview').should('not.exist');
    cy.get('@entityForm').findByAltText(step.expectedAlt).should('exist');
    cy.get('@entityForm')
      .findByRole('button', { name: step.removeAriaLabel })
      .should('exist');
    cy.get('@entityForm').shouldHaveUpdatedFormBuildId();
  });

  // Publish changes and make sure image persists.
  // Wait for any pending changes to refresh.
  cy.findByRole('button', {
    name: /Review \d+ change/,
    timeout: 20000,
  }).as('review');
  // We break this up to allow for the pending changes refresh which can disable
  // the button whilst it is loading.
  cy.get('@review').click();
  // Enable extended debug output from failed publishing.
  cy.intercept('**/xb/api/auto-saves/publish');
  cy.findByTestId('xb-publish-reviews-content')
    .as('publishReview')
    .should('exist');
  // We put the whole publish review step in a single should so it can be
  // retried as a group. Unfortunately this requires dropping down to raw
  // testing library queries because you can't make use of cypress commands
  // inside a should block.
  cy.get('@publishReview', { timeout: 10000 }).should(async (element) => {
    const container = element[0];
    const entity = await queries.findByText(container, title);
    expect(entity).to.exist;
    const button = await queries.findByText(container, 'Publish all changes');
    expect(button).to.exist;
    Cypress.$(button).click();
    const success = await queries.findByText(
      container,
      'All changes published!',
    );
    expect(success).to.exist;
    const errors = await queries.queryByText(container, 'Errors');
    expect(errors).not.to.exist;
  });

  // Reload the page and ensure the saved value persists.
  cy.loadURLandWaitForXBLoaded({ ...loadOptions, clearAutoSave: false });
  const lastStep = iterations.pop();
  // It can take a bit for the entity form to load, so let's give it a bit
  // longer.
  cy.get('@entityForm')
    .findByAltText(lastStep.expectedAlt, { timeout: 10000 })
    .should('exist');
  cy.get('@entityForm')
    .findByRole('button', { name: lastStep.removeAriaLabel })
    .should('exist');
};

describe('Media Library', () => {
  before(() => {
    cy.drupalXbInstall();
    cy.drupalEnableTheme('claro', true);
  });

  beforeEach(() => {
    cy.drupalSession();
    // A larger viewport makes it easier to debug in the test runner app.
    cy.viewport(2000, 1000);
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Can open the media library widget in an article props form', () => {
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
      .findByLabelText('Remove Hero image')
      .click();
    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    testMediaLibraryInComponentInstanceForm(cy);
  });

  it('Can open the media library widget in an xb_page props form', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/2' });
    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').findByText('Image').click();

    cy.get('.primaryPanelContent').findByText('Image').click();
    cy.get(
      '.previewOverlay [data-xb-component-id="sdc.experience_builder.image"]',
    ).should('have.length', 4);
    cy.clickComponentInPreview('Image', 0);

    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    testMediaLibraryInComponentInstanceForm(cy);
  });

  it('Can open the media library widget on a page data entity form', () => {
    testMediaLibraryInEntityForm(cy, { url: 'xb/xb_page/2' }, 'Empty Page');
  });

  it('Can open the media library widget on an article entity form', () => {
    testMediaLibraryInEntityForm(
      cy,
      { url: 'xb/node/2' },
      'I am an empty node',
    );
  });
});
