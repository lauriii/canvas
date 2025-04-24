export const edit = (cy) => {
  cy.findByLabelText('XB Entity Reference (Tags)').as('tags');
  cy.get('@tags').should(
    'have.value',
    'Air-Sea Dolphin (1), The Apples in Stereo (2)',
  );
  cy.get('@tags').type(', Black Swan');
  cy.get('ul.ui-autocomplete:visible').should('exist');
  cy.get('ul.ui-autocomplete:visible li').should(
    'have.text',
    'Black Swan Network',
  );
  cy.get('ul.ui-autocomplete:visible li').click();
  cy.get('@tags').should(
    'have.value',
    'Air-Sea Dolphin (1), The Apples in Stereo (2), Black Swan Network (4)',
  );
};
export const assertData = (response) => {
  expect(
    response.relationships.field_xbt_entity_ref_tags.data.map(
      (item) => item.meta.drupal_internal__target_id,
    ),
  ).to.deep.eq([1, 2, 4]);
};
