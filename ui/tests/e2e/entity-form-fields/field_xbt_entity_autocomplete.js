export const edit = (cy) => {
  cy.findByLabelText('XB Entity Reference (Autocomplete)').as('autocomplete');
  cy.get('@autocomplete').should(
    'have.value',
    'XB With a block in the layout (3)',
  );
  cy.get('@autocomplete').clear({ force: true });
  cy.get('@autocomplete').realType('XB Needs This For', { force: true });
  cy.get('ul.ui-autocomplete').should('exist');
  cy.get('ul.ui-autocomplete li a').should(
    'have.text',
    'XB Needs This For The Time Being',
  );
  cy.get('ul.ui-autocomplete li a').type('{enter}', { force: true });
  cy.get('@autocomplete').should(
    'have.value',
    'XB Needs This For The Time Being (1)',
  );
};
export const assertData = (response) => {
  expect(
    response.relationships.field_xbt_entity_ref_auto.data.meta
      .drupal_internal__target_id,
  ).to.equal(1);
};
