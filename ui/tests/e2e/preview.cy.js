describe('Preview a page', () => {
  before(() => {
    cy.drupalXbInstall(['xb_test_vh_preview']);
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('handles elements that have height defined in vh units', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').findByText('Hero').click();
    cy.get('.primaryPanelContent').findByText('VH Half').click();
    cy.get('.primaryPanelContent').findByText('VH Full').click();
    cy.waitForElementInIframe('[data-div="vh-half"]');
    cy.waitForElementInIframe('#vh-full');
    cy.testInIframe('[data-div="vh-half"]', (vhDiv) => {
      expect(vhDiv.getBoundingClientRect().height).to.be.closeTo(384, 10);
    });
    cy.testInIframe('#vh-full', (vhDiv) => {
      expect(vhDiv.getBoundingClientRect().height).to.be.closeTo(768, 10);
    });

    // Intentionally wait two seconds to ensure the heights of the VH styled
    // styled elements have not changed.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(2000);
    cy.testInIframe('[data-div="vh-half"]', (vhDiv) => {
      expect(vhDiv.getBoundingClientRect().height).to.be.closeTo(384, 10);
    });
    cy.testInIframe('#vh-full', (vhDiv) => {
      expect(vhDiv.getBoundingClientRect().height).to.be.closeTo(768, 10);
    });

    // Edit a component to ensure the VH styled elements do not increase in
    // size when the preview updates.
    cy.clickComponentInPreview('Hero');
    cy.findByLabelText('Heading').type('{selectall}{del}');
    cy.findByLabelText('Heading').type('NO GROW');
    cy.waitForElementContentInIframe('.my-hero__heading', 'NO GROW');
    cy.testInIframe('[data-div="vh-half"]', (vhDiv) => {
      expect(vhDiv.getBoundingClientRect().height).to.be.closeTo(384, 10);
    });
    cy.testInIframe('#vh-full', (vhDiv) => {
      expect(vhDiv.getBoundingClientRect().height).to.be.closeTo(768, 10);
    });
  });

  it('Can view a preview', () => {
    cy.loadURLandWaitForXBLoaded();

    cy.findByText('Preview').click();

    cy.findByText('Exit Preview');

    cy.get('iframe[title="Page preview"]')
      .its('0.contentDocument.body')
      .should('not.be.empty')
      .then(cy.wrap)
      .within(() => {
        cy.get('.my-hero__heading').should('exist');
      });

    cy.findAllByText('Tablet').filter(':visible').click();

    cy.url().should('contain', `/xb/node/1/preview/tablet`);

    cy.get('iframe[title="Page preview"]').should(
      'have.css',
      'width',
      '1024px',
    );

    cy.findByText('Exit Preview').click();
    cy.previewReady();
  });

  it('Links are intercepted and a modal is shown', () => {
    cy.loadURLandWaitForXBLoaded();

    cy.clickComponentInPreview('Hero', 0);

    cy.findByLabelText('CTA 1 text').clear();
    cy.findByLabelText('CTA 1 text').type('Link to Drupal');
    cy.findByLabelText('CTA 1 text').blur();

    cy.waitForElementContentInIframe('a.my-hero__cta', 'Link to Drupal');

    cy.findByText('Preview').click();

    cy.findByText('Exit Preview');

    cy.get('iframe[title="Page preview"]')
      .its('0.contentDocument.body')
      .should('not.be.empty')
      .then(cy.wrap)
      .within(() => {
        cy.findByText('Link to Drupal').should(
          'have.attr',
          'data-once',
          'xbDisableLinks',
        );
        cy.findByText('Link to Drupal').click();
      });

    cy.findByText('https://drupal.org/');
    cy.findByText('Open in new window');
  });
});
