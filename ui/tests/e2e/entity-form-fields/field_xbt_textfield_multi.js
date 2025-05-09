const items = [
  'The Music Tapes',
  'Neutral Milk Hotel',
  'of Montreal',
  'The Olivia Tremor Control',
];
export const edit = (cy) => {
  cy.findByRole('heading', { name: 'XB Unlimited Text' })
    .parents('.js-form-wrapper')
    .as('textfield_multi');
  cy.get('@textfield_multi')
    .findByRole('button', { name: 'Add another item' })
    .as('add-another-text');
  cy.findByLabelText('XB Unlimited Text (value 1)').should(
    'have.value',
    'Marshmallow Coast',
  );
  items.forEach((item, ix) => {
    cy.findByLabelText(`XB Unlimited Text (value ${ix + 2})`).type(item);
    cy.findByLabelText(`XB Unlimited Text (value ${ix + 2})`).should(
      'have.value',
      item,
    );
    // Wait for the preview to finish loading.
    cy.wait('@updatePreview');
    // Queue another intercept for the wait in the main test and/or the next
    // iteration in the loop.
    cy.intercept({
      url: '**/xb/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');
    cy.get('@add-another-text').click();
    cy.get('@entityForm').shouldHaveUpdatedFormBuildId();
  });
};
export const assertData = (response) => {
  // Add the default field value.
  // @see \xb_test_article_fields_install().
  expect(response.attributes.field_xbt_unlimited_text).to.deep.eq([
    'Marshmallow Coast',
    ...items,
  ]);
};
