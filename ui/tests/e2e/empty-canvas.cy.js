describe('Empty preview', () => {
  beforeEach(() => {
    // Unlike most tests, we are installing drupal before each it() as that has
    // demonstrated to be the only reliable way to get tests after the first
    // passing consistently. This occurs regardless of which test runs first.
    cy.drupalCanvasInstall(['metatag']);
    cy.drupalLogin('canvasUser', 'canvasUser');
    // Add `auto-saves/pending` intercept.
    cy.intercept('GET', '**/canvas/api/v0/auto-saves/pending', {
      log: false,
    }).as('getAutoSaveItems');
  });

  afterEach(() => {
    // Validate `auto-saves/pending` calls did not result in error responses.
    cy.get('@getAutoSaveItems.all').then((interceptions) => {
      // Assert we only get HTTP 200.
      interceptions.forEach((interception) => {
        // Cypress does not wait for responses unless explicitly told to.
        if (interception.responseWaited) {
          expect(interception.response.statusCode).to.equal(200);
        }
      });
    });
    cy.drupalUninstall();
  });

  // @todo test 'canvas/page', 'canvas/page/2' once Canvas router isn't tied to URL path
  //   matching the /canvas/{entity_type}/{entity_id} pattern and relies only on
  //   what exists in `drupalSettings.canvas` instead.
  //   Fix after https://www.drupal.org/project/canvas/issues/3489775
  it(`canvas/editor/node/2 can add a component to an empty preview`, () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    // Wait for an element in the page data panel to be present.
    cy.get('#edit-title-0-value').should('exist');

    // Confirm there is nothing in the preview.
    cy.get('.canvas--viewport-overlay [data-canvas-component-id]').should(
      'not.exist',
    );

    // For good measure, also confirm the content of the hero component is not
    // in the preview.
    cy.waitForElementContentNotInIframe('div', 'There goes my hero');

    cy.get('[data-canvas-component-id="sdc.canvas_test_sdc.my-hero"]').should(
      'not.exist',
    );
    cy.openLibraryPanel();

    // This is the component to be dragged in.
    cy.get('[data-canvas-component-id="sdc.canvas_test_sdc.my-hero"]').should(
      'exist',
    );

    cy.waitForElementInIframe('.canvas--region-empty-placeholder');
    cy.insertComponent({ name: 'Hero' });

    cy.log('The hero component is now in the iframe');

    cy.waitForElementContentInIframe('div', 'There goes my hero');
    cy.getIframeBody().within(() => {
      cy.get('[data-component-id="canvas_test_sdc:my-hero"]').should(
        'have.length',
        1,
      );
    });
  });

  it(`canvas/editor/canvas_page/2 can add a component to an empty preview`, () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/canvas_page/2' });
    cy.wait('@getAutoSaveItems');

    // Wait for an element in the page data panel to be present.
    cy.get('#edit-title-0-value').should('exist');

    cy.get('#edit-seo-settings').should('exist');
    cy.get('#edit-seo-settings #edit-image-wrapper').should('exist');
    cy.get('#edit-seo-settings #edit-metatags-0-basic-title').should('exist');
    cy.get('#edit-seo-settings #edit-description-wrapper').should('exist');

    // Confirm there is nothing in the preview.
    cy.get('.canvas--viewport-overlay [data-canvas-component-id]').should(
      'not.exist',
    );

    // For good measure, also confirm the content of the hero component is not
    // in the preview.
    cy.waitForElementContentNotInIframe('div', 'There goes my hero');

    cy.get('[data-canvas-component-id="sdc.canvas_test_sdc.my-hero"]').should(
      'not.exist',
    );
    cy.openLibraryPanel();

    // This is the component to be dragged in.
    cy.get('[data-canvas-component-id="sdc.canvas_test_sdc.my-hero"]').should(
      'exist',
    );

    cy.waitForElementInIframe('.canvas--region-empty-placeholder');

    cy.insertComponent({ name: 'Hero' });

    cy.waitForElementContentInIframe('div', 'There goes my hero');
    cy.getIframeBody().within(() => {
      cy.get('[data-component-id="canvas_test_sdc:my-hero"]').should(
        'have.length',
        1,
      );
    });
    // Wait for at least one `auto-saves/pending` call to finish.
    cy.wait('@getAutoSaveItems');
  });
});
