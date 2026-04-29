export const edit = (cy) => {
  cy.get('.field--name-field-cvt-unlimited-list-string').as('list-field');
  cy.get('@list-field').scrollIntoView();
  // 'One' is already selected by default; open dropdown and add the rest.
  cy.get('@list-field')
    .find('.canvas-select__dropdown-indicator')
    .click({ force: true });
  cy.get('@list-field').contains('.canvas-select__option', 'Two').click();
  cy.get('@list-field').contains('.canvas-select__option', 'Three').click();
  cy.get('@list-field').contains('.canvas-select__option', 'Four').click();
  cy.get('@list-field')
    .find('.canvas-select__multi-value')
    .should('have.length', 4);
};

export const assertData = (response) => {
  expect(response.attributes.field_cvt_unlimited_list_string).to.have.members([
    'one',
    'two',
    'three',
    'four',
  ]);
};
