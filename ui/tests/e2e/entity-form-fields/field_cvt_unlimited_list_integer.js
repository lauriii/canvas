export const edit = (cy) => {
  cy.get('.field--name-field-cvt-unlimited-list-integer').as('list-field');
  cy.get('@list-field').scrollIntoView();
  // '1' is already selected by default; open dropdown and add the rest.
  cy.get('@list-field')
    .find('.canvas-select__dropdown-indicator')
    .click({ force: true });
  cy.get('@list-field').contains('.canvas-select__option', '2').click();
  cy.get('@list-field').contains('.canvas-select__option', '3').click();
  cy.get('@list-field').contains('.canvas-select__option', '4').click();
  cy.get('@list-field')
    .find('.canvas-select__multi-value')
    .should('have.length', 4);
};

export const assertData = (response) => {
  expect(response.attributes.field_cvt_unlimited_list_integer).to.have.members([
    1, 2, 3, 4,
  ]);
};
