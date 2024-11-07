import { onlyVisibleChars } from '../support/utils.js';

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
      // Check that the layers menu is initially open
      cy.get('[data-testid="xb-primary-panel--layers"]').should(
        'have.attr',
        'data-state',
        'active',
      );
      cy.get('[data-xb-uuid="root"]').findByText('Hero').should('not.exist');
      cy.get('.primaryPanelContent').findByText('Two Column').click();
      cy.findByLabelText('Column Width').should('exist');
      cy.findAllByLabelText('Add section')
        .first()
        .click({ scrollBehavior: 'center' });

      // Check the active panel is the library panel.
      cy.get('[data-testid="xb-primary-panel--library"]').should(
        'have.attr',
        'data-state',
        'active',
      );
      cy.get('.primaryPanelContent').should('contain.text', 'Sections');
      // Click on Fake Section 2 inside menu.
      cy.get('.primaryPanelContent').findByText('Fake Section 2').click();
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
          const heroText1 = onlyVisibleChars(components[3].textContent);
          const heroText2 = onlyVisibleChars(components[4].textContent);
          expect(heroText1).to.equal('A hero in slot 1!');
          expect(heroText2).to.equal('A hero in slot 2!');
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
      // Check that the layers menu is initially open
      cy.get('[data-testid="xb-primary-panel--layers"]').should(
        'have.attr',
        'data-state',
        'active',
      );
      cy.clickComponentInPreview('Image');

      cy.findAllByLabelText('Add component')
        .first()
        .click({ scrollBehavior: 'center' });
      // Check the active panel is the library panel.
      cy.get('[data-testid="xb-primary-panel--library"]').should(
        'have.attr',
        'data-state',
        'active',
      );
      cy.get('.primaryPanelContent').should('contain.text', 'Components');
      // Click Hero
      cy.get('.primaryPanelContent').findByText('Hero').click();
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
