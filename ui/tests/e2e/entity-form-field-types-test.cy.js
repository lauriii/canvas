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

  it('Can interact with entity path field', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });

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

    cy.intercept({
      url: '**/xb/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    const path = '/not-empty-anymore';
    cy.findByLabelText('URL alias').as('path');
    cy.get('@path').should('have.value', '/i-am-an-empty-node');
    cy.get('@path').clear();
    cy.get('@path').type(path);
    cy.get('@path').should('have.value', path);
    // Wait for the preview to finish loading.
    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    cy.findByLabelText('Change to').as('moderation-state');
    cy.get('@moderation-state')
      .parents('.js-form-wrapper')
      .as('moderation-state-wrapper');
    cy.get('@moderation-state-wrapper')
      .findByRole('combobox', { name: 'Change to', exact: false })
      .click();
    cy.findByRole('option', { name: 'Published', exact: false }).click();
    cy.get('@moderation-state')
      .parent()
      .find('select')
      .should('have.value', 'published');

    cy.findByLabelText('Alternative text').click({ force: true });
    // Wait for the preview to finish loading.
    cy.findByLabelText('Loading Preview').should('not.exist');
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(15000);
    // Save changes.
    cy.publishAllPendingChanges('I am an empty node');

    // Request all articles over JSON:API.
    cy.request('/jsonapi/node/article').then((listResponse) => {
      expect(listResponse.status).to.eq(200);
      const data = listResponse.body.data;
      // Filter down to just node 2 which we've been editing.
      const nodeData = data
        .filter((item) => item.attributes.drupal_internal__nid === 2)
        .shift();
      expect(nodeData.attributes.path.alias).to.equal(path);
    });
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
    cy.intercept('POST', '**/xb/api/v0/form/content-entity/**');
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
        url: '**/xb/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      value.edit(cy);
      // Wait for the preview to finish loading.
      cy.wait('@updatePreview');
      cy.findByLabelText('Loading Preview').should('not.exist');
    });

    cy.publishAllPendingChanges('I am an empty node');

    // Request all articles over JSON:API.
    cy.request('/jsonapi/node/article').then((listResponse) => {
      expect(listResponse.status).to.eq(200);
      const data = listResponse.body.data;
      // Filter down to just node 2 which we've been editing.
      const nodeData = data
        .filter((item) => item.attributes.drupal_internal__nid === 2)
        .shift();
      // Then request the latest working copy of this node using its UUID. We need to
      // request it specifically because the content moderation widget test marks the
      // edit as a draft. JSON:API collections do not support working copies.
      cy.request(
        `jsonapi/node/article/${nodeData.id}?resourceVersion=rel:working-copy`,
      ).then((itemResponse) => {
        expect(itemResponse.status).to.eq(200);
        // Perform assertions on the draft entity.
        Object.entries(fields).forEach(([key, value]) => {
          cy.log(`Performing validation for ${key}`);
          value.assertData(itemResponse.body.data);
        });
      });
    });
  });
});
