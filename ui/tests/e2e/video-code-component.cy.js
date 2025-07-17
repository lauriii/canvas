describe('📹️ Code video component', () => {
  beforeEach(() => {
    cy.drupalXbInstall(['xb_test_e2e_code_components']);
    cy.drupalSession();
    // A larger viewport makes it easier to debug in the test runner app.
    cy.viewport(2000, 1000);
  });

  afterEach(() => {
    cy.drupalUninstall();
  });

  it(
    'Can use a generic file widget to populate a video prop',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.drupalLogin('xbUser', 'xbUser');
      cy.loadURLandWaitForXBLoaded({ url: 'xb/node/2' });
      cy.openLibraryPanel();
      cy.get('.primaryPanelContent').should('contain.text', 'Components');
      cy.get('.primaryPanelContent').findByText('Code Video').click();
      // Check the default video src is set.
      const iframeSelector =
        '[data-test-xb-content-initialized="true"][data-xb-swap-active="true"]';
      cy.waitForElementInIframe(
        'video[src*="/ui/assets/videos/mountain_wide.mp4"]',
        iframeSelector,
        10000,
      );
      cy.get('[data-testid*="xb-component-form-"]').as('inputForm');
      cy.get('@inputForm').recordFormBuildId();
      // Log all ajax form requests to help with debugging.
      cy.intercept('PATCH', '**/xb/api/v0/form/component-instance/**').as(
        'patch',
      );
      cy.get('@inputForm')
        .findByLabelText('theVideo')
        .selectFile(
          '../tests/modules/xb_test_video_fixture/videos/waterfall.mp4',
        );
      cy.get('@inputForm').shouldHaveUpdatedFormBuildId({ timeout: 11000 });
      cy.waitForElementInIframe(
        'video[src*="waterfall.mp4"]',
        iframeSelector,
        10000,
      );
      cy.get('@inputForm')
        .findByRole('button', {
          name: 'Remove',
        })
        .click();
      cy.get('@inputForm').shouldHaveUpdatedFormBuildId({ timeout: 11000 });
      // This video has an example, so removing the user value should revert to
      // the default value
      cy.waitForElementInIframe(
        'video[src*="/ui/assets/videos/mountain_wide.mp4"]',
        iframeSelector,
        10000,
      );
      // Remove the component.
      cy.clickComponentInPreview('Code Video');
      cy.realPress('{del}');

      // Now let's test the required video component.
      cy.get('.primaryPanelContent').findByText('CodeVideoRequired').click();
      // Check the default video src is set.
      cy.waitForElementInIframe(
        'video[src*="/ui/assets/videos/bird_vertical.mp4"]',
        iframeSelector,
        10000,
      );
      cy.get('[data-testid*="xb-component-form-"]').as('inputForm');
      cy.get('@inputForm').recordFormBuildId();

      cy.get('@inputForm')
        .findByLabelText('theVideo')
        .selectFile('../tests/modules/xb_test_video_fixture/videos/duck.mp4');
      cy.get('@inputForm').shouldHaveUpdatedFormBuildId({ timeout: 11000 });
      cy.waitForElementInIframe(
        'video[src*="duck.mp4"]',
        iframeSelector,
        10000,
      );
      cy.get('@inputForm')
        .findByRole('button', {
          name: 'Remove',
        })
        .click();
      cy.get('@inputForm').shouldHaveUpdatedFormBuildId({ timeout: 11000 });
      // This video is a required prop, so removing it should fall back to the
      // default value.
      cy.waitForElementInIframe(
        'video[src*="/ui/assets/videos/bird_vertical.mp4"]',
        iframeSelector,
        10000,
      );
    },
  );
});
