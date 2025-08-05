// cspell:ignore Duderino

describe('Publish review functionality', () => {
  beforeEach(() => {
    cy.drupalXbInstall([
      'xb_test_article_fields',
      'xb_test_invalid_field',
      'xb_force_publish_error',
    ]);
    cy.drupalSession();
    cy.drupalLogin('xbUser', 'xbUser');
  });

  afterEach(() => {
    cy.drupalUninstall();
  });

  it('Handles non-validation publish errors', () => {
    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/2' });
    cy.findByLabelText('Title').type('{selectall}{del}');
    cy.findByLabelText('Title').type('cause exception');
    cy.get('[data-testid="xb-publish-review"]:not([disabled])', {
      timeout: 20000,
    }).should('exist');

    cy.publishAllPendingChanges(['cause exception'], false);
    cy.get('[data-testid="error-details"] h4').should(
      'have.text',
      'cause exception',
    );
    cy.get('[data-testid="publish-error-detail"]').should(
      'include.text',
      'Forced exception for testing purposes.',
    );
    cy.get('button', { timeout: 20000 })
      .contains(`Publish 1 selected`, { timeout: 20000 })
      .should('exist');
  });

  it('Has links to the corresponding entity in errors', () => {
    const entityData = [];
    const paths = [{ path: 'xb/xb_page/2' }, { path: 'xb/node/2' }];
    paths.forEach(({ path }) => {
      cy.loadURLandWaitForXBLoaded({ url: path });
      cy.openLibraryPanel();
      cy.get('.primaryPanelContent').findByText('Hero').click();
      cy.findByLabelText('Heading').type('{selectall}{del}');
      cy.findByLabelText('Heading').type('Z');
      cy.waitForElementContentInIframe('.my-hero__heading', 'Z');
      cy.window().then((win) => {
        entityData.push({
          title: win.document.querySelector(
            '[data-testid="xb-navigation-button"]',
          ).textContent,
          componentId: win.document
            .querySelector(
              '[data-xb-component-id="sdc.xb_test_sdc.my-hero"][data-xb-uuid]',
            )
            .getAttribute('data-xb-uuid'),
          path,
        });
        if (entityData.length === paths.length) {
          cy.intercept('POST', '**/xb/api/v0/auto-saves/publish', {
            statusCode: 422,
            body: {
              errors: [
                {
                  detail:
                    'We really dislike the following thing you typed: "Z".',
                  source: {
                    pointer: `model.${entityData[0].componentId}.heading`,
                  },
                  meta: {
                    entity_type: entityData[0].path.split('/')[1],
                    entity_id: '2',
                    label: entityData[0].title,
                    api_auto_save_key: `${entityData[0].path.split('/')[1]}:2:en`,
                  },
                  entityLabel: entityData[0].title,
                },
                {
                  detail:
                    'We really dislike the following thing you typed: "Z".',
                  source: {
                    pointer: `model.${entityData[1].componentId}.heading`,
                  },
                  meta: {
                    entity_type: entityData[1].path.split('/')[1],
                    entity_id: '2',
                    label: entityData[1].title,
                    api_auto_save_key: `${entityData[1].path.split('/')[1]}:2:en`,
                  },
                  entityLabel: entityData[1].title,
                },
              ],
            },
          }).as('publishRequest');
        }
      });
    });

    cy.loadURLandWaitForXBLoaded({ url: 'xb/xb_page/1', clearAutoSave: false });
    cy.findByText('Review 2 changes').should('exist');
    cy.publishAllPendingChanges(['I am an empty node', 'Empty Page'], false);
    cy.get('[data-testid="error-details"]').then(($errors) => {
      $errors.each((i, element) => {
        const title = element.querySelector('h4').textContent;
        const href = element
          .querySelector('[data-testid="publish-error-detail"] a')
          .getAttribute('href');
        const data = entityData.filter((item) => item.title === title);
        expect(data).to.have.length(1);
        expect(
          href.endsWith(
            `/${data[0].path}/editor/component/${data[0].componentId}`,
          ),
        ).to.be.true;
      });
    });
    // Alter the link so it opens in the current tab.
    cy.get('[data-testid="publish-error-detail"] a').invoke(
      'attr',
      'target',
      '_self',
    );
    // Confirm clicking the link opens the UI of the erroring entity and the
    // component with the error is selected.
    cy.get('[data-testid="publish-error-detail"] a').first().click();
    cy.waitForElementContentInIframe('.my-hero__heading', 'Z');
    cy.findByLabelText('Heading').should('exist');
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
