describe('Multi-select components', () => {
  before(() => {
    cy.drupalCanvasInstall();
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('canvasUser', 'canvasUser');
    cy.loadURLandWaitForCanvasLoaded();
    cy.openLayersPanel();

    // Make sure we have multiple components visible for testing
    cy.testInIframe(
      '[data-component-id="canvas_test_sdc:my-hero"]',
      (myHeroComponent) => {
        expect(myHeroComponent.length).to.be.at.least(
          2,
          'Need at least 2 Hero components for multi-select tests',
        );
      },
    );
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('should select a single component when clicked', () => {
    // Click the first Hero component
    cy.clickComponentInPreview('Hero', 0);
    cy.previewReady();

    // Check that the component is selected
    cy.getAllComponentsInPreview('Hero')
      .eq(0)
      .should('have.attr', 'data-canvas-selected', 'true');

    // Check that only one component is selected (no multiselect active)
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 1);

    // Check that the single-component panel is shown
    cy.get('[data-testid="canvas-contextual-panel"]').should('exist');
    cy.get('[data-testid="canvas-contextual-panel--settings"]').should('exist');
    cy.findByLabelText('Heading').should('have.value', 'hello, world!');

    cy.location('pathname').should(
      'match',
      /\/editor\/[^/]+\/[^/]+\/component/,
    );
  });

  it('should select multiple components with cmd/meta + click', () => {
    // Click the first component
    cy.clickComponentInPreview('Hero', 0);
    cy.previewReady();

    // URL should contain the component ID for single selection
    cy.location('pathname').should('include', '/component/');

    // Then meta+click the second component
    cy.getAllComponentsInPreview('Hero')
      .eq(1)
      .click({ metaKey: true, force: true });
    cy.previewReady();

    // Both components should be selected
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 2);

    // The multi-select panel should show with count
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('2 items selected')
      .should('be.visible');

    // URL should no longer contain the component ID for multi-selection
    cy.location('pathname').should('not.include', '/component/');
  });

  it('should toggle selection of component with cmd/meta + click', () => {
    // First select two components
    cy.clickComponentInPreview('Hero', 0);
    cy.previewReady();

    cy.getAllComponentsInPreview('Hero')
      .eq(1)
      .click({ metaKey: true, force: true });
    cy.previewReady();

    // Check both are selected
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 2);

    // URL should not contain component ID for multi-selection
    cy.location('pathname').should('not.include', '/component/');

    // Now meta+click one of them again to deselect it
    cy.getAllComponentsInPreview('Hero')
      .eq(1)
      .click({ metaKey: true, force: true });
    cy.previewReady();

    // Only one should remain selected
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 1);

    // Assert that the URL has the correct /component/:componentId in the URL
    cy.location('pathname').should('include', '/component/');

    // Assert that the multi select panel is no longer shown
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('items selected')
      .should('not.exist');
  });

  it('should select components from Layers panel and support multi-selection', () => {
    cy.previewReady();

    // Find and click Hero components in layers view
    cy.findByTestId('canvas-primary-panel').within(() => {
      cy.findAllByText('Hero') // Try to find by text instead of label
        .first()
        .click();
    });

    // Verify a component is selected in preview
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 1);

    // Try to select a second one with meta key
    cy.findByTestId('canvas-primary-panel')
      .findAllByText('Hero')
      .eq(1)
      .click({ metaKey: true, force: true });
    cy.previewReady();

    // Both should be selected in preview
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 2);

    // Multi-select UI should be shown
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('items selected')
      .should('exist');
  });

  it('should sync selection between preview and layers panel', () => {
    cy.previewReady();

    // Now select in preview
    cy.clickComponentInPreview('Hero', 0);
    cy.previewReady();

    // Verify that selection is reflected in the layers panel
    cy.findByTestId('canvas-primary-panel')
      .find('[data-canvas-selected="true"]')
      .should('exist');

    // Select a second component in preview with meta key
    cy.getAllComponentsInPreview('Hero')
      .eq(1)
      .click({ metaKey: true, force: true });
    cy.previewReady();

    // Should find at least 2 selected items in layers panel
    cy.findByTestId('canvas-primary-panel')
      .find('[data-canvas-selected="true"]')
      .should('have.length.at.least', 2);
  });

  it('should prevent selecting parent and child components simultaneously', () => {
    cy.previewReady();

    cy.log('Select the parent');
    cy.findByTestId('canvas-primary-panel').within(() => {
      cy.findByText('Two Column').click();
    });

    cy.findByTestId('canvas-primary-panel')
      .find('[data-canvas-selected="true"]')
      .should('have.length', 1);

    cy.log('Try to multi select one of its children');
    cy.findByTestId('canvas-primary-panel').within(() => {
      cy.findByText('One Column').click({ metaKey: true });
    });

    cy.log(
      'Still should have one item selected, selecting a child replaces the parent in the selection',
    );
    cy.findByTestId('canvas-primary-panel')
      .find('[data-canvas-selected="true"]')
      .should('have.length', 1);

    cy.log('Select a sibling child');
    cy.findByTestId('canvas-primary-panel').within(() => {
      cy.findAllByText('Hero').first().click({ metaKey: true });
    });

    cy.findByTestId('canvas-primary-panel')
      .find('[data-canvas-selected="true"]')
      .should('have.length', 2);

    cy.log('Multi select the parent again');
    cy.findByTestId('canvas-primary-panel').within(() => {
      cy.findByText('Two Column').click({ metaKey: true });
    });

    cy.log(
      'Should have one item selected, selecting a parent replaces the children in the selection',
    );
    cy.findByTestId('canvas-primary-panel')
      .find('[data-canvas-selected="true"]')
      .should('have.length', 1);
  });

  // The operation tests below each restore the baseline layout (3 Heroes, 1
  // Test Code Component, 1 Test SDC Image in column one) before ending, via
  // undo, so tests stay independent even though the site is installed once.

  it('should delete a three-item selection spanning two parents with the Delete key and restore it with one undo', () => {
    cy.getAllComponentsInPreview('Hero').should('have.length', 3);

    cy.log(
      'Hero 0 lives in column one, Heroes 1 and 2 in column two: two parents.',
    );
    cy.clickComponentInPreview('Hero', 0);
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .eq(1)
      .click({ metaKey: true, force: true });
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .eq(2)
      .click({ metaKey: true, force: true });
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 3);

    cy.realPress('{del}');

    cy.log('All three Heroes are removed in one operation.');
    cy.get('#canvasPreviewOverlay').findByLabelText('Hero').should('not.exist');
    cy.waitForElementContentNotInIframe('div', 'hello, world!');

    cy.log('The selection is cleared after deleting.');
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('items selected')
      .should('not.exist');
    cy.location('pathname').should('not.include', '/component/');

    cy.log('A single undo restores the whole batch.');
    cy.realPress(['Meta', 'Z']);
    cy.waitForElementContentInIframe('div', 'hello, world!');
    cy.getAllComponentsInPreview('Hero').should('have.length', 3);
  });

  it('should copy and paste a consecutive selection preserving document order', () => {
    cy.clearLocalStorage();

    cy.log(
      'Column one children in document order: Test SDC Image, Hero, Test Code Component. Click in scrambled order; the pasted group must follow document order, not click order.',
    );
    cy.clickComponentInPreview('Test Code Component');
    cy.previewReady();
    cy.getAllComponentsInPreview('Test SDC Image')
      .first()
      .click({ metaKey: true, force: true });
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .eq(0)
      .click({ metaKey: true, force: true });
    cy.previewReady();
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('3 items selected')
      .should('be.visible');

    cy.realPress(['Meta', 'c']);
    cy.realPress(['Meta', 'v']);

    cy.getAllComponentsInPreview('Hero').should('have.length', 4);
    cy.getAllComponentsInPreview('Test Code Component').should(
      'have.length',
      2,
    );
    cy.getAllComponentsInPreview('Test SDC Image').should('have.length', 2);

    cy.log('The pasted components become the selection.');
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('3 items selected')
      .should('be.visible');

    cy.log(
      'The layers tree lists the pasted group in document order after the originals.',
    );
    cy.get('.primaryPanelContent [aria-label^="Draggable component"]').then(
      ($els) => {
        const names = [...$els].map((el) =>
          el.getAttribute('aria-label').replace('Draggable component ', ''),
        );
        expect(names.join('|')).to.include(
          'Test SDC Image|Hero|Test Code Component|Test SDC Image|Hero|Test Code Component|One Column',
        );
      },
    );

    cy.log('Restore the baseline layout: one undo removes the pasted group.');
    cy.realPress(['Meta', 'Z']);
    cy.getAllComponentsInPreview('Hero').should('have.length', 3);
    cy.getAllComponentsInPreview('Test Code Component').should(
      'have.length',
      1,
    );
    cy.getAllComponentsInPreview('Test SDC Image').should('have.length', 1);
  });

  it('should duplicate a consecutive selection from the context menu', () => {
    cy.log('The two Heroes in column two are adjacent siblings.');
    cy.clickComponentInPreview('Hero', 1);
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .eq(2)
      .click({ metaKey: true, force: true });
    cy.previewReady();
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('2 items selected')
      .should('be.visible');

    cy.getAllComponentsInPreview('Hero').eq(2).rightclick({ force: true });
    cy.findByText('Duplicate 2 items').click();

    cy.getAllComponentsInPreview('Hero').should('have.length', 5);

    cy.log(
      'The duplicates appear directly after the originals and become the selection.',
    );
    cy.getAllComponentsInPreview('Hero')
      .eq(3)
      .should('have.attr', 'data-canvas-selected', 'true');
    cy.getAllComponentsInPreview('Hero')
      .eq(4)
      .should('have.attr', 'data-canvas-selected', 'true');
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('2 items selected')
      .should('be.visible');

    cy.log('Restore the baseline layout: one undo removes both duplicates.');
    cy.realPress(['Meta', 'Z']);
    cy.getAllComponentsInPreview('Hero').should('have.length', 3);
  });

  it('should save a consecutive selection as a pattern', () => {
    cy.clickComponentInPreview('Hero', 1);
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .eq(2)
      .click({ metaKey: true, force: true });
    cy.previewReady();

    cy.get('[data-testid="canvas-contextual-panel"]')
      .findByRole('button', { name: 'Save as Pattern' })
      .click();

    cy.findByLabelText('Pattern name').should(
      'have.value',
      '2 components pattern',
    );
    cy.findByLabelText('Pattern name').clear();
    cy.findByLabelText('Pattern name').type('Two heroes');
    cy.findByText('Add to library').click();
    cy.log('The dialog should close.');
    cy.findByLabelText('Pattern name').should('not.exist');

    cy.openLibraryPanel();
    cy.get('.primaryPanelContent').within(() => {
      cy.findAllByText('Patterns').first().click();
      cy.findByText('Two heroes').should('exist');
    });
  });

  it('should offer batch actions in the context menu on a selection member', () => {
    cy.log('Select all three Heroes.');
    cy.clickComponentInPreview('Hero', 0);
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .eq(1)
      .click({ metaKey: true, force: true });
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .eq(2)
      .click({ metaKey: true, force: true });
    cy.previewReady();

    cy.getAllComponentsInPreview('Hero').eq(1).rightclick({ force: true });
    cy.findByText('3 items selected').should('exist');
    cy.findByText('Copy 3 items').should('exist');
    cy.findByText('Duplicate 3 items').should('exist');

    cy.findByText('Delete 3 items').click();

    cy.log('All three Heroes are removed and the selection is cleared.');
    cy.get('#canvasPreviewOverlay').findByLabelText('Hero').should('not.exist');
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('items selected')
      .should('not.exist');

    cy.log('Restore the baseline layout.');
    cy.realPress(['Meta', 'Z']);
    cy.getAllComponentsInPreview('Hero').should('have.length', 3);
  });

  it('should replace the selection when the context menu opens on a non-member', () => {
    cy.clickComponentInPreview('Hero', 1);
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .eq(2)
      .click({ metaKey: true, force: true });
    cy.previewReady();
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 2);

    cy.getAllComponentsInPreview('Test Code Component')
      .first()
      .rightclick({ force: true });

    cy.log('The single-component menu is shown, not the batch menu.');
    cy.findByText('Delete 2 items').should('not.exist');
    cy.findByText('Move').should('exist');

    cy.get('body').type('{esc}');

    cy.log('The selection becomes just the right-clicked component.');
    cy.getAllComponentsInPreview('Test Code Component')
      .first()
      .should('have.attr', 'data-canvas-selected', 'true');
    cy.getAllComponentsInPreview('Hero')
      .filter('[data-canvas-selected="true"]')
      .should('have.length', 0);
    cy.get('[data-testid="canvas-contextual-panel"]')
      .contains('items selected')
      .should('not.exist');
  });

  it('should disable group actions for a non-adjacent selection while delete stays available', () => {
    cy.log(
      'Test SDC Image and Test Code Component are siblings in column one but not adjacent.',
    );
    cy.clickComponentInPreview('Test SDC Image');
    cy.previewReady();
    cy.getAllComponentsInPreview('Test Code Component')
      .first()
      .click({ metaKey: true, force: true });
    cy.previewReady();

    cy.get('[data-testid="canvas-contextual-panel"]').within(() => {
      cy.contains('2 items selected').should('exist');
      cy.findByRole('button', { name: 'Copy' }).should('be.disabled');
      cy.findByRole('button', { name: 'Save as Pattern' }).should(
        'be.disabled',
      );
      cy.contains(
        'Actions are only available when selecting adjacent items',
      ).should('exist');
    });

    cy.getAllComponentsInPreview('Test SDC Image')
      .first()
      .rightclick({ force: true });
    cy.findByText('Copy 2 items').should('have.attr', 'data-disabled');
    cy.findByText('Duplicate 2 items').should('have.attr', 'data-disabled');

    cy.log('Delete is not subject to the adjacency constraint.');
    cy.findByText('Delete 2 items').click();
    cy.get('#canvasPreviewOverlay')
      .findByLabelText('Test Code Component')
      .should('not.exist');
    cy.get('#canvasPreviewOverlay')
      .findByLabelText('Test SDC Image')
      .should('not.exist');

    cy.log('Restore the baseline layout.');
    cy.realPress(['Meta', 'Z']);
    cy.getAllComponentsInPreview('Test Code Component').should(
      'have.length',
      1,
    );
    cy.getAllComponentsInPreview('Test SDC Image').should('have.length', 1);
  });
});
