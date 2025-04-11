export const edit = (cy) => {
  cy.findByLabelText('Change to').as('moderation-state');
  cy.get('@moderation-state')
    .parents('.js-form-wrapper')
    .as('moderation-state-wrapper');
  cy.get('@moderation-state-wrapper')
    .findByRole('combobox', { name: 'Change to', exact: false })
    .click();
  cy.findByRole('option', { name: 'Published', selected: true, exact: false });
  cy.findByRole('option', { name: 'Draft', exact: false }).click();
  cy.get('@moderation-state')
    .parent()
    .find('select')
    .should('have.value', 'draft');
};
export const assertData = (response) => {
  expect(response.attributes.moderation_state).to.equal('draft');
};
