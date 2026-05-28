const items = [
  'The Music Tapes',
  'Neutral Milk Hotel',
  'of Montreal',
  'The Olivia Tremor Control',
];

// Helper function to open popover for a specific row and type text.
const typeInRow = (cy, rowIndex, text) => {
  cy.get('@textfield_multi')
    .find('tbody tr')
    .eq(rowIndex)
    .find('[class*="_listItem_"]')
    .click();
  // Type in the input field that appears in the popover.
  cy.get('[role="dialog"][data-state="open"]')
    .find('input[type="text"]')
    .clear();
  cy.get('[role="dialog"][data-state="open"]')
    .find('input[type="text"]')
    .type(text);
  // Press Enter to close the dialog.
  cy.get('[role="dialog"][data-state="open"]')
    .find('input[type="text"]')
    .type('{enter}');
  // Wait for popover to close after Enter.
  cy.get('[role="dialog"][data-state="open"]').should('not.exist');
};

// Helper function to verify text content in a row.
const verifyRowText = (cy, rowIndex, expectedText) => {
  cy.get('@textfield_multi')
    .find('tbody tr')
    .eq(rowIndex)
    .find('[class*="_itemText_"]')
    .should('have.text', expectedText);
};
export const edit = (cy) => {
  cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
    .parents('.js-form-wrapper')
    .as('textfield_multi');
  cy.get('@textfield_multi')
    .findByRole('button', { name: '+ Add new' })
    .as('add-another-text');
  cy.findByLabelText('Canvas Unlimited Text (value 1)').should(
    'have.value',
    'Marshmallow Coast',
  );
  items.forEach((item, ix) => {
    // Type into the row (ix + 1 because index 0 is the first item with default value).
    typeInRow(cy, ix + 1, item);

    // Verify the value was set.
    verifyRowText(cy, ix + 1, item);
    // Wait for the preview to finish loading.
    cy.reasonableWait();
    // Queue another intercept for the wait in the main test and/or the next
    // iteration in the loop.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    // Despite waiting on the layout request and AJAX completion, this wait is
    // still necessary in order to prevent a specific problem where the first
    // only the first item in the loop makes it to the published version of the
    // node, despite all items being properly added to the form.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(400);
    cy.get('@add-another-text').click({ force: true });
    cy.selectorShouldHaveUpdatedFormBuildId(
      '[data-testid="canvas-page-data-form"]',
    );
  });
};
export const assertData = (response) => {
  // Add the default field value.
  // @see \canvas_test_article_fields_install().
  expect(response.attributes.field_cvt_unlimited_text).to.deep.eq([
    'Marshmallow Coast',
    ...items,
  ]);
};
