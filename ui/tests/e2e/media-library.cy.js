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

const testMediaLibraryInComponentInstanceForm = (
  cy,
  entityType = 'xb_page',
) => {
  cy.get('div[role="dialog"]').should('exist');
  cy.findByLabelText('Select The bones are their money').should(
    'not.be.checked',
  );
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

  // The image location in the preview is different depending on the entity
  // type.
  cy.get('[data-testid*="xb-component-form-"]').as('inputForm');
  cy.intercept('PATCH', '**/xb/api/v0/form/component-instance/**').as('patch');

  iterations.forEach((step, index) => {
    cy.get('@inputForm').recordFormBuildId();
    const priorAlt =
      index % 2 === 0 ? iterations[1].expectedAlt : iterations[0].expectedAlt;
    const defaultPlaceholder =
      entityType === 'xb_page'
        ? `[id^="block-"] > img[alt="${priorAlt}"]:first-of-type`
        : `img[alt="${priorAlt}"][data-xb-uuid="static-image-udf7d"]`;
    cy.log(
      `Iteration ${index + 1}: start ${index % 2 === 0 ? iterations[1].expectedAlt : iterations[0].expectedAlt}`,
    );
    cy.get('[class*="contextualPanel"]').should('exist');
    cy.get('div[role="dialog"]').should('not.exist');
    const removeIt = `[class*="contextualPanel"] .js-media-library-selection  [aria-label="${step.removeText}"][data-once="drupal-ajax"]`;
    cy.get(removeIt).click({ force: true });

    cy.log(
      `Confirm removing a required image in step ${index + 1} results in the example appearing in the preview.`,
    );

    // The prior image should still be there because the prop is required.
    cy.waitForElementInIframe(defaultPlaceholder);

    // Waiting for the build id does not work - it does not update.
    // Waiting for the preview (the last request after clicking remove) does not
    // appear to work reliably either. Hence, the fixed wait. Presumably there is
    // something that can be waited on, but it is not clear what.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(1000);
    const addIt = `[class*="contextualPanel"] .js-media-library-widget .js-media-library-open-button[data-once="drupal-ajax"]`;
    cy.get(addIt).first().click({ force: true });

    cy.get('div[role="dialog"]').should('exist');
    cy.findByLabelText(step.selectNewText).check();
    cy.get('button:contains("Insert selected")').realClick({ force: true });
    cy.wait('@patch');
    cy.get('div[role="dialog"]').should('not.exist');
    cy.get('@inputForm').shouldHaveUpdatedFormBuildId({ timeout: 11000 });
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
  cy.intercept('POST', '**/xb/api/v0/form/content-entity/**');
  // Make a record of the starting form build ID for the form
  cy.get('@entityForm').recordFormBuildId();

  // Perform media operations.
  iterations.forEach((step, ix) => {
    cy.log(`Iteration ${ix + 1}: start`);
    cy.findByRole('dialog').should('not.exist');
    cy.get('@entityForm').findByRole(step.expectedAlt).should('not.exist');
    if (ix > 0) {
      cy.intercept('POST', '**/xb/api/v0/layout/**').as('updatePreview');
      cy.get('@entityForm')
        .findByRole('button', { name: step.removeText })
        .should('exist')
        .click();
      // Wait for the preview to finish loading.
      cy.wait('@updatePreview');
      cy.findByLabelText('Loading Preview').should('not.exist');
      cy.get('@entityForm').shouldHaveUpdatedFormBuildId();
      cy.log(`Iteration ${ix + 1}: ${step.removeText} complete`);
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
    cy.intercept('POST', '**/xb/api/v0/layout/**').as('updatePreview');
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
    cy.log(`Iteration ${ix + 1}: Adding ${step.expectedAlt} complete`);
  });

  cy.publishAllPendingChanges(title);

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
    cy.drupalXbInstall(['xb_test_sdc', 'xb_test_e2e_code_components']);
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
    testMediaLibraryInComponentInstanceForm(cy, 'article');
  });

  it(
    'Can open the media library widget in an xb_page props form',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.drupalLogin('xbUser', 'xbUser');
      cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/2' });
      cy.openLibraryPanel();
      cy.get('.primaryPanelContent').findByText('Image').click();

      cy.get('.primaryPanelContent').findByText('Image').click();
      cy.get(
        '.previewOverlay [data-xb-component-id="sdc.experience_builder.image"]',
      ).should('have.length', 2);
      cy.clickComponentInPreview('Image', 0);

      cy.get(
        '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
      )
        .first()
        .click();
      testMediaLibraryInComponentInstanceForm(cy, 'xb_page');
    },
  );

  it(
    'Can open the media library widget on a page data entity form',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      testMediaLibraryInEntityForm(cy, { url: 'xb/xb_page/2' }, 'Empty Page');
    },
  );

  it(
    'Can open the media library widget on an article entity form',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      testMediaLibraryInEntityForm(
        cy,
        { url: 'xb/node/2' },
        'I am an empty node',
      );
    },
  );

  it('Can remove an optional image no example and there is no image in the preview', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.openLibraryPanel();
    cy.get(
      '[data-xb-component-id="sdc.xb_test_sdc.image-optional-without-example"]',
    ).realClick();
    cy.waitForElementNotInIframe('.layout-content img');
    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    cy.get('div[role="dialog"]').should('exist');
    cy.findByLabelText('Select The bones are their money').check();
    cy.get('button:contains("Insert selected")').click();
    cy.get('div[role="dialog"]').should('not.exist');
    cy.waitForElementInIframe('img[alt="The bones equal dollars"]');
    cy.get('[class*="contextualPanel"]')
      .findByLabelText('Remove The bones are their money')
      .click();

    // Confirms the removed optional image prop is not rendered at all, vs the
    // example/default value reappearing.
    cy.waitForElementNotInIframe('.layout-content img');
  });

  it('Can remove an optional image with example and there is no image in the preview', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.openLibraryPanel();
    cy.get(
      '[data-xb-component-id="sdc.xb_test_sdc.image-optional-with-example"]',
    ).realClick();
    cy.waitForElementInIframe('.layout-content img[alt="Boring placeholder"]');
    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    cy.get('div[role="dialog"]').should('exist');
    cy.findByLabelText('Select The bones are their money').check();
    cy.get('button:contains("Insert selected")').click();
    cy.get('div[role="dialog"]').should('not.exist');
    cy.waitForElementInIframe('img[alt="The bones equal dollars"]');
    cy.get('[class*="contextualPanel"]')
      .findByLabelText('Remove The bones are their money')
      .click();

    // Confirms the removed optional image prop is not rendered at all, vs the
    // example/default value reappearing.
    cy.waitForElementNotInIframe('.layout-content img');
  });

  it('Can remove an optional code component image with example and there is no image in the preview', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.openLibraryPanel();
    cy.get(
      '[data-xb-component-id="js.xb_test_e2e_code_components_optional_image"]',
    ).realClick();
    cy.waitForElementInIframe(
      '.layout-content img[alt="Example image placeholder"]',
    );
    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    cy.get('div[role="dialog"]').should('exist');
    cy.findByLabelText('Select The bones are their money').check();
    cy.get('button:contains("Insert selected")').click();
    cy.get('div[role="dialog"]').should('not.exist');
    cy.waitForElementInIframe('img[alt="The bones equal dollars"]');
    cy.waitForElementNotInIframe(
      '.layout-content img[alt="Example image placeholder"]',
    );
    cy.findByLabelText('text').type('{selectall}{del}A new value');
    cy.findByLabelText('text').should('have.value', 'A new value');
    cy.waitForElementContentInIframe('p', 'A new value');
    cy.get('[class*="contextualPanel"]')
      .findByLabelText('Remove The bones are their money')
      .click();

    // Confirms the removed optional image prop is not rendered at all, vs the
    // example/default value reappearing.
    cy.waitForElementNotInIframe('.layout-content img');

    // Text prop is still intact after image removal.
    cy.waitForElementContentInIframe('p', 'A new value');
    // Confirm other props still work.
    cy.findByLabelText('text').type(
      '{selectall}{del}Further changes to the value',
    );
    cy.findByLabelText('text').should(
      'have.value',
      'Further changes to the value',
    );
    cy.waitForElementContentInIframe('p', 'Further changes to the value');
  });

  it.only('Can remove a required code component image with example and there is no image in the preview', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.openLibraryPanel();
    cy.get(
      '[data-xb-component-id="js.xb_test_e2e_code_components_req_image"]',
    ).realClick();
    cy.waitForElementInIframe(
      '.layout-content img[alt="Example image placeholder"]',
    );
    cy.get(
      '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
    )
      .first()
      .click();
    cy.get('div[role="dialog"]').should('exist');
    cy.findByLabelText('Select The bones are their money').check();
    cy.get('button:contains("Insert selected")').click();
    cy.get('div[role="dialog"]').should('not.exist');
    cy.waitForElementInIframe('img[alt="The bones equal dollars"]');
    cy.waitForElementNotInIframe(
      '.layout-content img[alt="Example image placeholder"]',
    );
    cy.findByLabelText('text').type('{selectall}{del}A new value');
    cy.findByLabelText('text').should('have.value', 'A new value');
    cy.waitForElementContentInIframe('p', 'A new value');
    cy.get('[class*="contextualPanel"]')
      .findByLabelText('Remove The bones are their money')
      .click();

    // Confirm the widget is now empty.
    cy.get('.js-media-library-widget .field-prefix')
      .contains('No media items are selected.')
      .should('exist');
    cy.get('.js-media-library-widget .description')
      .contains('One media item remaining.')
      .should('exist');

    // The previously added image is still in the preview due to it being a
    // required prop.
    cy.waitForElementInIframe('img[alt="The bones equal dollars"]');

    // Confirms the example does not return.
    cy.waitForElementNotInIframe(
      '.layout-content img[alt="Example image placeholder"]',
    );

    // Text prop is still intact after image removal.
    cy.waitForElementContentInIframe('p', 'A new value');

    cy.findByLabelText('text').type(
      '{selectall}{del}Further changes to the value',
    );
    cy.findByLabelText('text').should(
      'have.value',
      'Further changes to the value',
    );
    cy.waitForElementContentInIframe('p', 'Further changes to the value');
  });
});
