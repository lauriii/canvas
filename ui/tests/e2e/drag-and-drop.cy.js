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
      .findByText('Two Column')
      .parents('.treeItem')
      .findByLabelText('Expand component tree')
      .click();
    cy.get('@layersTree')
      .findAllByText('Column One')
      .first()
      .parents('.treeItem')
      .findByLabelText('Expand component tree')
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
      cy.findByLabelText('Two Column: Column One').within(() => {
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
        .closest('.xb--collapsible-root')
        .within(() => {
          cy.findAllByText('Image').should('not.exist');
        });
      cy.checkSiblings(
        cy.findByLabelText('Two Column').parents('.xb--collapsible-root'),
        cy.findByLabelText('Image'),
      );
    });

    cy.log(
      'Image component no longer exists in the first slot in the overlay UI and is now a sibling of Two Column',
    );
    cy.get('@desktopPreviewOverlay').within(() => {
      cy.findByLabelText('Two Column: Column One').within(() => {
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
    cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').realDnd(
      '.rootDropZone[data-xb-type="region"]',
    );

    assertPageStateAfterFirstDrag();

    // Next, drag the image component from the root level to column two's slot.
    cy.get('@layersTree').within(() => {
      cy.findAllByText('Column Two')
        .parents('.treeItem')
        .findByLabelText('Expand component tree')
        .click();
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').realDnd(
        '[data-xb-uuid="dynamic-static-card2df"]',
        { position: 'bottom' },
      );
    });

    // After dragging, check that the image is now in column two's slot in the layers menu and preview.
    cy.log('Image component exists in the second slot in the layers panel');
    cy.get('@layersTree').within(() => {
      cy.findAllByText('Column Two')
        .closest('.xb--collapsible-root')
        .within(() => {
          cy.findAllByText('Image');
        });
      // Ensure there is only one Image and we didn't clone it or anything!
      cy.findAllByLabelText('Image').should('have.length', 1);
    });

    cy.log('Image component exists in the column_two slot in the overlay UI');
    cy.get('@desktopPreviewOverlay').within(() => {
      cy.findByLabelText('Two Column: Column Two').within(() => {
        cy.findByLabelText('Image');
      });
      // Ensure there is only one Image and we didn't clone it or anything!
      cy.findAllByLabelText('Image').should('have.length', 1);
    });
  });

  it('Check undo/redo works with the layers menu', () => {
    preparePage();
    assertInitialPageState();

    cy.log('Drag image component out of the slot and to the root level.');
    cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').realDnd(
      '.rootDropZone[data-xb-type="region"]',
    );
    assertPageStateAfterFirstDrag();

    // Hit the undo button.
    cy.get('button[aria-label="Undo"]').click();
    assertInitialPageState();

    // Hit Redo
    cy.get('button[aria-label="Redo"]').click();
    assertPageStateAfterFirstDrag();
  });
});
