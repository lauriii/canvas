describe(
  'Add section/component functionality',
  { testIsolation: false },
  () => {
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

    it('Performs basic interaction with the Add section button', () => {
      cy.drupalRelativeURL('xb/node/1');
      // Wait for the preview iframe to load and render something that confirms it is ready.
      cy.get('iframe[data-xb-preview="lg"]').should(
        'have.attr',
        'data-test-xb-content-initialized',
        'true',
      );
      cy.waitForElementInIframe('[data-xb-type="experience_builder:image"]');

      // Check there are three heroes initially.
      cy.testInIframe(
        '[data-component-id="experience_builder:my-hero"]',
        (myHeroComponent) => {
          expect(myHeroComponent.length).to.equal(3);
        },
      );
      // Check that the menu is not open yet.
      cy.get('[data-radix-menubar-content]').should('have.length', 0);
      cy.scrollToMiddleOfIframe();
      cy.getIframeBody()
        .find('[data-component-id="experience_builder:two_column"] .column-one')
        .first()
        .trigger('click');
      cy.get('button[aria-label="Add section"]').then((button) => {
        button.click();
      });
      // Confirm that the menu opens the Section templates.
      cy.get('[data-radix-menubar-content]').should('have.length', 2);
      cy.get('[data-radix-menu-content].MenubarSubContent').should(
        'contain.text',
        'Section templates placeholder',
      );
      cy.intercept('POST', '**/api/preview/node/1').as('getPreview');

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
    });

    // @todo: Add a test for the "Add component" button when we have child nodes to test in the frontend.
    //   https://www.drupal.org/project/experience_builder/issues/3463300
  },
);
