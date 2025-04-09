export const edit = (cy) => {
  cy.findByText('Comment settings').click();
  cy.findByText('Comment settings')
    .parents('[data-state="open"][data-drupal-selector]')
    .as('commentFieldset');
  cy.get('@commentFieldset')
    .findByLabelText('Open', { exact: false })
    .assertToggleState(false);
  cy.get('@commentFieldset').findByText('Open', { exact: false }).click();
  cy.get('@commentFieldset')
    .findByLabelText('Open', { exact: false })
    .assertToggleState(true);
};
export const assertData = (response) => {
  expect(response.attributes.field_xbt_comment.status).to.equal(2);
};
