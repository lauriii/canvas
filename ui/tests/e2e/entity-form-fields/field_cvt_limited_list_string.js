export const edit = (cy) => {
  cy.get('.field--name-field-cvt-limited-list-string').as('list-field');
  cy.get('@list-field').scrollIntoView();
  // 'One' and 'Two' are already selected by default; open dropdown and add the rest.
  cy.get('@list-field')
    .find('.canvas-select__dropdown-indicator')
    .click({ force: true });
  cy.get('@list-field').contains('.canvas-select__option', 'Three').click();
  cy.get('@list-field')
    .find('.canvas-select__multi-value')
    .should('have.length', 3);
};

export const assertData = (response) => {
  expect(response.attributes.field_cvt_limited_list_string).to.have.members([
    'one',
    'two',
    'three',
  ]);
};
