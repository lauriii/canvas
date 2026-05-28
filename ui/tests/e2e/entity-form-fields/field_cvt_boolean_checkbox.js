export const edit = (cy) => {
  cy.findByLabelText('Canvas Boolean Checkbox (default true)').as(
    'checkboxDefaultTrue',
  );
  cy.get('@checkboxDefaultTrue').should('have.attr', 'aria-checked', 'true');
  cy.get('@checkboxDefaultTrue').click();
  cy.get('@checkboxDefaultTrue').should('have.attr', 'aria-checked', 'false');
  // Wait for the preview to finish loading.
  cy.wait('@updatePreview');
  cy.findByLabelText('Loading Preview').should('not.exist');

  // Trigger a new intercept for the main test to wait for.
  cy.intercept({
    url: '**/canvas/api/v0/layout/node/2',
    times: 1,
    method: 'POST',
  }).as('updatePreview');
  cy.findByLabelText('Canvas Boolean Checkbox (default false)').as(
    'checkboxDefaultFalse',
  );
  cy.get('@checkboxDefaultFalse').should('have.attr', 'aria-checked', 'false');
  cy.get('@checkboxDefaultFalse').click();
  cy.get('@checkboxDefaultFalse').should('have.attr', 'aria-checked', 'true');
};

export const assertData = (response) => {
  expect(response.attributes.field_cvt_boolean_checkbox).to.eq(false);
  expect(response.attributes.field_cvt_boolean_checkbox2).to.eq(true);
};
