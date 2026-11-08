describe('Drupal Canvas overlay UI interactions', () => {
  before(() => {
    cy.drupalCanvasInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it(
    'Component and slot label behavior should work correctly',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.loadURLandWaitForCanvasLoaded();
      cy.get('.canvas--viewport-overlay').as('desktopPreviewOverlay');
      cy.openLayersPanel();
      cy.clickComponentInLayersView('Two Column');

      cy.log(
        'Selecting a "parent" component should show its toolbar, but not the label(s) of its children',
      );
      cy.findByTestId('scale-to-fit').click();
      cy.get('@desktopPreviewOverlay').within(() => {
        // For perf. reasons we only ever render one name tag at a time - because the name tag relies on checking
        // lots of global state e.g. hoveredComponent or isDragging - having a lot of rendered but invisible nameTags is bad.
        cy.findAllByTestId('canvas-component-toolbar').should('have.length', 1);
        cy.findByTestId('canvas-component-toolbar').should(
          'contain.text',
          'Two Column',
        );
        cy.findAllByTestId('canvas-name-tag').should('have.length', 0);
      });

      cy.clickComponentInPreview('Hero');

      cy.log('After selecting a "child" component it should show its toolbar.');
      cy.get('@desktopPreviewOverlay').within(() => {
        cy.findAllByTestId('canvas-component-toolbar').should('have.length', 1);
        cy.findByTestId('canvas-component-toolbar').should(
          'contain.text',
          'Hero',
        );
      });

      cy.log(
        'Now hover a different component. Its name should show, next to the toolbar of the selected component.',
      );
      cy.get('@desktopPreviewOverlay').within(() => {
        cy.findAllByLabelText('Test SDC Image')
          .eq(1)
          .realHover({ scrollBehavior: 'center' });
        cy.findAllByTestId('canvas-name-tag').should('have.length', 1);
        cy.findAllByTestId('canvas-name-tag').should(
          'have.text',
          'Test SDC Image',
        );
        cy.findByTestId('canvas-component-toolbar').should(
          'contain.text',
          'Hero',
        );
      });
    },
  );
});
