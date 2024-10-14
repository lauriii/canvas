describe('General Experience Builder', { testIsolation: false }, () => {
  before(() => {
    cy.drupalXbInstall();
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Created a node 1 with type article on install', () => {
    cy.drupalSession();
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
      '[data-component-id="experience_builder:my-hero"] button[formaction="https://drupal.org"]',
    ).should.exist;
    cy.get(
      '[data-component-id="experience_builder:my-hero"] button[formaction="https://drupal.org"] ~ button',
    ).should.exist;
  });

  it('Can access XB UI and do basic interactions', () => {
    cy.drupalLogin('xbUser', 'xbUser');
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

    cy.get('#menuBarContainer').should('be.empty');
    cy.clickAddMenu();
    cy.get('#menuBarContainer').should('not.be.empty');
    cy.get('[role="menuitem"][aria-expanded="true"]').contains(
      'Default components',
    );

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
      .trigger('mouseover')
      .then((clicked) => {
        // While in the iframe, get the dimensions of the component so we can
        // compare the outline dimensions to it
        const item = clicked.closest('.xb--sortable-item');
        lgPreviewRect = item[0].getBoundingClientRect();
      });

    // After hovering, the component should be outlined for both small and large viewports.
    cy.get('[data-xb-component-outline]')
      .should(($outline) => {
        expect($outline).to.exist;
        // Ensure the width is set before moving on to then().
        expect($outline[0].getBoundingClientRect().width).to.not.equal(0);
      })
      .then(($outline) => {
        // The outline width and height should be the same as the dimensions of
        // the corresponding component in the iframe.
        const outlineRect = $outline[0].getBoundingClientRect();
        expect(outlineRect.width).to.equal(lgPreviewRect.width);
        expect(outlineRect.height).to.equal(lgPreviewRect.height);
        expect($outline).to.have.css('position', 'absolute');
        expect($outline).to.have.css('top', '0px');
        expect($outline).to.have.css('left', '0px');
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
    cy.get('[data-xb-preview="sm"] ~ div > [data-xb-component-outline]')
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
        expect($outline).to.have.css('top', '0px');
        expect($outline).to.have.css('left', '0px');
      });

    // Click the component to trigger the opening of the right drawer.
    cy.getIframeBody()
      .find('[data-component-id="experience_builder:my-hero"] h1')
      .first()
      .trigger('click');

    // The right panel has opened.
    cy.findByTestId('xb-contextual-panel').should('exist');

    // The drawer contains a component edit form.
    cy.get(
      '[class*="contextualPanel"] [data-drupal-selector="component-props-form"].component-props-form',
    ).then(($form) => {
      expect($form).to.exist;
      const expectedLabels = [
        'Heading',
        'Sub-heading',
        'CTA 1 text',
        'CTA 1 link',
        'CTA 2 text',
      ];
      $form.find('label').each((index, label) => {
        expect(label.textContent).to.equal(expectedLabels[index]);
      });
    });

    cy.get(
      '[data-drupal-selector="edit-xb-component-props-static-static-card1ab-heading-0-value"]',
    )
      .should('have.value', 'hello, world!')
      .invoke('attr', 'type')
      .should('eq', 'text');

    cy.get(
      '[data-drupal-selector="edit-xb-component-props-static-static-card1ab-cta1href-0-value"]',
    )
      .should('have.value', 'https://drupal.org')
      .invoke('attr', 'type')
      .should('eq', 'url');

    const heroSelectors = {
      heading: 'h1',
      subheading: 'h1 ~ p',
      cta1: 'button:first-child',
      cta2: 'button:last-child',
    };
    const heroBefore = {
      heading: 'hello, world!',
      subheading: '',
      cta1: '',
      cta2: '',
    };

    // Confirm the current values of the first "My Hero" component so we can
    // be certain these values later change.
    cy.testInIframe(
      '[data-xb-component-id="experience_builder:my-hero"]',
      (heroes) => {
        const hero = heroes[0];
        Object.entries(heroSelectors).forEach(([prop, selector]) => {
          if (heroBefore[prop]) {
            expect(
              hero.querySelector(selector).textContent.onlyVisibleChars(),
              `${prop} should be ${heroBefore[prop]}`,
            ).to.equal(heroBefore[prop]);
          } else {
            expect(
              !!hero.querySelector(selector).textContent.onlyVisibleChars(),
              `${prop} should be empty`,
            ).to.be.false;
          }
        });
        expect(
          hero.querySelector(heroSelectors.cta1).getAttribute('formaction'),
        ).to.equal('https://drupal.org');
      },
    );

    const propEditFormSelectors = {
      heading:
        '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-heading-0-value"]',
      subheading:
        '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-subheading-0-value"]',
      cta1href:
        '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-cta1href-0-value"]',
      cta1: '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-cta1-0-value"]',
      cta2: '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-cta2-0-value"]',
    };
    const newValues = {
      heading: 'You parked your car',
      subheading: 'Over the sidewalk',
      cta1: 'ponytail',
      cta2: 'stuck',
      cta1href: 'https://hoobastank.com',
    };

    // Monitor the endpoint that processes changed values in the prop edit form.
    cy.intercept('POST', '**/api/preview/node/1').as('getPreview');
    Object.entries(propEditFormSelectors).forEach(([prop, selector]) => {
      // Type a new value into a given input.
      cy.get(selector).focus().clear().type(newValues[prop]);

      // Wait for completion of the request triggered by our typing. This
      // ensures that the `testInIframe` ~10 lines down is working with an iframe that
      // has fully responded to these value changes.
      cy.wait('@getPreview');
      // Confirm React is properly handling form state by confirming the input
      // has the value we typed into it.
      cy.get(selector).should('have.value', newValues[prop]);
    });

    // New values were typed into the prop form inputs, now enter the iframe
    // and confirm the component reflects these new values.
    cy.waitForElementContentInIframe(heroSelectors.heading, newValues.heading);
    cy.waitForElementContentInIframe(
      heroSelectors.subheading,
      newValues.subheading,
    );
    cy.waitForElementContentInIframe(heroSelectors.cta1, newValues.cta1);
    cy.waitForElementContentInIframe(heroSelectors.cta2, newValues.cta2);
  });

  it('previews components on hover', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();

    cy.get('#menuBarContainer').should('be.empty');
    cy.clickAddMenu();
    cy.get('#menuBarContainer').should('not.be.empty');
    cy.get('[role="menuitem"][aria-expanded="true"]').contains(
      'Default components',
    );

    const imageSelect =
      '.MenubarSubContent [data-xb-component-id="sdc+experience_builder+image"]';
    const heroSelect =
      '.MenubarSubContent [data-xb-component-id="sdc+experience_builder+my-hero"]';

    // Hover over "Image" and a preview should appear.
    cy.get(`${imageSelect} > span`).should('exist').realHover();
    cy.waitForElementInIframe(
      'img[alt="Boring placeholder"]',
      'iframe[data-preview-component-id="sdc+experience_builder+image"]',
    );

    // Hover over "My Hero" and a preview should appear and load correct CSS
    cy.get(`${heroSelect} > span`).should('exist').realHover();
    cy.waitForElementInIframe(
      'div.my-hero__container > .my-hero__actions > .my-hero__cta--primary',
      'iframe[data-preview-component-id="sdc+experience_builder+my-hero"]',
    );
    cy.getIframeBody(
      'iframe[data-preview-component-id="sdc+experience_builder+my-hero"]',
    )
      .find(
        'div.my-hero__container > .my-hero__actions > .my-hero__cta--primary',
      )
      .should('exist')
      .should(($cta) => {
        // Retry until the background-color is the expected one
        const bgColor = window.getComputedStyle($cta[0])['background-color'];
        expect(bgColor, 'The "My Hero" SDC is styled').to.equal(
          'rgb(0, 123, 255)',
        );
      });
  });

  it('Opens contextual panel on component selection with correct routing', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();

    // Find and alias the UUID of the "my-hero" component.
    cy.getIframeBody()
      .find('[data-xb-component-id="experience_builder:my-hero"]')
      .should('have.length', 3)
      .last()
      .invoke('attr', 'data-xb-uuid')
      .as('cid1');
    // Find and alias the UUID of the "image" component.
    cy.getIframeBody()
      .find('[data-xb-component-id="experience_builder:image"]')
      .should('have.length', 2)
      .last()
      .invoke('attr', 'data-xb-uuid')
      .as('cid2');

    // Ensure both aliases are retrieved and compare them.
    cy.get('@cid1').then((uuid1) => {
      cy.get('@cid2').then((uuid2) => {
        expect(uuid2).to.not.equal(uuid1);
      });
    });

    // Click component 1.
    cy.get('@cid1').then((cid1) => {
      cy.getIframeBody()
        .find(`[data-xb-component-id="experience_builder:my-hero"]`)
        .then((heroes) => {
          heroes.filter(`[data-xb-uuid="${cid1}"]`).find('h1').trigger('click');
          // Make sure the contextual panel opens for the clicked component.
          cy.findByTestId(`xb-contextual-panel-${cid1}`).should('exist');
          // Make sure the component form is rendered for the clicked component.
          cy.findByTestId(`xb-component-form-${cid1}`).should('exist');
          // Now on a path specific to that component.
          cy.url().should((url) => {
            expect(
              url,
              `After clicking on ${cid1}, path should include '/xb/node/1/component/${cid1}'`,
            ).to.contain(`/xb/node/1/component/${cid1}`);
          });
        });
    });

    // Click component 2.
    cy.get('@cid2').then((cid2) => {
      cy.getIframeBody()
        .find(`[data-xb-component-id="experience_builder:image"]`)
        .then((images) => {
          images
            .filter(`[data-xb-uuid="${cid2}"]`)
            .find('img')
            .trigger('click');
          // Make sure the contextual panel opens for the clicked component.
          cy.findByTestId(`xb-contextual-panel-${cid2}`).should('exist');
          // Make sure the component form is rendered for the clicked component.
          cy.findByTestId(`xb-component-form-${cid2}`).should('exist');
          // Now on a path specific to that component.
          cy.url().should((url) => {
            expect(
              url,
              `After clicking on ${cid2}, path should include '/xb/node/1/component/${cid2}'`,
            ).to.contain(`/xb/node/1/component/${cid2}`);
          });
        });
    });

    cy.go('back');

    cy.get('@cid1').then((cid1) => {
      // Returns to the URL for the prior component.
      cy.url().should((url) => {
        expect(
          url,
          `Hit back once and path should again include '/xb/node/1/component/${cid1}'`,
        ).to.contain(`/xb/node/1/component/${cid1}`);
      });
      // Returns to the contextual form for the prior component.
      cy.findByTestId(`xb-contextual-panel-${cid1}`).should('exist');
    });

    cy.go('back');

    cy.url().should((url) => {
      expect(
        url,
        `Hit back twice and the and path should not have 'component' in it`,
      ).to.not.contain('/xb/node/1/component');
      expect(
        url,
        `Hit back twice and the path should still have /xb`,
      ).to.contain('/xb/node/1');
    });
  });

  it('Visits a router URL directly', () => {
    cy.drupalLogin('xbUser', 'xbUser');

    // Ideally the UUID would get its value dynamically, but that value can
    // only be accessed reliably in a command callback, and visiting a url
    // can't happen in that scope.
    const uuid = 'dynamic-dynamic-card3rr';
    cy.intercept('GET', '**/api/layout/node/1').as('getLayout');
    cy.intercept('POST', '**/api/preview/node/1').as('getPreview');
    cy.intercept('GET', '**/xb-field-form/node/1?**').as('getPropsForm');
    cy.drupalRelativeURL(`xb/node/1/component/${uuid}`);

    cy.wait('@getLayout');
    cy.wait('@getPreview');
    cy.wait('@getPropsForm');
    cy.findByTestId(`xb-contextual-panel-${uuid}`).should('exist');
    cy.url().should('contain', `/xb/node/1/component/${uuid}`);
  });

  it('Handles and resets errors', () => {
    cy.drupalLogin('xbUser', 'xbUser');

    // Intercept the request to the preview endpoint and return a 418 status
    // code. This will cause the error boundary to display an error message.
    // Note the times: 1 option, which ensures the request is only intercepted
    // once.
    cy.intercept(
      { url: '**/api/preview/node/1', times: 1 },
      { statusCode: 418 },
    );
    cy.drupalRelativeURL('xb/node/1');

    cy.findByTestId('xb-error-alert')
      .should('exist')
      .invoke('text')
      .should('include', 'An unexpected error has occurred');

    // Click the reset button to clear the error, and confirm the error message
    // is no longer present.
    cy.findByTestId('xb-error-reset').click();
    cy.contains('An unexpected error has occurred').should('not.exist');
  });

  it('has the expected performance', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.intercept('POST', '**/api/preview/node/1').as('getPreview');

    cy.visit('/xb/node/1');
    cy.wait('@getPreview').its('response.statusCode').should('eq', 200);

    // Assert that only one request was sent
    cy.get('@getPreview.all').should('have.length', 1);
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
    cy.getIframeBody()
      .find(`[data-xb-component-id="experience_builder:my-hero"]`)
      .first()
      .click();

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
      .find(`[data-xb-component-id="experience_builder:my-hero"]`)
      .first()
      .click();

    cy.get('[data-xb-uuid="root"]').click();
    cy.realPress('{del}');
    cy.previewReady();
    cy.getIframeBody()
      .find('[data-component-id="experience_builder:two_column"] .column-one')
      .should('have.length', 1);

    // Deleting from the content menu.
    cy.get('[data-xb-uuid="root"]').findByText('Two Column').click();
    cy.realPress('{del}');
    cy.previewReady();

    cy.get('[data-xb-uuid="root"]')
      .findByText('Two Column')
      .should('not.exist');
  });

  it('Insert panel should close when clicking off', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();

    cy.get('#menuBarContainer').should('be.empty');
    cy.clickAddMenu();
    cy.get('#menuBarContainer').should('not.be.empty');
    cy.get('#menuBarSubmenuContainer').should('not.be.empty');
    cy.clickAddMenu();
    cy.get('#menuBarContainer').should('be.empty');
    cy.get('#menuBarSubmenuContainer').should('be.empty');

    cy.log('Should close insert panel when clicking outside');
    cy.clickAddMenu();
    cy.get('#menuBarContainer').should('not.be.empty');
    cy.get('#menuBarSubmenuContainer').should('not.be.empty');
    cy.get('body').click('topRight');
    cy.get('#menuBarContainer').should('be.empty');
    cy.get('#menuBarSubmenuContainer').should('be.empty');

    cy.log('Should close insert panel when clicking inside iframe');
    cy.clickAddMenu();
    cy.get('#menuBarContainer').should('not.be.empty');
    cy.get('#menuBarSubmenuContainer').should('not.be.empty');
    cy.getIframeBody().findByText('hello, world!').click();
    cy.get('#menuBarContainer').should('be.empty');
    cy.get('#menuBarSubmenuContainer').should('be.empty');
  });

  it('Should ensure the component insert panel is scrollable', () => {
    cy.drupalLogin('xbUser', 'xbUser');

    // Stub the HTTP request to return many components to make scrolling necessary
    cy.intercept('GET', '**/xb-components', {
      statusCode: 200,
      body: Array(50)
        .fill()
        .reduce((acc, _, index) => {
          const paddedIndex = String(index + 1).padStart(2, '0');
          const id = `experience_builder:component_${paddedIndex}`;
          acc[id] = { id, name: `Component ${paddedIndex}` };
          return acc;
        }, {}),
    }).as('getComponents');

    cy.loadURLandWaitForXBLoaded();

    cy.clickAddMenu();
    cy.get('#menuBarContainer').should('not.be.empty');
    cy.get('#menuBarSubmenuContainer').should('not.be.empty');
    cy.wait('@getComponents');

    cy.get('#menuBarSubmenuContainer .MenubarSubContent')
      .realMouseWheel({ deltaY: 2000 })
      .then(() => {
        cy.get(
          '[data-xb-component-id="experience_builder:component_50"]',
        ).should('be.visible');
      });
  });

  it('Handles empty values in required inputs', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();

    // Make note of the number of Hero components currently in the preview.
    cy.getIframeBody()
      .find('[data-xb-component-id="experience_builder:my-hero"]')
      .its('length')
      .then((count) => {
        cy.wrap(count).as('initialHeroCount');
      });

    // Click a Hero component to open the component form.
    cy.getIframeBody().findByText('hello, world!').click();

    // `@componentFormHeading`
    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('Heading')
      .as('componentFormHeading');

    // Check if the "Heading" prop's <input> tag has the `required` attribute.
    cy.get('@componentFormHeading').should('have.attr', 'required');
    // Clear the value.
    cy.get('@componentFormHeading')
      .clear()
      // Ensure the input is invalid.
      .then(($input) => {
        expect($input[0].validity.valid).to.be.false;
        expect($input[0].matches(':invalid')).to.be.true;
      });

    // Make sure the umber of Hero components in the preview hasn't changed.
    cy.get('@initialHeroCount').then((initialHeroCount) => {
      cy.getIframeBody()
        .find('[data-xb-component-id="experience_builder:my-hero"]')
        .its('length')
        .should('eq', initialHeroCount);
    });

    // Update the value of the "Heading" prop's <input>.
    cy.get('@componentFormHeading').type('New heading text');
    // Ensure the new value shows in the preview.
    cy.waitForElementContentInIframe(
      'div[data-xb-component-id="experience_builder:my-hero"] h1',
      'New heading text',
    );

    // `@componentFormCTA1Link`
    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('CTA 1 link')
      .as('componentFormCTA1Link');
    // `@componentFormCTA1Text`
    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('CTA 1 text')
      .as('componentFormCTA1Text');

    // Check if the "CTA 1 link" prop's <input> tag has the `required` attribute.
    cy.get('@componentFormCTA1Link').should('have.attr', 'required');
    // Clear the value.
    cy.get('@componentFormHeading')
      .clear()
      // Ensure the input is invalid.
      .then(($input) => {
        expect($input[0].validity.valid).to.be.false;
        expect($input[0].matches(':invalid')).to.be.true;
      });

    // Make sure the number of Hero components in the preview hasn't changed.
    cy.get('@initialHeroCount').then((initialHeroCount) => {
      cy.getIframeBody()
        .find('[data-xb-component-id="experience_builder:my-hero"]')
        .its('length')
        .should('eq', initialHeroCount);
    });

    // Update the value of the "CTA 1 link" prop's <input>.
    cy.get('@componentFormCTA1Link')
      .clear()
      // Ensure the input is invalid.
      .then(($input) => {
        expect($input[0].validity.valid).to.be.false;
        expect($input[0].matches(':invalid')).to.be.true;
      })
      .type('https://www.example.com/');
    // Also update the value of the "CTA 1 text" prop's <input>.
    cy.get('@componentFormCTA1Text').type('Example link');
    // Ensure the new value shows in the preview.
    cy.waitForElementContentInIframe(
      'div[data-xb-component-id="experience_builder:my-hero"] button',
      'Example link',
    );
    cy.getIframeBody()
      .findByText('Example link')
      .should('have.attr', 'formaction', 'https://www.example.com/');

    // Make sure the number of Hero components in the preview hasn't changed.
    cy.get('@initialHeroCount').then((initialHeroCount) => {
      cy.getIframeBody()
        .find('[data-xb-component-id="experience_builder:my-hero"]')
        .its('length')
        .should('eq', initialHeroCount);
    });

    // Ensure enum/select required field does not have None option
    // Click on the first image component
    cy.getIframeBody()
      .find('[data-xb-component-id="experience_builder:image"]')
      .first()
      .click();
    // Click on the Add component button of image component
    cy.findAllByLabelText('Add component')
      .first()
      .click({ scrollBehavior: 'center' });
    // Click Heading in the side menu
    cy.get('#menuBarSubmenuContainer').findByText('Heading').click();
    // Check if heading component has been added in the preview
    cy.waitForElementContentInIframe(
      'div[data-xb-component-id="experience_builder:heading"] h1[data-component-id="experience_builder:heading"]',
      'A heading element',
    );
    // Find added Heading component above and click on it
    cy.getIframeBody().findByText('A heading element').click();

    // Find the Element enum/select component and check for None option - it should not be there
    cy.findByTestId(/^xb-component-form-.*/)
      .find('select[required]')
      .should('be.visible')
      .find('option')
      .should('not.contain', '- None -');

    // Hitting enter within a field should not submit the form or alter that
    // prop within the layout.
    cy.findByTestId(/^xb-component-form-.*/)
      .find('input[required]')
      .click()
      .type('{enter}');
    cy.getIframeBody().findByText('A heading element').should('exist');
  });
});
