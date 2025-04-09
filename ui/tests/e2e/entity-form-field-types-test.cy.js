import fields from './entity-form-fields/index.js';

describe('Entity form field types', () => {
  before(() => {
    // We need to set the timezone in the running browser too.
    Cypress.automation('remote:debugger:protocol', {
      command: 'Emulation.setTimezoneOverride',
      params: {
        timezoneId: 'Australia/Sydney',
      },
    });
    cy.drupalXbInstall([
      // Adds the required fields.
      'xb_test_article_fields',
      // For validating the shape of the node.
      'jsonapi',
    ]);
  });

  beforeEach(() => {
    cy.drupalSession();
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Can interact with form fields', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });

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

    cy.task('countFiles', './tests/e2e/entity-form-fields/field_*.js').then(
      (count) => {
        expect(count).to.equal(Object.entries(fields).length);
      },
    );

    // Perform field edits.
    Object.entries(fields).forEach(([key, value]) => {
      cy.log(`Performing edits for ${key}`);
      cy.intercept({
        url: '**/xb/api/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      value.edit(cy);
      // Wait for the preview to finish loading.
      cy.wait('@updatePreview');
      cy.findByLabelText('Loading Preview').should('not.exist');
    });

    cy.publishAllPendingChanges('I am an empty node');

    // Request the node over jsonapi.
    cy.request('/jsonapi/node/article').then((response) => {
      expect(response.status).to.eq(200);
      const data = response.body.data;
      const nodeData = data
        .filter((item) => item.attributes.drupal_internal__nid === 2)
        .shift();
      // Perform validation.
      Object.entries(fields).forEach(([key, value]) => {
        cy.log(`Performing validation for ${key}`);
        value.assertData(nodeData);
      });
    });
  });
});
