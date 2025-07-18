describe('Perform CRUD operations on components that require disabled CSS aggregation', () => {
  before(() => {
    cy.drupalXbInstall([], { disableAggregation: true });
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('The iframe loads the SDC CSS', () => {
    cy.loadURLandWaitForXBLoaded();
    cy.getIframe(
      '[data-test-xb-content-initialized="true"][data-xb-swap-active="true"]',
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
  });
});

describe('Perform CRUD operations on components', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Can handle empty heading prop in hero component', () => {
    // Load the page and add hero component
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').findByText('Hero').click();

    // Wait for hero to be added and default content to appear
    cy.waitForElementContentInIframe(
      'h1.my-hero__heading',
      'There goes my hero',
    );
    cy.waitForElementContentInIframe(
      'p.my-hero__subheading',
      'Watch him as he goes!',
    );

    // Clear the heading field
    cy.findByLabelText('Heading').type('{selectall}{del}');

    // Verify preview still loads and heading is empty
    cy.waitForElementContentNotInIframe(
      'h1.my-hero__heading',
      'There goes my hero',
    );
    cy.waitForElementContentInIframe(
      'p.my-hero__subheading',
      'Watch him as he goes!',
    );

    // Refresh page
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2', clearAutoSave: false });
    cy.clickComponentInPreview('Hero');

    // Verify preview still loads and heading is empty.
    cy.waitForElementContentInIframe(
      'p.my-hero__subheading',
      'Watch him as he goes!',
    );
    cy.waitForElementContentNotInIframe(
      'h1.my-hero__heading',
      'There goes my hero',
    );

    // Verify heading input is still empty
    cy.findByLabelText('Heading').should('have.value', '');

    // Add new heading text and verify preview updates,
    const newHeading = 'New Hero Heading';
    cy.findByLabelText('Heading').type(newHeading);
    cy.waitForElementContentInIframe('h1.my-hero__heading', newHeading);
  });

  it('Should be able to blur autocomplete without problems. See #3519734', () => {
    cy.intercept('POST', '**/xb/api/v0/layout/**').as('updatePreview');
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.get('#edit-title-0-value').should('exist');
    cy.waitForElementContentNotInIframe('div', 'There goes my hero');
    cy.openLibraryPanel();

    cy.get('[data-xb-component-id="sdc.xb_test_sdc.my-hero"]').should('exist');

    cy.get('[data-xb-component-id="sdc.xb_test_sdc.my-hero"]').realClick();
    cy.waitForElementContentInIframe('div', 'There goes my hero');
    const headType = 'Head is different';
    const subType = 'Sub also experienced change';
    cy.findByLabelText('Heading').type(`{selectall}{del}${headType}`);
    cy.findByLabelText('Sub-heading').type(`{selectall}{del}${subType}`);
    cy.findByLabelText('Heading').should('have.value', headType);
    cy.findByLabelText('Sub-heading').should('have.value', subType);
    cy.waitForElementContentInIframe(
      '[data-component-id="xb_test_sdc:my-hero"] h1',
      headType,
    );
    cy.waitForElementContentInIframe(
      '[data-component-id="xb_test_sdc:my-hero"] p',
      subType,
    );

    // Click the autocomplete field.
    cy.findByLabelText('CTA 1 link').type('com', { force: true });
    // Click another field to blur the autocomplete field, which in #3519734
    // would revert the preview to earlier values.
    cy.findByLabelText('CTA 2 text').click();

    // To make this a test that will fail without the fix present, but pass with
    // the fix in place, we need a fixed value wait here so there's enough time
    // for the problem to appear in the preview, without waiting for specific
    // preview contents. Those contents will be asserted after this.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(1000);

    cy.waitForElementContentInIframe(
      '[data-component-id="xb_test_sdc:my-hero"] h1',
      headType,
    );
    cy.waitForElementContentInIframe(
      '[data-component-id="xb_test_sdc:my-hero"] p',
      subType,
    );

    cy.findByLabelText('Heading').should('have.value', headType);
    cy.findByLabelText('Sub-heading').should('have.value', subType);
  });

  it('Created a node 1 with type article on install', () => {
    cy.drupalRelativeURL('node/1');
    cy.get('h1').should(($h1) => {
      expect($h1.text()).to.include('XB Needs This');
    });
    cy.get('[data-component-id="xb_test_sdc:my-hero"] h1').should(($h1) => {
      expect($h1.text()).to.include('hello, world!');
    });
    cy.get(
      '[data-component-id="xb_test_sdc:my-hero"] a[href="https://drupal.org"]',
    ).should.exist;
    cy.get(
      '[data-component-id="xb_test_sdc:my-hero"] a[href="https://drupal.org"] ~ button',
    ).should.exist;
  });

  it('Can access XB UI and do basic interactions', () => {
    cy.loadURLandWaitForXBLoaded();

    // Confirm that some elements in the default layout are present in the
    // default iframe (lg).
    cy.testInIframe('[data-component-id="xb_test_sdc:my-hero"] h1', (h1s) => {
      expect(h1s.length).to.equal(3);
      h1s.forEach((h1, index) =>
        expect(h1.textContent).to.equal(
          index === 0 ? 'hello, world!' : 'XB Needs This For The Time Being',
        ),
      );
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
          'Pattern',
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
      .find('[data-component-id="xb_test_sdc:my-hero"] h1')
      .first()
      .then(($h1) => {
        cy.wrap($h1).trigger('mouseover');
        // While in the iframe, get the dimensions of the component so we can
        // compare the outline dimensions to it

        const $item = $h1.closest('[data-xb-uuid]');
        lgPreviewRect = $item[0].getBoundingClientRect();
      });

    // After hovering, the component should be outlined.
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

    // Click the component to trigger the opening of the right drawer.
    cy.clickComponentInPreview('Hero');

    cy.editHeroComponent();
  });

  it('Can add component by clicking component in library', () => {
    cy.loadURLandWaitForXBLoaded();

    // Check there are three heroes initially.
    cy.testInIframe(
      '[data-component-id="xb_test_sdc:my-hero"]',
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
      '[data-component-id="xb_test_sdc:my-hero"]',
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
    cy.getAllComponentsInPreview('Hero').should('have.length', 3);

    // Select the component and ensure it's focused
    cy.clickComponentInPreview('Hero');

    cy.realPress('{del}');
    cy.previewReady();

    // Check there are two heroes after deleting
    cy.getAllComponentsInPreview('Hero').should('have.length', 2);

    cy.getIframeBody()
      .find('[data-component-id="xb_test_sdc:two_column"]')
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

    // The component begins with one content drop zone.
    cy.findAllByTestId('xb-empty-slot-drop-zone').should('have.length', 1);

    // Assert there is an existing two column SDC on the page already.
    cy.findByTestId('xb-primary-panel').within(() => {
      cy.findAllByText('Two Column').should('have.length', 1);
    });
    cy.get('.primaryPanelContent').findByText('Two Column').click();
    cy.openLibraryPanel();
    // Click on Two Column inside menu.
    cy.get('.primaryPanelContent').findByText('Two Column').click();
    cy.log(
      'There should be 2 new drop zones - 2 added columns in addition to the original',
    );
    cy.findAllByTestId('xb-empty-slot-drop-zone').should('have.length', 3);

    cy.openLayersPanel();
    // Assert that a second two column SDC has been added.
    cy.findByTestId('xb-primary-panel').within(() => {
      cy.findAllByText('Two Column').should('have.length', 2);
    });
  });

  it('Can add an image component with a preview but empty input', () => {
    cy.loadURLandWaitForXBLoaded();

    // Delete the two existing image components.
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');

    cy.waitForComponentNotInPreview('Image');

    cy.openLibraryPanel();

    cy.get('.primaryPanelContent').findByText('Image').click();

    cy.intercept('POST', '**/xb/api/v0/layout/node/1').then(cy.log);

    // Check the default image src is set.
    cy.waitForElementInIframe(
      'img[src*="/xb_test_sdc/components/image/600x400.png"]',
      '[data-test-xb-content-initialized="true"][data-xb-swap-active="true"]',
      40000,
    );
  });

  it('Can add an optional image component with a preview but empty input', () => {
    cy.loadURLandWaitForXBLoaded();

    // Delete the two existing image components.
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');
    cy.clickComponentInPreview('Image');
    cy.realPress('{del}');

    cy.waitForComponentNotInPreview('Image');

    cy.openLibraryPanel();
    cy.get('.primaryPanelContent')
      .findByText('XB test SDC with optional image and heading')
      .click();

    // Check the default image src is set.
    cy.waitForElementInIframe(
      'img[src*="/image-optional-with-example-and-additional-prop/gracie.jpg"]',
    );

    cy.publishAllPendingChanges([
      'XB Needs This For The Time Being',
      'I am an empty node',
    ]);

    cy.visit('/node/1');
    cy.get(
      'img[src*="/image-optional-with-example-and-additional-prop/gracie.jpg"]',
    ).should('exist');
  });
});
