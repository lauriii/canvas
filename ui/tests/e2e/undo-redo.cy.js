describe('Undo/Redo functionality', () => {
  before(() => {
    cy.drupalCanvasInstall();
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('Performs a basic interaction with Undo/Redo', () => {
    cy.loadURLandWaitForCanvasLoaded();
    cy.openLibraryPanel();

    // Assert that the undo button is disabled initially.
    cy.get('button[aria-label="Undo"]').should('be.disabled');

    // Check there are three heroes initially.
    cy.testInIframe(
      '[data-component-id="canvas_test_sdc:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.equal(3);
      },
    );
    cy.insertComponent({ name: 'Two Column' });

    // Click on the menu item with data-canvas-name="Hero" inside menu.
    cy.insertComponent({ name: 'Hero' });

    const heroInPreview = '[data-component-id="canvas_test_sdc:my-hero"]';

    // Inserting the Hero adds a fourth my-hero to the preview. Each layout
    // change re-renders the preview asynchronously and swaps the iframe, so use
    // a retryable count assertion that re-queries the active iframe and waits
    // for the render to settle. (The previous version used
    // `cy.getIframeBody().find(selector, callback)`, whose second argument is
    // options, not a callback — so the count was never actually asserted.)
    cy.waitForElementCountInIframe(heroInPreview, 4, undefined, 12000);

    // Undo removes the just-inserted Hero.
    cy.realPress(['Meta', 'Z']);
    cy.waitForElementCountInIframe(heroInPreview, 3, undefined, 12000);

    // Redo adds it back.
    cy.realPress(['Meta', 'Shift', 'Z']);
    cy.waitForElementCountInIframe(heroInPreview, 4, undefined, 12000);
  });

  it('Component instance form values are included in Undo/Redo', () => {
    cy.loadURLandWaitForCanvasLoaded();

    // Click on our "hello, world!" hero component.
    cy.clickComponentInPreview('Hero');

    // Add " one" to the heading field.
    cy.findByTestId(/^canvas-component-form-.*/)
      .findByLabelText('Heading')
      .click();
    cy.findByTestId(/^canvas-component-form-.*/)
      .findByLabelText('Heading')
      .type(' one');

    cy.waitForElementContentInIframe('.my-hero__heading', 'hello, world! one');
    cy.findByTestId(/^canvas-component-form-.*/)
      .findByLabelText('Heading')
      .should('have.value', 'hello, world! one');

    // Add " two" to the heading field.
    cy.findByTestId(/^canvas-component-form-.*/)
      .findByLabelText('Heading')
      .click();
    cy.findByTestId(/^canvas-component-form-.*/)
      .findByLabelText('Heading')
      .type(' two');
    cy.findByTestId(/^canvas-component-form-.*/)
      .findByLabelText('Heading')
      .blur();
    // Disable the no-unnecessary-waiting eslint rule below because we need to wait
    // for the debounce to finish to ensure the undo history is updated.
    cy.wait(500); // eslint-disable-line cypress/no-unnecessary-waiting

    cy.findByTestId(/^canvas-component-form-.*/)
      .findByLabelText('Heading')
      .should('have.value', 'hello, world! one two');

    cy.waitForElementContentInIframe(
      '.my-hero__heading',
      'hello, world! one two',
    );

    // Undo, see if the value is "hello, world! one".
    cy.realPress(['Meta', 'Z']);
    cy.findByLabelText('Heading').should((input) => {
      expect(input).to.have.value('hello, world! one');
    });
    cy.waitForElementContentInIframe('.my-hero__heading', 'hello, world! one');

    // Redo, see if the value is "hello, world! one two".
    cy.realPress(['Meta', 'Shift', 'Z']);
    cy.findByLabelText('Heading').should((input) => {
      expect(input).to.have.value('hello, world! one two');
    });
    cy.waitForElementContentInIframe(
      '.my-hero__heading',
      'hello, world! one two',
    );

    // Undo twice, see if the value is "hello, world!".
    cy.realPress(['Meta', 'Z']);
    cy.realPress(['Meta', 'Z']);
    cy.findByLabelText('Heading').should((input) => {
      expect(input).to.have.value('hello, world!');
    });
  });
});
