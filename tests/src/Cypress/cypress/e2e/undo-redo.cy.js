describe('Undo/Redo functionality', { testIsolation: false }, () => {
  before(() => {
    cy.drupalXbInstall();
  });

  after(() => {
    cy.drupalUninstall();
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  it('Performs a basic interaction with Undo/Redo', () => {
    cy.loadURLandWaitForXBLoaded();

    // Assert that the undo button is disabled initially.
    cy.get('button[aria-label="Undo"]').should('be.disabled');

    // Check there are three heroes initially.
    cy.testInIframe(
      '[data-component-id="experience_builder:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.equal(3);
      },
    );
    // Check that the menu is not open yet.
    cy.get('[data-radix-menubar-content]').should('have.length', 0);
    cy.getIframeBody()
      .find('[data-component-id="experience_builder:two_column"] .column-one')
      .first()
      .trigger('click');
    cy.findAllByLabelText('Add section')
      .first()
      .click({ scrollBehavior: 'center' });

    // Confirm that the menu opens the Section templates.
    cy.get('[data-radix-menubar-content]').should('have.length', 2);
    cy.get('[data-radix-menu-content].MenubarSubContent').should(
      'contain.text',
      'Section templates',
    );
    cy.intercept('POST', '**/api/preview/node/1').as('getPreview');

    cy.findByText('Default components').click();

    // Click on the menu item with data-xb-name="Hero" inside menu.
    cy.get('[data-radix-menu-content] [data-xb-name="Hero"]')
      .click()
      .then(() => {
        cy.wait('@getPreview');
      });

    cy.getIframeBody().find(
      '[data-component-id="experience_builder:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.equal(4);
      },
    );
    cy.get('button[aria-label="Undo"]')
      .click()
      .then(() => {
        cy.wait('@getPreview');
      });

    // Assert that the component was deleted from the layout.
    cy.getIframeBody().find(
      '[data-component-id="experience_builder:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.equal(3);
      },
    );

    // Click the Redo button.
    cy.get('button[aria-label="Redo"]')
      .click()
      .then(() => {
        cy.wait('@getPreview');
      });
    // Assert that the component was again added to the layout.
    cy.getIframeBody().find(
      '[data-component-id="experience_builder:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.equal(4);
      },
    );
  });

  it('Component props form values are included in Undo/Redo', () => {
    cy.loadURLandWaitForXBLoaded();

    // Click on our "hello, world!" hero component.
    cy.getIframeBody().findByText('hello, world!').click();

    // Add " one" to the heading field.
    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('Heading')
      .click()
      .type(' one')
      .wait(500); // Wait for debounce to finish to ensure undo history is updated.

    // Add " two" to the heading field.
    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('Heading')
      .click()
      .type(' two')
      .wait(500); // Wait for debounce to finish to ensure undo history is updated.

    // Click the Undo button, see if the value is "hello, world! one".
    cy.get('button[aria-label="Undo"]').click();
    cy.findByLabelText('Heading').should((input) => {
      expect(input).to.have.value('hello, world! one');
    });

    // Click the Redo button, see if the value is "hello, world! one two".
    cy.get('button[aria-label="Redo"]').click();

    cy.findByLabelText('Heading').should((input) => {
      expect(input).to.have.value('hello, world! one two');
    });

    // Click the Undo button twice, see if the value is "hello, world!".
    cy.get('button[aria-label="Undo"]').click().click();
    cy.findByLabelText('Heading').should((input) => {
      expect(input).to.have.value('hello, world!');
    });
  });
});
