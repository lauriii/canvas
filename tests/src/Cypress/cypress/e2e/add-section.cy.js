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
      cy.drupalLogin('xbUser', 'xbUser');
    });

    it('Performs basic interaction with the Add section button', () => {
      cy.loadURLandWaitForXBLoaded();

      // Check there are three heroes initially.
      cy.testInIframe(
        '[data-component-id="experience_builder:my-hero"]',
        (myHeroComponent) => {
          expect(myHeroComponent.length).to.equal(3);
        },
      );
      cy.get('[data-xb-uuid="root"]').findByText('Hero').should('not.exist');
      // Check that the menu is not open yet.
      cy.get('#menuBarContainer').should('be.empty');
      cy.get('.primaryMenuContent').findByText('Two Column').click();
      cy.findByLabelText('Column Width').should('exist');
      cy.findAllByLabelText('Add section')
        .first()
        .click({ scrollBehavior: 'center' });
      // Confirm that the menu opens the Section templates.
      cy.get('#menuBarContainer').should('not.be.empty');
      cy.get('#menuBarSubmenuContainer').should(
        'contain.text',
        'Section templates',
      );

      // Click on Fake Section 2 inside menu.
      cy.get('#menuBarContainer').findByText('Section templates').click();
      cy.get('#menuBarSubmenuContainer').findByText('Fake Section 2').click();

      cy.waitForElementContentInIframe('div', 'A hero in slot 1!');

      cy.testInIframe(
        '[data-component-id="experience_builder:my-hero"]',
        (components) => {
          expect(components.length).to.equal(5);
        },
      );

      cy.testInIframe(
        '[data-component-id="experience_builder:my-hero"] h1',
        (components) => {
          expect(components[3].textContent.onlyVisibleChars()).to.equal(
            'A hero in slot 1!',
          );
          expect(components[4].textContent.onlyVisibleChars()).to.equal(
            'A hero in slot 2!',
          );
        },
      );
    });

    it('Can add component via the Add component button', () => {
      cy.loadURLandWaitForXBLoaded();

      // Check there are three heroes initially.
      cy.testInIframe(
        '[data-component-id="experience_builder:my-hero"]',
        (myHeroComponent) => {
          expect(myHeroComponent.length).to.equal(3);
        },
      );
      // Check that the menu is not open yet.
      cy.get('#menuBarContainer').should('be.empty');
      cy.getIframeBody()
        .find('[data-xb-component-id="experience_builder:image"]')
        .first()
        .trigger('click');
      cy.findAllByLabelText('Add component')
        .first()
        .click({ scrollBehavior: 'center' });
      // Confirm that the menu opens the Section templates.
      cy.get('#menuBarContainer').should('not.be.empty');
      cy.get('#menuBarSubmenuContainer').should('contain.text', 'Components');

      // Click Hero in the side menu
      cy.get('#menuBarSubmenuContainer').findByText('Hero').click();

      cy.waitForElementContentInIframe('div', 'There goes my hero');

      cy.testInIframe(
        '[data-component-id="experience_builder:my-hero"]',
        (myHeroComponent) => {
          expect(myHeroComponent.length).to.equal(4);
        },
      );
    });
  },
);
