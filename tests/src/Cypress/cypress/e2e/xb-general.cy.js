
describe('General Experience Builder', {testIsolation: false}, () => {
  before( () => {
    cy.drupalXbInstall()
  });

  after(() => {
    cy.drupalUninstall()
  })

  beforeEach(() => {
    cy.drupalSession();
    //A larger viewport makes it easier to debug in the test runner app.
    cy.viewport(2000, 1000);
  });

  it ('Created a node 1 with type article on install', () => {
    cy.drupalRelativeURL('node/1');
    cy.get('h1').should(($h1) => {
      expect($h1.text()).to.include('XB Needs This')
    })
    cy.get('[data-component-id="experience_builder:my-hero"] h1').should(($h1) => {
      expect($h1.text()).to.include('hello, world!')
    })
    cy.get('[data-component-id="experience_builder:my-hero"] button[formaction="https://drupal.org"]').should.exist
    cy.get('[data-component-id="experience_builder:my-hero"] button[formaction="https://drupal.org"] ~ button').should.exist
  })

  it('Can access XB UI and do basic interactions', () => {
    cy.drupalLogin('xbUser', 'xbUser')
    cy.drupalRelativeURL('xb')

    // Wait for the preview iframe to load and render something that confirms
    // it is ready.
    cy.get('iframe[data-xb-preview]').should('exist')
    cy.waitForElementInIframe('[data-xb-type="experience_builder:image"]')

    // Confirm that some elements in the default layout are present in the
    // default iframe (lg).
    cy.testInIframe('[data-component-id="experience_builder:my-hero"] h1', (h1s) => {
      expect(h1s.length).to.equal(3)
      h1s.forEach((h1, index) => expect(h1.textContent).to.equal(
        (index === 0 ? 'hello, world!' : 'XB Needs This For The Time Being')
      ))
    })

    // Do the same checks as above, but for the narrow layout preview.
    cy.testInIframe('[data-component-id="experience_builder:my-hero"] h1', (h1s) => {
      expect(h1s.length).to.equal(3)
      h1s.forEach((h1, index) => expect(h1.textContent).to.equal(
        (index === 0 ? 'hello, world!' : 'XB Needs This For The Time Being')
      ))
    }, '[data-xb-preview="sm"]')

    // Confirm that the iframe loads the SDC CSS.
    cy.getIframe()
      .its('head').should('not.be.undefined')
      .then((head) => {
        expect(head.querySelector('link[rel="stylesheet"][href*="experience_builder/components/my-hero/my-hero.css"]'))
          .to.exist
      })

    cy.get('[data-radix-menubar-content]').should('have.length', 0, 'There is no menubar yet.')
    cy.get('[data-hover-overlay="addElement"]').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 1, 'Menubar is present after clicking `[data-radix-menubar-content]`')
    cy.get('[role="menuitem"][aria-expanded="false"]').contains('Default components').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 2)

    cy.get('.listContainer > div').contains('Basic').should(($basicListLabel) => {
      const $listed = $basicListLabel.parent().find('[data-xb-uuid]');
      expect($listed).to.have.length(2)
      const expectedNames = ['Image', 'Hero']
      $listed.each((index, listItem) => {
        expect($listed.get(index).textContent.trim()).to.equal(expectedNames[index])
      })
    })

    // Before interacting with components in the layout, confirm there is
    // currently no right drawer.
    cy.get('[class*="contextualPanel"]').should('not.exist')

    // Confirm no component has a hover outline.
    cy.get('[data-xb-component-outline]').should('not.exist')

    let lgPreviewRect = {}
    // Enter the iframe to find an element in the preview iframe and hover over it.
    cy.getIframeBody().find('[data-component-id="experience_builder:my-hero"] h1')
      .first()
      .trigger('mouseover')
      .then(clicked => {
        // While in the iframe, get the dimensions of the component so we can
        // compare the outline dimensions to it
        const item = clicked.closest('.sortable-item')
        lgPreviewRect = item[0].getBoundingClientRect();
      });

    // After hovering, the component should be outlined for both small and large viewports.
    cy.get('[data-xb-component-outline]')
      .should(($outline) => {
        expect($outline).to.exist;
        // Ensure the width is set before moving on to then().
        expect($outline[0].getBoundingClientRect().width).to.not.equal(0)
      })
      .then($outline => {
        // The outline width and height should be the same as the dimensions of
        // the corresponding component in the iframe.
        const outlineRect = $outline[0].getBoundingClientRect();
        expect(outlineRect.width).to.equal(lgPreviewRect.width)
        expect(outlineRect.height).to.equal(lgPreviewRect.height)
        expect($outline).to.have.css('position', 'absolute')
        expect($outline).to.have.css('top', '0px')
        expect($outline).to.have.css('left', '0px')
      });

    // Get the dimensions of the highlighted component in the small preview, so
    // it can be compared to its corresponding outline.
    let smPreviewRect = {}
    cy.getIframeBody('[data-xb-preview="sm"]').find('[data-component-id="experience_builder:my-hero"] h1')
      .first()
      .then(clicked => {
        // While in the iframe, get the dimensions of the component so we can
        // compare the outline dimensions to it
        const item = clicked.closest('.sortable-item')
        smPreviewRect = item[0].getBoundingClientRect();
      });

    // Get the small preview outline and confirm its dimensions match the
    // corresponding component,
    cy.get('[data-xb-preview="sm"] ~ [data-xb-component-outline]')
      .should(($outline) => {
        expect($outline).to.exist;
        // Ensure the width is set before moving on to then().
        expect($outline[0].getBoundingClientRect().width).to.not.equal(0)
      })
      .then($outline => {
        // The outline width and height should be the same as the dimensions of
        // the corresponding component in the iframe.
        const outlineRect = $outline[0].getBoundingClientRect();
        expect(outlineRect.width).to.equal(smPreviewRect.width)
        expect(outlineRect.height).to.equal(smPreviewRect.height)
        expect($outline).to.have.css('position', 'absolute')
        expect($outline).to.have.css('top', '0px')
        expect($outline).to.have.css('left', '0px')
      });

    // Click the component to trigger the opening of the right drawer.
    cy.getIframeBody().find('[data-component-id="experience_builder:my-hero"] h1')
      .first()
      .trigger('click')

    // The right panel has opened.
    cy.get('[class*="contextualPanel"]').should('exist')

    // The drawer contains a component edit form.
    cy.get('[class*="contextualPanel"] [data-drupal-selector="component-props-form"].component-props-form').then(($form) => {
      expect($form).to.exist
      const expectedLabels = ['heading', 'subheading', 'cta1', 'cta1href', 'cta2'];
      $form.find('label').each((index, label) => {
        expect(label.textContent).to.equal(expectedLabels[index])
      })
    })

    cy.get('[data-drupal-selector="edit-xb-component-props-static-static-card1ab-heading-0-value"]')
      .should('have.value', 'hello, world!')
      .invoke('attr', 'type')
      .should('eq', 'text')

    cy.get('[data-drupal-selector="edit-xb-component-props-static-static-card1ab-cta1href-0-value"]')
      .should('have.value', 'https://drupal.org')
      .invoke('attr', 'type')
      .should('eq', 'url')


    const heroSelectors = {
      heading: 'h1',
      subheading: 'h1 ~ p',
      cta1: 'button:first-child',
      cta2: 'button:last-child',
    }
    const heroBefore = {
      heading: 'hello, world!',
      subheading: '',
      cta1: '',
      cta2: '',
    }

    // Confirm the current values of the first "My Hero" component so we can
    // be certain these values later change.
    cy.testInIframe('[data-xb-type="experience_builder:my-hero"]', (heroes) => {
      const hero = heroes[0];
      Object.entries(heroSelectors).forEach(([ prop, selector ]) => {
        if(heroBefore[prop]) {
          expect(hero.querySelector(selector).textContent.onlyVisibleChars()
            , `${prop} should be ${heroBefore[prop]}`).to.equal(heroBefore[prop])
        } else {
          expect(!!hero.querySelector(selector).textContent.onlyVisibleChars()
            ,  `${prop} should be empty`).to.be.false
        }
      })
      expect(hero.querySelector(heroSelectors.cta1).getAttribute('formaction')).to.equal('https://drupal.org')
    })

    const propEditFormSelectors  = {
      heading: '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-heading-0-value"]',
      subheading: '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-subheading-0-value"]',
      cta1href: '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-cta1href-0-value"]',
      cta1: '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-cta1-0-value"]',
      cta2: '[data-drupal-selector="component-props-form"] [data-drupal-selector="edit-xb-component-props-static-static-card1ab-cta2-0-value"]',
    }
    const newValues = {
      heading: 'You parked your car',
      subheading: 'Over the sidewalk',
      cta1: 'ponytail',
      cta2: 'stuck',
      cta1href: 'https://hoobastank.com'
    }

    // Monitor the endpoint that processes changed values in the prop edit form.
    cy.intercept('POST', '**/api/preview').as('getPreview')
    Object.entries(propEditFormSelectors).forEach(([ prop, selector ]) => {
      // Type a new value into a given input.
      cy.get(selector).focus().clear().type(newValues[prop])

      // Wait for completion of the request triggered by our typing. This
      // ensures that the `testInIframe` ~10 lines down is working with an iframe that
      // has fully responded to these value changes.
      cy.wait('@getPreview')
      // Confirm React is properly handling form state by confirming the input
      // has the value we typed into it.
      cy.get(selector).should('have.value', newValues[prop])
    })

    // Close the right drawer, so it doesn't cover the iFrame content when Cypress is looking
    // for elements.
    cy.get('[class*="contextualPanel"] button[aria-label="Close"]').click();

    // New values were typed into the prop form inputs, now enter the iframe
    // and confirm the component reflects these new values.
    cy.testInIframe('[data-xb-type="experience_builder:my-hero"]', (heroes) => {
      const hero = heroes[0];
      Object.entries(heroSelectors).forEach(([ prop, selector ]) => {
        expect(
          hero.querySelector(selector).textContent.onlyVisibleChars(),
          `${prop} (${selector}) should be '${newValues[prop]}'`)
          .to.equal(newValues[prop])
      })
      // Special check for ctaHref as it is an attribute value.
      expect(hero.querySelector(heroSelectors.cta1).getAttribute('formaction')).to.equal(newValues.cta1href)
    })
  })

  it('previews components on hover', () => {
    cy.drupalLogin('xbUser', 'xbUser')
    cy.drupalRelativeURL('xb')
    cy.get('iframe[data-xb-preview]').should('exist')
    cy.waitForElementInIframe('[data-xb-type="experience_builder:image"]')
    cy.get('[data-radix-menubar-content]').should('have.length', 0, 'There is no menubar yet.')
    cy.get('[data-hover-overlay="addElement"]').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 1, 'Menubar is present after clicking `[data-radix-menubar-content]`')
    cy.get('[role="menuitem"][aria-expanded="false"]').contains('Default components').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 2)


    const previewSelect = `[data-radix-popper-content-wrapper] > .ComponentPreviewContent`
    const imageSelect = '.MenubarSubContent [data-xb-uuid="experience_builder:image"]'
    const heroSelect = '.MenubarSubContent [data-xb-uuid="experience_builder:my-hero"]'

    // Hover over "Image" and a preview should appear.
    cy.get(`${imageSelect} > button`)
      .should('exist')
      .realHover()
    cy.get(
      `${imageSelect} ${previewSelect} img[alt="Boring placeholder"]`,
      ).should('exist')

    // Hover over "My Hero" and a preview should appear
    cy.get(`${heroSelect} > button`)
      .should('exist')
      .realHover()
    cy.get(
      `.ComponentPreviewContent div.my-hero__container > .my-hero__actions > .my-hero__cta--primary`,
    )
      .should('exist')
      .then(($cta) => {
        expect(
          window.getComputedStyle($cta[0])['background-color'],
          'The "My Hero" SDC is styled'
        ).to.equal('rgb(0, 123, 255)')
    })
  })

  it('uses react router successfully', () => {
      cy.drupalLogin('xbUser', 'xbUser')
      cy.drupalRelativeURL('xb')

      // Wait for the preview iframe to load and render something that confirms
      // it is ready.
      cy.get('iframe[data-xb-preview]').should('exist')
      cy.waitForElementInIframe('[data-xb-type="experience_builder:image"]')


      let componentId1 = ''
      let componentId2 = ''

      // data-xb-uuid="static-static-card1ab"
      cy.getIframeBody().find('[data-xb-type="experience_builder:my-hero"]')
        .first()
        .invoke('attr', 'data-xb-uuid')
        .then((uuid) => {
          componentId1 = uuid;
        })
      cy.getIframeBody().find('[data-xb-type="experience_builder:my-hero"]')
        .eq(2)
        .invoke('attr', 'data-xb-uuid')
        .then((uuid) => {
          componentId2 = uuid;
          expect(componentId1).to.not.equal(componentId2)
        })

      // Click a component.
      cy.getIframeBody().find('[data-xb-type="experience_builder:my-hero"] h1')
        .first()
        .trigger('click')

      // Opens the contextual form for the clicked component.
      cy.get('[class*="contextualPanel"] h4').should('contain', componentId1)

      // Now on a path specific to that component.
      cy.url().should('contain', `/xb/component/${componentId1}`)
      cy.url().should((url) => {
        expect(url, `After clicking on ${componentId1}, path should include '/xb/component/${componentId1}'`).to.contain(`/xb/component/${componentId1}`)
      })

      // Click a different component.
      cy.getIframeBody().find('[data-xb-type="experience_builder:my-hero"] h1')
        .eq(2)
        .trigger('click')

      // Opens the contextual form for the clicked component.
      cy.get('[class*="contextualPanel"] h4').should('contain', componentId2)
      // Now on a path specific to that component.
      cy.url().should('contain', `/xb/component/${componentId2}`)
      cy.url().should((url) => {
        expect(url, `After clicking on ${componentId2}, path should include '/xb/component/${componentId2}'`).to.contain(`/xb/component/${componentId2}`)
      })

      cy.go('back')
      // Returns to the URL for the prior component.
      cy.url().should((url) => {
        expect(url, `Hit back once and path should again include '/xb/component/${componentId1}'`).to.contain(`/xb/component/${componentId1}`)
      })
      // Returns to the contextual form for the prior component.
      cy.get('[class*="contextualPanel"] h4').should('contain', componentId1)

      cy.go('back')
      cy.url().should((url) => {
        expect(url, `Hit back twice and the and path should not have 'component' in it`).to.not.contain('/xb/component')
        expect(url, `Hit back twice and the path should still have /xb`).to.contain('/xb')
      })

      // No contextual panel open.
      cy.get('[class*="contextualPanel"] h4').should('not.exist')
    })
})
