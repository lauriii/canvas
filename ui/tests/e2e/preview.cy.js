describe('Preview a page', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
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
