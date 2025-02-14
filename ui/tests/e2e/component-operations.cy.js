describe('Perform CRUD operations on components', () => {
  beforeEach(() => {
    cy.drupalXbInstall();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  afterEach(() => {
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

        const $item = $h1.closest('[data-xb-uuid]');
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
        const item = clicked.closest('[data-xb-uuid]');
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

    cy.editHeroComponent();
  });

  it('Can create and add section', () => {
    const clickDefault = {
      force: true,
      scrollBehavior: false,
    };

    cy.viewport(2000, 1320);
    cy.loadURLandWaitForXBLoaded();
    cy.get(
      '[data-xb-viewport-size="lg"] [aria-label="Two Column: Column One"]',
    ).realClick({ position: 'bottom' });
    cy.log(
      'Save the entire node 1 layout as a section, so it can be added to a different node.',
    );

    // First remove the two image components because they will otherwise crash
    // due to the test not creating them in a way that allows the media entity
    // to be found based on filename.
    cy.get(
      '[data-xb-viewport-size="lg"] [data-xb-component-id="sdc.experience_builder.image"]',
    )
      .first()
      .trigger('contextmenu', clickDefault);
    cy.findByText('Delete').click({
      force: true,
      scrollBehavior: false,
    });
    cy.get(
      '[data-xb-viewport-size="lg"] [data-xb-component-id="sdc.experience_builder.image"]',
    )
      .first()
      .trigger('contextmenu', clickDefault);
    cy.findByText('Delete').click({
      force: true,
      scrollBehavior: false,
    });
    cy.get(
      '[data-xb-viewport-size="lg"] [aria-label="Two Column: Column One"]',
    ).trigger('contextmenu', {
      ...clickDefault,
      position: 'bottom',
    });
    cy.findByText('Create section').click(clickDefault); // Section name

    // Typing into the section name input does not work instantly, so attempt
    // changing the section name until the input value properly updates.
    // This delay is short enough that no end user could possibly encounter it,
    // but it can occur within these tests.
    const sectionName = 'The Entire Node 1';
    cy.findByLabelText('Section name').should(($sectionInput) => {
      cy.devices.keyboard.type({
        $el: $sectionInput,
        chars: `{selectall}${sectionName}`,
      });
      expect($sectionInput).to.have.value(sectionName);
    });

    cy.findByText('Add to library').click({ scrollBehavior: false });
    // The dialog should close
    cy.findByLabelText('Section name').should('not.exist');

    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').within(() => {
      cy.findByText('Sections').click(clickDefault);
      cy.findByText(sectionName).should('exist');
    });
    cy.get('.primaryPanelContent')
      .as('panel')
      .should('contain.text', sectionName);

    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.get('#edit-title-0-value').should('exist');
    cy.waitForElementContentNotInIframe('div', 'There goes my hero');
    cy.openLibraryPanel();

    cy.get('[data-xb-component-id="sdc.experience_builder.my-hero"]').should(
      'exist',
    );

    // Ensure the element that can receive component drops is present.
    cy.waitForElementInIframe('.xb--sortable-slot-empty-placeholder');
    cy.get(
      '[data-xb-component-id="sdc.experience_builder.my-hero"]',
    ).realClick();
    cy.waitForElementContentInIframe('div', 'There goes my hero');

    // There should be one Hero added.
    cy.get(
      '[data-xb-viewport-size="lg"] [data-xb-component-id="sdc.experience_builder.my-hero"]',
    ).should('have.length', 1);

    // Add the section that was created earlier in this test.
    cy.openLibraryPanel();
    cy.findByText('Sections').click();
    cy.get('.primaryPanelContent').within(() => {
      cy.findByText(sectionName).should('exist');
      cy.findByText(sectionName).click(clickDefault);
    });

    // After adding the section, there should be four Hero components.
    cy.get(
      '[data-xb-viewport-size="lg"] [data-xb-component-id="sdc.experience_builder.my-hero"]',
    ).should('have.length', 4);

    // The Two Column component that is the top level element of the section
    // should be the currently selected layer.
    cy.openLayersPanel();
    cy.findByTestId('xb-primary-panel').within(() => {
      cy.findAllByText('Two Column').should('have.length', 1);
      cy.findAllByLabelText('Two Column').should(
        'have.attr',
        'data-xb-selected',
        'true',
      );
    });
  });

  it('Can add component by clicking component in library', () => {
    cy.loadURLandWaitForXBLoaded();

    // Check there are three heroes initially.
    cy.testInIframe(
      '[data-component-id="experience_builder:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.equal(3);
      },
    );

    cy.clickComponentInPreview('Image');
    cy.openLibraryPanel();

    cy.get('.primaryPanelContent').should('contain.text', 'Components');
    // Click the "Hero" SDC-sourced Component.
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
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

    // Click the "My First Code Component" JS-sourced Component.
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
    cy.get('.primaryPanelContent')
      .findByText('My First Code Component')
      .click();
    // @todo Make the test code component have meaningful markup and then assert details about its preview in https://www.drupal.org/i/3499988
    cy.log('The newly added code component should be selected');
    cy.findAllByLabelText('My First Code Component', {
      selector: '.componentOverlay',
    })
      .eq(0)
      .should('have.attr', 'data-xb-selected', 'true');
  });

  it('Can delete component with delete key', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();

    // Check there are three heroes initially.
    cy.getComponentInPreview('Hero', 1).should('exist');
    cy.getComponentInPreview('Hero', 2).should('exist');

    // Select the component and ensure it's focused
    cy.clickComponentInPreview('Hero');

    cy.realPress('{del}');
    cy.previewReady();

    // Check there are two heroes after deleting
    cy.getComponentInPreview('Hero', 1).should('exist');
    cy.getComponentInPreview('Hero', 2).should('not.exist');

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

  it('Can add a component with empty slots', () => {
    cy.loadURLandWaitForXBLoaded();
    // Assert there is an existing two column SDC on the page already.
    cy.findByTestId('xb-primary-panel').within(() => {
      cy.findAllByText('Two Column').should('have.length', 1);
    });
    cy.get('.primaryPanelContent').findByText('Two Column').click();
    cy.openLibraryPanel();
    // Click on Two Column inside menu.
    cy.get('.primaryPanelContent').findByText('Two Column').click();
    cy.waitForElementContentInIframe('div', 'This is column 1 content');
    cy.waitForElementContentInIframe('div', 'This is column 2 content');
    cy.openLayersPanel();
    // Assert that a second two column SDC has been added.
    cy.findByTestId('xb-primary-panel').within(() => {
      cy.findAllByText('Two Column').should('have.length', 2);
    });
  });
});
