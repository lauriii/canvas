const testMediaLibraryInEntityForm = (cy, loadOptions = {}, title) => {
  const iterations = [
    {
      removeText: 'Remove The bones are their money',
      selectNewText: 'Select Sorry I resemble a dog',
      removeAriaLabel: 'Remove Sorry I resemble a dog',
      expectedAlt: 'My barber may have been looking at a picture of a dog',
    },
    {
      removeText: 'Remove Sorry I resemble a dog',
      selectNewText: 'Select The bones are their money',
      removeAriaLabel: 'Remove The bones are their money',
      expectedAlt: 'The bones equal dollars',
    },
    {
      removeText: 'Remove The bones are their money',
      selectNewText: 'Select Sorry I resemble a dog',
      removeAriaLabel: 'Remove Sorry I resemble a dog',
      expectedAlt: 'My barber may have been looking at a picture of a dog',
    },
  ];

  cy.drupalLogin('canvasUser', 'canvasUser');

  cy.loadURLandWaitForCanvasLoaded(loadOptions);

  cy.findByTestId('canvas-contextual-panel--page-data').should(
    'have.attr',
    'data-state',
    'active',
  );
  const entityFormSelector = '[data-testid="canvas-page-data-form"]';
  cy.findByTestId('canvas-page-data-form').as('entityForm');
  // Log all ajax form requests to help with debugging.
  cy.intercept('POST', '**/canvas/api/v0/form/content-entity/**');

  // Perform media operations.
  iterations.forEach((step, ix) => {
    cy.log(`Iteration ${ix + 1}: start`);
    cy.findByRole('dialog').should('not.exist');
    cy.get('@entityForm').findByAltText(step.expectedAlt).should('not.exist');
    if (ix > 0) {
      const removeIt = `[class*="contextualPanel"] .js-media-library-selection  [aria-label="${step.removeText}"][data-form-id="page_data_form"][data-once="drupal-ajax"][data-ajax="true"]:not(.visually-hidden)`;
      cy.reasonableWait();
      cy.get(removeIt).click();
      cy.get(removeIt).should('not.exist', { timeout: 10000 });
      cy.log(`Iteration ${ix + 1}: ${step.removeText} complete`);
    }
    const addIt = `[data-form-id="page_data_form"] .js-media-library-widget .js-media-library-open-button[data-once="drupal-ajax"]`;
    cy.get(addIt).should(($button) => {
      expect($button).to.exist;
      // Custom check for disabled to account for the disabled attribute
      // leveraging truthy values instead of strict boolean.
      const disabled = $button.attr('disabled');
      const isDisabled =
        disabled !== undefined && disabled !== false && disabled !== 'false';
      expect(isDisabled, 'Button should not be disabled').to.be.false;
    });
    cy.reasonableWait();
    cy.get(addIt).first().click();

    // The first time the media dialog opens there are a lot of CSS files to
    // load, and it can take more than the default timeout of 4s.
    cy.findByRole('dialog', { timeout: 10000 }).as('dialog');
    cy.reasonableWait();
    cy.get('@dialog').findByLabelText(step.selectNewText).check();
    cy.reasonableWait();
    cy.get('@dialog')
      .findByRole('button', {
        name: 'Insert selected',
      })
      .click();
    cy.findByRole('dialog').should('not.exist');
    cy.get('@entityForm').findByAltText(step.expectedAlt).should('exist');
    cy.get('@entityForm')
      .findByRole('button', { name: step.removeAriaLabel })
      .should('exist');
    cy.log(`Iteration ${ix + 1}: Adding ${step.expectedAlt} complete`);
  });

  // Add a new component which should trigger opening the component instance form
  // in the contextual panel.
  cy.openLibraryPanel();
  cy.get('.primaryPanelContent').should('contain.text', 'Components');
  cy.insertComponent({ name: 'Hero' });
  cy.findByTestId('canvas-contextual-panel').should('exist');
  cy.get(
    '[class*="contextualPanel"] [data-drupal-selector="component-instance-form"]',
  ).within(() => {
    cy.findAllByLabelText('Heading').should('exist');
  });
  const lastStep = iterations.pop();

  // Switch back to entity edit form.
  cy.findByTestId('canvas-contextual-panel--page-data').click();
  // It can take a bit for the entity form to load, so let's give it a bit
  // longer.
  cy.get('@entityForm')
    .findByAltText(lastStep.expectedAlt, { timeout: 10000 })
    .should('exist');
  cy.get('@entityForm')
    .findByRole('button', { name: lastStep.removeAriaLabel })
    .should('exist');

  // Switch to full screen preview.
  cy.findByText('Preview').click();
  cy.findByText('Exit Preview').click();
  cy.get('@entityForm')
    .findByAltText(lastStep.expectedAlt, { timeout: 10000 })
    .should('exist');
  cy.get('@entityForm')
    .findByRole('button', { name: lastStep.removeAriaLabel })
    .should('exist');

  cy.publishAllPendingChanges(title);

  // Reload the page and ensure the saved value persists.
  cy.loadURLandWaitForCanvasLoaded({ ...loadOptions, clearAutoSave: false });
  // It can take a bit for the entity form to load, so let's give it a bit
  // longer.
  cy.get('@entityForm')
    .findByAltText(lastStep.expectedAlt, { timeout: 10000 })
    .should('exist');
  cy.get('@entityForm')
    .findByRole('button', { name: lastStep.removeAriaLabel })
    .should('exist');
};

describe('Media Library In Entity (page data) Form', () => {
  before(() => {
    cy.drupalCanvasInstall([], {}, ['administer nodes']);
  });

  beforeEach(() => {
    cy.drupalSession();
    // A larger viewport makes it easier to debug in the test runner app.
    cy.viewport(2000, 1000);
  });

  after(() => {
    cy.drupalUninstall();
  });

  it(
    'Can open the media library widget on a page data entity form',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      testMediaLibraryInEntityForm(
        cy,
        { url: 'canvas/editor/canvas_page/2' },
        'Empty Page',
      );
    },
  );

  it(
    'Can open the media library widget on an article entity form',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      testMediaLibraryInEntityForm(
        cy,
        { url: 'canvas/editor/node/2' },
        'I am an empty node',
      );
    },
  );
});
