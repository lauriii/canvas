export const edit = (cy) => {
  cy.findByLabelText('XB Telephone').as('telephone');
  cy.get('@telephone').should('have.value', '');
  cy.get('@telephone').type('1-800-444-4444');
  cy.get('@telephone').should('have.value', '1-800-444-4444');
};
export const assertData = (response) => {
  expect(response.attributes.field_xbt_telephone).to.equal('1-800-444-4444');
};
