export const edit = (cy) => {
  cy.findByLabelText('Option 2', { exact: false }).assertToggleState(true);
  cy.findByText('Option 3', { exact: false }).click();
};
export const assertData = (response) => {
  expect(response.attributes.field_xbt_options_buttons).to.equal('option3');
};
