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
            '.xb--viewport-overlay [data-xb-component-id="sdc.experience_builder.image"]',
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
            '.xb--viewport-overlay [data-xb-component-id="sdc.experience_builder.image"]',
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
      cy.findByText('Publish all changes').click();
      cy.findByTestId('xb-review-publish-errors').should('exist');
      cy.findByTestId('xb-review-publish-errors').should(($errorsContainer) => {
        expect($errorsContainer.find('h3')).to.include.text('2 Errors');
        const expectedH4 = [
          'XB Needs This For The Time Being',
          'I am an empty node',
        ];
        $errorsContainer.find('h4').each((index, h4) => {
          expect(h4).to.include.text(expectedH4[index]);
        });
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

  it(
    'Publish process does not currently notice form validation errors',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.clearAutoSave('node', 2);
      cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
      cy.findByLabelText('XB Text Field').type('invalid value');
      cy.get('[data-testid="xb-publish-review"]:not([disabled])', {
        timeout: 20000,
      }).should('exist');
      cy.publishAllPendingChanges('I am an empty node');
      cy.get('[data-testid="xb-publish-reviews-content"] p.rt-CalloutText')
        .contains('All changes published!')
        .should('exist');
      cy.waitForElementContentInIframe(
        'div[role="contentinfo"] div[role="alert"]',
        'The value "invalid value" is not allowed in this field.',
      );
    },
  );
});
