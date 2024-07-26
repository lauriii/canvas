describe('General Experience Builder', {testIsolation: false}, () => {
  before( () => {
    cy.drupalXbInstall()
  });

  after(() => {
    cy.drupalUninstall()
  })

  beforeEach(() => {
    cy.drupalSession();
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
    cy.get('[role="dialog"][vaul-drawer-direction="right"][data-state="open"]').should('not.exist')

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

    // The right drawer has opened.
    cy.get('[role="dialog"][vaul-drawer-direction="right"][data-state="open"]').should('exist')

    // The drawer contains a component edit form.
    cy.get('[role="dialog"][vaul-drawer-direction="right"][data-state="open"] [data-drupal-selector="component-props-form"].component-props-form').should(($form) => {
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
  })
})
