describe('checkbox states', () => {
  before(() => {
    cy.drupalXbInstall(['xb_test_state_api']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').should('contain.text', 'Components');
    cy.get('.primaryPanelContent').findByText('Heading').click();
    cy.waitForElementContentInIframe('div', 'A heading element');
  });
  after(() => {
    cy.drupalUninstall();
  });

  it('checkbox (default unchecked) toggles the visibility of a text field', () => {
    const controller = 'Checkbox A: Toggle conditionally visible field';
    const target = 'Visible when Checkbox A is checked';
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.visible');
    cy.findByLabelText(controller).check();
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.visible');
    cy.findByLabelText(controller).uncheck();
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.visible');
  });

  it('checkbox (default unchecked) toggles the disabled state of a text field', () => {
    const controller = 'Checkbox B: Toggle conditionally enabled field';
    const target = 'Enabled when Checkbox B is checked';
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.enabled');
    cy.findByLabelText(controller).check();
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.enabled');
    cy.findByLabelText(controller).uncheck();
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.enabled');
  });

  it('checkbox (default unchecked) toggles the visibility of another checkbox', () => {
    const controller = 'Checkbox C: Toggle visibility of another checkbox';
    const target = 'Visible when Checkbox C is checked';
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.visible');
    cy.findByLabelText(controller).check();
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.visible');
    cy.findByLabelText(controller).uncheck();
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.visible');
  });

  it('checkbox (default unchecked) toggles the checked state of another checkbox', () => {
    const controller = 'Checkbox D: Toggle checked state of another checkbox';
    const target = 'Checked when Checkbox D is checked';
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.checked');
    cy.findByLabelText(controller).check();
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.checked');
    cy.findByLabelText(controller).uncheck();
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.checked');
  });

  // Default checked

  it('checkbox (default checked) toggles the visibility of a text field', () => {
    const controller = '[REV] Checkbox A: Toggle conditionally visible field';
    const target = '[REV] Visible when Checkbox A is checked';
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.visible');
    cy.findByLabelText(controller).uncheck();
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.visible');
    cy.findByLabelText(controller).check();
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.visible');
  });

  it('checkbox (default checked) toggles the disabled state of a text field', () => {
    const controller = '[REV] Checkbox B: Toggle conditionally enabled field';
    const target = '[REV] Enabled when Checkbox B is checked';
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.enabled');
    cy.findByLabelText(controller).uncheck();
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.enabled');
    cy.findByLabelText(controller).check();
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.enabled');
  });

  it('checkbox (default checked) toggles the visibility of another checkbox', () => {
    const controller =
      '[REV] Checkbox C: Toggle visibility of another checkbox';
    const target = '[REV] Visible when Checkbox C is checked';
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.visible');
    cy.findByLabelText(controller).uncheck();
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.visible');
    cy.findByLabelText(controller).check();
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.visible');
  });

  it('checkbox (default checked) toggles the checked state of another checkbox', () => {
    const controller =
      '[REV] Checkbox D: Toggle checked state of another checkbox';
    const target = '[REV] Checked when Checkbox D is checked';
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.checked');
    cy.findByLabelText(controller).uncheck();
    cy.findByLabelText(controller).should('not.be.checked');
    cy.findByLabelText(target).should('not.be.checked');
    cy.findByLabelText(controller).check();
    cy.findByLabelText(controller).should('be.checked');
    cy.findByLabelText(target).should('be.checked');
  });
});
