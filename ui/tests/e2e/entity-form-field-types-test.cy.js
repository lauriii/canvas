describe('Entity form field types', () => {
  before(() => {
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

    // Expand this to add additional coverage.
    // For each field to be tested, add a new object with the field name and
    // two methods as follows:
    // - 'edit' - The edit method receives the current Cypress instance and
    // should perform pre-condition checks (e.g. assert the default state), then
    // make an edit to the field.
    // - 'assertData' - The assertData method receives the JSON:API representation
    // of the entity after the form has been submitted and the entity has been
    // published. It should make use of expect to assert the value was correctly
    // submitted.
    // @see xb_test_article_fields_install for where the fields are created.
    const fields = {
      field_xbt_comment: {
        edit: (cy) => {
          cy.findByText('Comment settings').click();
          cy.findByText('Comment settings')
            .parents('[data-state="open"][data-drupal-selector]')
            .as('commentFieldset');
          cy.get('@commentFieldset')
            .findByLabelText('Open', { exact: false })
            .assertToggleState(false);
          cy.get('@commentFieldset')
            .findByText('Open', { exact: false })
            .click();
          cy.get('@commentFieldset')
            .findByLabelText('Open', { exact: false })
            .assertToggleState(true);
        },
        assertData: (response) => {
          expect(response.attributes.field_xbt_comment.status).to.equal(2);
        },
      },
      field_xbt_options_buttons: {
        edit: (cy) => {
          cy.findByLabelText('Option 2', { exact: false }).assertToggleState(
            true,
          );
          cy.findByText('Option 3', { exact: false }).click();
        },
        assertData: (response) => {
          expect(response.attributes.field_xbt_options_buttons).to.equal(
            'option3',
          );
        },
      },
      field_xbt_language: {
        edit: (cy) => {
          cy.findByLabelText('XB Language')
            .parent()
            .find('select')
            .as('languageSelect');
          cy.get('@languageSelect').should('have.value', 'und');
          // Radix renders this as a hidden element with a button to trigger, so
          // we have to use force.
          cy.get('@languageSelect').select('English', { force: true });
          cy.get('@languageSelect').should('have.value', 'en');
        },
        assertData: (response) => {
          expect(response.attributes.field_xbt_language).to.equal('en');
        },
      },
    };

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
