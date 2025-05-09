describe('Drag and drop functionality in the Layers menu', () => {
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

  function preparePage() {
    cy.loadURLandWaitForXBLoaded();

    cy.get('#xbPreviewOverlay .xb--viewport-overlay')
      .first()
      .as('desktopPreviewOverlay');
    cy.get('.primaryPanelContent').as('layersTree');

    // TODO don't even have this image here in the first place! For now, we delete it
    cy.clickComponentInPreview('Image', 1);
    cy.realType('{del}');

    // Open the layers in the Tree.
    cy.get('@layersTree')
      .findByLabelText('Two Column')
      .findByLabelText('Expand component tree')
      .click();
    cy.get('@layersTree')
      .findAllByLabelText('Column One')
      .first()
      .findByLabelText('Expand slot')
      .click();
    cy.get('@layersTree').findAllByText('Image').should('be.visible');
    cy.get('@layersTree').findAllByText('Hero').should('be.visible');
  }

  function assertInitialPageState() {
    // Before dragging, check that the component is in the column one slot in the layers menu and preview.
    cy.log('Image component exists in the first slot in the layers panel');
    cy.get('@layersTree').within(() => {
      cy.findAllByText('Column One')
        .first()
        .closest('.xb--collapsible-root')
        .within(() => {
          cy.findAllByText('Image');
        });
    });

    cy.log('Image component exists in the first slot in the overlay UI');
    cy.get('@desktopPreviewOverlay').within(() => {
      cy.findByLabelText('Column One (Two Column)').within(() => {
        cy.findByLabelText('Image');
      });
    });
  }

  function assertPageStateAfterFirstDrag() {
    cy.log(
      'Image component no longer exists in the first slot in the layers panel and is now a sibling of Two Column',
    );
    cy.get('@layersTree').within(() => {
      cy.findAllByText('Column One')
        .first()
        .within(() => {
          cy.findAllByText('Image').should('not.exist');
        });
      cy.checkSiblings(
        cy.findByLabelText('Two Column'),
        cy.findByLabelText('Image'),
      );
    });

    cy.log(
      'Image component no longer exists in the first slot in the overlay UI and is now a sibling of Two Column',
    );
    cy.get('@desktopPreviewOverlay').within(() => {
      cy.findByLabelText('Column One (Two Column)').within(() => {
        cy.findByLabelText('Image').should('not.exist');
      });
      cy.checkSiblings(
        cy.findByLabelText('Two Column'),
        cy.findByLabelText('Image'),
      );
    });
  }

  it('Drag a component from the column one slot to the root level then to the column two slot', () => {
    preparePage();
    assertInitialPageState();

    cy.log('Drag image component out of the slot and to the root level.');
    cy.get('.primaryPanelContent span').contains('Image').should('exist');
    cy.get('@layersTree').within(() => {
      cy.get('#layer-static-image-udf7d-name span').realDnd(
        '[data-xb-slot-id="content"]',
        {
          position: 'top',
          preReleaseWait: 500,
        },
      );
    });
    assertPageStateAfterFirstDrag();
    // This is the "Image" inside the layers tree
    cy.get(
      '.primaryPanelContent [data-xb-uuid="static-image-udf7d"][data-state]',
    ).should('exist');
    cy.get(
      '.primaryPanelContent [data-xb-uuid="two-column-uuid/column_two"] [aria-label="Expand slot"]',
    ).should('exist');
    cy.get(
      '.primaryPanelContent [data-xb-uuid="two-column-uuid/column_two"] [aria-label="Expand slot"]',
    ).click();
    // This confirms the slot is expanded.
    cy.get(
      '.primaryPanelContent [data-xb-uuid="static-static-card2df"]',
    ).should('exist');

    // Next, drag the image component from the root level to column two's slot.
    cy.get(
      '.primaryPanelContent [data-xb-uuid="static-image-udf7d"][data-state]',
    ).realDnd(
      '.primaryPanelContent [data-xb-uuid="two-column-uuid/column_two"][data-xb-type="slot"]',
      {
        position: 'center',
        scrollBehavior: false,
        force: true,
      },
    );

    // After dragging, check that the image is now in column two's slot in the layers menu and preview.
    cy.log('Image component exists in the second slot in the layers panel');
    cy.get(
      '.primaryPanelContent [data-xb-slot-id="two-column-uuid/column_two"] span',
    )
      .contains('Image')
      .should('have.length', 1);

    cy.log('Image component exists in the column_two slot in the overlay UI');
    // Ensure there is only one Image in each preview and we didn't clone it or anything!
    cy.get(
      '#xbPreviewOverlay .xb--viewport-overlay [aria-label="Column Two (Two Column)"] [data-xb-component-id="sdc.experience_builder.image"]',
    ).should('have.length', 2);
  });

  it('Check undo/redo works with the layers menu', () => {
    preparePage();
    assertInitialPageState();

    cy.log('Drag image component out of the slot and to the root level.');
    cy.get('@layersTree').within(() => {
      cy.get('#layer-static-image-udf7d-name span').realDnd(
        '[data-xb-slot-id="content"]',
        {
          position: 'top',
          preReleaseWait: 900,
        },
      );
    });
    assertPageStateAfterFirstDrag();

    // Hit the undo button.
    cy.realPress(['Meta', 'Z']);
    assertInitialPageState();

    // Hit Redo
    cy.realPress(['Meta', 'Shift', 'Z']);
    assertPageStateAfterFirstDrag();
  });
});
