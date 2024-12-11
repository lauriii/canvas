import { onlyVisibleChars } from '../support/utils.js';

describe('Perform CRUD operations on components', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Created a node 1 with type article on install', () => {
    cy.drupalRelativeURL('node/1');
    cy.get('h1').should(($h1) => {
      expect($h1.text()).to.include('XB Needs This');
    });
    cy.get('[data-component-id="experience_builder:my-hero"] h1').should(
      ($h1) => {
        expect($h1.text()).to.include('hello, world!');
      },
    );
    cy.get(
      '[data-component-id="experience_builder:my-hero"] a[href="https://drupal.org"]',
    ).should.exist;
    cy.get(
      '[data-component-id="experience_builder:my-hero"] a[href="https://drupal.org"] ~ button',
    ).should.exist;
  });

  it('Can access XB UI and do basic interactions', () => {
    cy.loadURLandWaitForXBLoaded();

    // Confirm that some elements in the default layout are present in the
    // default iframe (lg).
    cy.testInIframe(
      '[data-component-id="experience_builder:my-hero"] h1',
      (h1s) => {
        expect(h1s.length).to.equal(3);
        h1s.forEach((h1, index) =>
          expect(h1.textContent).to.equal(
            index === 0 ? 'hello, world!' : 'XB Needs This For The Time Being',
          ),
        );
      },
    );

    // Do the same checks as above, but for the narrow layout preview.
    cy.testInIframe(
      '[data-component-id="experience_builder:my-hero"] h1',
      (h1s) => {
        expect(h1s.length).to.equal(3);
        h1s.forEach((h1, index) =>
          expect(h1.textContent).to.equal(
            index === 0 ? 'hello, world!' : 'XB Needs This For The Time Being',
          ),
        );
      },
      '[data-xb-preview="sm"][data-test-xb-content-initialized="true"][data-xb-swap-active="true"]',
    );

    // Confirm that the iframe loads the SDC CSS.
    cy.getIframe(
      '[data-xb-preview="lg"][data-test-xb-content-initialized="true"][data-xb-swap-active="true"]',
    )
      .its('head')
      .should('not.be.undefined')
      .then((head) => {
        expect(
          head.querySelector(
            'link[rel="stylesheet"][href*="components/my-hero/my-hero.css"]',
          ),
          `Tried to find [href*="components/my-hero/my-hero.css"] in <head> ${head.innerHTML}`,
        ).to.exist;
      });

    cy.openLibraryPanel();
    // Confirm the Library panel is open by checking if a component is visible.
    cy.get('.primaryPanelContent [data-state="open"]').contains('Components');
    cy.get('.primaryPanelContent').should('contain.text', 'Deprecated SDC');

    cy.get('.listContainer > div')
      .contains('Basic')
      .should(($basicListLabel) => {
        const $listed = $basicListLabel.parent().find('[data-xb-uuid]');
        const expectedNames = [
          'Deprecated SDC',
          'Experimental SDC',
          'Heading',
          'Image',
          'Hero',
          'Section',
          'One Column',
          'Shoe Badge',
          'Shoe Icon',
          'Shoe Tab',
          'Shoe Tab Group',
          'Shoe Tab Panel',
          'Two Column',
          'Video',
          'Teaser',
        ];
        $listed.each((index, listItem) => {
          expect($listed.get(index).textContent.trim()).to.equal(
            expectedNames[index],
          );
        });
      });

    // Confirm no component has a hover outline.
    cy.get('[data-xb-component-outline]').should('not.exist');

    let lgPreviewRect = {};
    // Enter the iframe to find an element in the preview iframe and hover over it.
    cy.getIframeBody()
      .find('[data-component-id="experience_builder:my-hero"] h1')
      .first()
      .then(($h1) => {
        cy.wrap($h1).trigger('mouseover');
        // While in the iframe, get the dimensions of the component so we can
        // compare the outline dimensions to it
        const $item = $h1.closest('.xb--sortable-item');
        lgPreviewRect = $item[0].getBoundingClientRect();
      });

    // After hovering, the component should be outlined for both small and large viewports.
    cy.getComponentInPreview('Hero')
      .should(($outline) => {
        expect($outline).to.exist;
        // Ensure the width is set before moving on to then().
        expect($outline[0].getBoundingClientRect().width).to.not.equal(0);
      })
      .then(($outline) => {
        // The outline width and height should be the same as the dimensions of
        // the corresponding component in the iframe.
        const outlineRect = $outline[0].getBoundingClientRect();
        expect(outlineRect.width).to.be.closeTo(lgPreviewRect.width, 0.1);
        expect(outlineRect.height).to.be.closeTo(lgPreviewRect.height, 0.1);
        expect($outline).to.have.css('position', 'absolute');
      });

    // Get the dimensions of the highlighted component in the small preview, so
    // it can be compared to its corresponding outline.
    let smPreviewRect = {};
    cy.getIframeBody(
      '[data-xb-preview="sm"][data-test-xb-content-initialized="true"][data-xb-swap-active="true"]',
    )
      .find('[data-component-id="experience_builder:my-hero"] h1')
      .first()
      .then((clicked) => {
        // While in the iframe, get the dimensions of the component so we can
        // compare the outline dimensions to it
        const item = clicked.closest('.xb--sortable-item');
        smPreviewRect = item[0].getBoundingClientRect();
      });

    // Get the small preview outline and confirm its dimensions match the
    // corresponding component,
    cy.getComponentInPreview('Hero', 0, 'sm')
      .should(($outline) => {
        expect($outline).to.exist;
        // Ensure the width is set before moving on to then().
        expect($outline[0].getBoundingClientRect().width).to.not.equal(0);
      })
      .then(($outline) => {
        // The outline width and height should be the same as the dimensions of
        // the corresponding component in the iframe.
        const outlineRect = $outline[0].getBoundingClientRect();
        expect(outlineRect.width).to.equal(smPreviewRect.width);
        expect(outlineRect.height).to.equal(smPreviewRect.height);
        expect($outline).to.have.css('position', 'absolute');
      });

    // Click the component to trigger the opening of the right drawer.
    cy.clickComponentInPreview('Hero');

    // The right panel has opened.
    cy.findByTestId('xb-contextual-panel').should('exist');

    const expectedLabels = [
      'Heading',
      'Sub-heading',
      'CTA 1 text',
      'CTA 1 link',
      'CTA 2 text',
    ];

    // The drawer contains a component edit form.
    cy.get(
      '[class*="contextualPanel"] [data-drupal-selector="component-props-form"]',
    ).then(($form) => {
      expect($form).to.exist;
      $form.find('label').each((index, label) => {
        expect(label.textContent).to.equal(expectedLabels[index]);
      });
    });

    cy.findByLabelText('Heading')
      .should('have.value', 'hello, world!')
      .invoke('attr', 'type')
      .should('eq', 'text');

    cy.findByLabelText('CTA 1 link').should('have.value', 'https://drupal.org');

    const heroSelectors = {
      Heading: '.my-hero__heading',
      'Sub-heading': 'h1 ~ p',
      'CTA 1 text': '.my-hero__cta:first-child',
      'CTA 2 text': '.my-hero__cta:last-child',
    };
    const heroBefore = {
      Heading: 'hello, world!',
      'Sub-heading': '',
      'CTA 1 text': '',
      'CTA 2 text': '',
    };

    // Confirm the current values of the first "My Hero" component so we can
    // be certain these values later change.
    cy.testInIframe(
      '[data-xb-component-id="experience_builder:my-hero"]',
      (heroes) => {
        const hero = heroes[0];
        Object.entries(heroSelectors).forEach(([prop, selector]) => {
          const heroText = onlyVisibleChars(
            hero.querySelector(selector).textContent,
          );
          if (heroBefore[prop]) {
            expect(heroText, `${prop} should be ${heroBefore[prop]}`).to.equal(
              heroBefore[prop],
            );
          } else {
            expect(heroText, `${prop} should be empty but it is "${heroText}"`)
              .to.be.empty;
          }
        });
        expect(
          hero.querySelector(heroSelectors['CTA 1 text']).getAttribute('href'),
        ).to.equal('https://drupal.org');
      },
    );

    const newValues = {
      Heading: 'You parked your car',
      'Sub-heading': 'Over the sidewalk',
      'CTA 1 text': 'ponytail',
      'CTA 2 text': 'stuck',
      'CTA 1 link': 'https://hoobastank.com',
    };

    // Monitor the endpoint that processes changed values in the prop edit form.
    cy.intercept('POST', '**/api/preview/node/1').as('getPreview');
    expectedLabels.forEach((label) => {
      // Type a new value into a given input.
      cy.findByLabelText(label).focus();
      cy.findByLabelText(label).clear();
      cy.findByLabelText(label).type(newValues[label]);
      // Wait for completion of the request triggered by our typing. This
      // ensures that the `testInIframe` ~10 lines down is working with an iframe that
      // has fully responded to these value changes.
      cy.wait('@getPreview');
      // Confirm React is properly handling form state by confirming the input
      // has the value we typed into it.
      cy.findByLabelText(label).should('have.value', newValues[label]);
    });

    // New values were typed into the prop form inputs, now enter the iframe
    // and confirm the component reflects these new values.
    cy.waitForElementContentInIframe(heroSelectors.Heading, newValues.Heading);
    cy.waitForElementContentInIframe(
      heroSelectors['Sub-heading'],
      newValues['Sub-heading'],
    );
    cy.waitForElementContentInIframe(
      heroSelectors['CTA 1 text'],
      newValues['CTA 1 text'],
    );
    cy.waitForElementContentInIframe(
      heroSelectors['CTA 2 text'],
      newValues['CTA 2 text'],
    );
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
    cy.get('[data-xb-uuid="content"]').findByText('Hero').should('not.exist');
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

    cy.log(
      'The newly added Two Column component from the section should be selected',
    );
    cy.openLayersPanel();
    cy.findByTestId('xb-primary-panel').within(() => {
      cy.findAllByText('Two Column').should('have.length', 2);
      cy.log(
        'The second (new) Two Column should be selected (check that it has a parent treeItem with the data-xb-selected attr)',
      );
      cy.findAllByText('Two Column')
        .eq(1)
        .parents('.treeItem')
        .should('have.attr', 'data-xb-selected', 'true');
    });
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
    cy.log('The newly added Hero component should be selected');
    cy.findAllByLabelText('Hero', { selector: '.componentOverlay' })
      .eq(0)
      .should('have.attr', 'data-xb-selected', 'true');
  });

  it('Can delete component with delete key', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();

    // Check there are three heroes initially.
    cy.testInIframe(
      '[data-xb-component-id="experience_builder:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.equal(3);
      },
    );

    // Select the component and ensure it's focused
    cy.clickComponentInPreview('Hero');

    cy.getIframeBody().realType('{del}');
    cy.previewReady();

    // Check there are two heroes after deleting
    cy.testInIframe(
      '[data-xb-component-id="experience_builder:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.equal(2);
      },
    );

    cy.getIframeBody()
      .find('[data-component-id="experience_builder:two_column"]')
      .should('have.length', 1);

    // Deleting from the content menu.
    cy.clickComponentInLayersView('Two Column');
    cy.realPress('{del}');

    cy.get('.primaryPanelContent')
      .findByLabelText('Two Column')
      .should('not.exist');
    cy.previewReady();
    cy.get(`#xbPreviewOverlay`)
      .findAllByLabelText('Two Column')
      .should('not.exist');
  });
});
