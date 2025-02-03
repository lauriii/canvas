// @todo Expand this test to include coverage for "Page Data" fields such as 'title' and 'URL alias' in https://drupal.org/i/3495752.
// @todo Expand this test to include coverage for adding a component with no properties in https://drupal.org/i/3498227.

describe('Publish review functionality', () => {
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

  it('Can make a change and see changes in the “Review x changes” button', () => {
    cy.loadURLandWaitForXBLoaded();

    cy.clickComponentInPreview('Hero');

    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('Heading')
      .type(' updated');

    // Extra long timeout here, because the poll to get changes is every 10 seconds.
    cy.findByText('Review 1 change', { timeout: '11000' }).click();

    cy.visit('/node/1');

    cy.findByText('hello, world! updated').should('not.exist');

    cy.loadURLandWaitForXBLoaded({ clearAutoSave: false });

    // Extra long timeout here, because the poll to get changes is every 10 seconds.
    cy.findByText('Review 1 change', { timeout: '11000' }).click();

    cy.findByTestId('xb-publish-reviews-content').within(() => {
      cy.findByText('XB Needs This For The Time Being');

      cy.findByText('Publish all changes').click();

      cy.findByText('Publishing').should('exist');
      cy.findByText('Publishing').should('not.exist');
    });

    cy.findByTestId('xb-publish-reviews-content').within(() => {
      cy.findByText('All changes published!');
      cy.findByText('Errors').should('not.exist');
      cy.findByLabelText('Close').click();
    });

    cy.log('After publishing, there should be no changes.');
    cy.findByTestId('xb-topbar')
      .findByText('No changes', { selector: 'button' })
      .should('exist');

    cy.log(
      'Make another change and ensure the button still updates say "Review n changes"',
    );
    cy.clickComponentInPreview('Hero');
    cy.findByTestId(/^xb-component-form-.*/)
      .findByLabelText('Heading')
      .type(' updated again');

    // Extra long timeout here, because the poll to get changes is every 10 seconds.
    cy.findByText('Review 1 change', { timeout: '11000' }).click();

    cy.log('...and make sure the change shows up in the drop-down');
    cy.findByTestId('xb-publish-reviews-content').within(() => {
      cy.findByText('XB Needs This For The Time Being');
    });

    cy.log('After publishing, the change should be visible page!');
    cy.visit('/node/1');
    cy.findByText('hello, world! updated').should('exist');
  });
});
