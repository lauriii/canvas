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

  it(
    'Can make a change and see changes in the "Review x changes" button',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.loadURLandWaitForXBLoaded();

      cy.findByTestId('xb-topbar').findByText('Published');

      cy.clickComponentInPreview('Hero');

      cy.findByTestId(/^xb-component-form-.*/)
        .findByLabelText('Heading')
        .type(' updated');

      cy.findByText('Changed');

      cy.findByText('Review 1 change').click();

      // Delete the image that uses an adapted source. This node (1) includes prop
      // sources that make use of adapters, we need to delete the adapted source
      // image in order to publish.
      cy.clickComponentInPreview('Image', 1);
      cy.realType('{del}');

      cy.visit('/node/1');

      cy.findByText('hello, world! updated').should('not.exist');

      cy.loadURLandWaitForXBLoaded({ clearAutoSave: false });

      cy.publishAllPendingChanges('XB Needs This For The Time Being');

      cy.log('After publishing, there should be no changes.');
      cy.findByTestId('xb-topbar')
        .findByText('No changes', { selector: 'button' })
        .should('exist');
      cy.findByTestId('xb-topbar').findByText('Published');

      cy.log(
        'After publishing and reloading the page, there should be no changes.',
      );
      cy.loadURLandWaitForXBLoaded({ clearAutoSave: false });
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

      cy.findByTestId('xb-topbar').findByText('Changed');
      cy.findByText('Review 1 change').click();

      cy.log('...and make sure the change shows up in the drop-down');
      cy.findByTestId('xb-publish-reviews-content').within(() => {
        cy.findByText('XB Needs This For The Time Being');
      });

      cy.log('After publishing, the change should be visible page!');
      cy.visit('/node/1');
      cy.findByText('hello, world! updated').should('exist');
    },
  );

  it(
    'User without "publish auto-saves" permission cannot publish changes',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      // Create user with all xbUser permissions except publish auto-saves
      cy.visit('admin/people/permissions/xb');
      cy.get(
        'input[type="checkbox"][data-drupal-selector="edit-xb-publish-auto-saves"]',
      ).uncheck();
      cy.get('input[data-drupal-selector="edit-submit"]').click();

      cy.loadURLandWaitForXBLoaded();

      cy.clickComponentInPreview('Hero');
      cy.findByTestId(/^xb-component-form-.*/)
        .findByLabelText('Heading')
        .type(' updated by user without publish permission');

      cy.findByText('Changed');
      cy.findByText('Review 1 change').click();

      cy.findByTestId('xb-publish-reviews-content').within(() => {
        cy.findByTestId('xb-publish-review-select-all').click();
        cy.findByText(/Publish \d selected/).should('not.exist');
      });
    },
  );
});
