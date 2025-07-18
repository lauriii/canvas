// cspell:ignore Duderino

describe('Publish review functionality', () => {
  beforeEach(() => {
    cy.drupalXbInstall(['xb_test_article_fields', 'xb_test_invalid_field']);
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  afterEach(() => {
    cy.drupalUninstall();
  });

  it(
    'Publish will error when attempting to publish entity with failing validation constraint',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.clearAutoSave('node', 1);
      cy.clearAutoSave('node', 2);

      const iterations = [
        { path: 'xb/node/1', waitFor: 'Review 1 change' },
        { path: 'xb/node/2', waitFor: 'Review 2 changes' },
      ];

      iterations.forEach(({ path, waitFor }, index) => {
        cy.loadURLandWaitForXBLoaded({ url: path, clearAutoSave: false });
        // First remove the two image components because they will otherwise crash
        // due to the test not creating them in a way that allows the media entity
        // to be found based on filename.
        if (path === 'xb/node/1') {
          cy.get(
            '.xb--viewport-overlay [data-xb-component-id="sdc.xb_test_sdc.image"]',
          )
            .first()
            .trigger('contextmenu', {
              force: true,
              scrollBehavior: false,
            });
          cy.findByText('Delete').click({
            force: true,
            scrollBehavior: false,
          });
          cy.get(
            '.xb--viewport-overlay [data-xb-component-id="sdc.xb_test_sdc.image"]',
          )
            .first()
            .trigger('contextmenu', {
              force: true,
              scrollBehavior: false,
            });
          cy.findByText('Delete').click({
            force: true,
            scrollBehavior: false,
          });
          cy.waitForComponentNotInPreview('Image');
          cy.findByText(waitFor).should('not.exist');
        }

        cy.findByLabelText('XB Text Field').type('invalid constraint');
        cy.get('[data-testid="xb-publish-review"]:not([disabled])', {
          timeout: 20000,
        }).should('exist');
        cy.findByText(waitFor, { timeout: 20000 }).should('exist');
      });

      cy.findByText('Review 2 changes').click();
      cy.findByTestId('xb-publish-review-select-all').click();
      cy.findByText('Publish 2 selected').click();
      cy.findByTestId('xb-review-publish-errors').should('exist');
      cy.findByTestId('xb-review-publish-errors').should(($errorsContainer) => {
        expect($errorsContainer.find('h3')).to.include.text('Errors');
        $errorsContainer
          .find('[data-testid="publish-error-detail"]')
          .each((index, errorDetail) => {
            expect(errorDetail).to.include.text(
              'The value "invalid constraint" is not allowed in this field.',
            );
          });
      });
    },
  );
});

describe('Form validation ✅', { retries: { openMode: 0, runMode: 3 } }, () => {
  before(() => {
    cy.drupalInstall({
      setupFile: Cypress.env('setupFile'),
      // Need this permission to view the author field.
      extraPermissions: ['administer nodes'],
    });
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Form validation errors prevent publishing', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
    cy.findByText('Authoring information').click({ force: true });
    cy.findByText('Authoring information')
      .parents('[data-state="open"][data-drupal-selector]')
      .as('authoringInformation');
    cy.get('@authoringInformation')
      .findByLabelText('Authored by', { exact: false })
      .as('author');
    cy.get('@author').clear({ force: true });
    cy.get('@author').type('El Duderino', { force: true });
    // Blur the autocomplete input to trigger an update.
    cy.findByLabelText('Title').focus();

    cy.get('[data-testid="xb-publish-review"]:not([disabled])', {
      timeout: 20000,
    }).should('exist');
    cy.findByRole('button', {
      name: /Review \d+ change/,
      timeout: 20000,
    }).as('review');
    // We break this up to allow for the pending changes refresh which can disable
    // the button whilst it is loading.
    cy.get('@review').click();
    // Enable extended debug output from failed publishing.
    cy.intercept('**/xb/api/v0/auto-saves/publish');
    cy.findByTestId('xb-publish-reviews-content')
      .as('publishReview')
      .should('exist');
    cy.findByTestId('xb-publish-review-select-all').click();
    cy.get('@publishReview')
      .findByRole('button', { name: /Publish \d+ selected/ })
      .click();
    cy.get('@publishReview')
      .findByTestId('publish-error-detail')
      .findByText('There are no users matching "El Duderino".', {
        exact: false,
      })
      .should('exist');
  });
});
