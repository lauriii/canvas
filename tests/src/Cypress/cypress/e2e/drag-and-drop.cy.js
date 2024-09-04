describe(
  'Drag and drop functionality in the Layers menu',
  { testIsolation: false },
  () => {
    before(() => {
      cy.drupalXbInstall();
    });

    after(() => {
      cy.drupalUninstall();
    });

    beforeEach(() => {
      cy.drupalSession();
      cy.drupalLogin('xbUser', 'xbUser');
    });

    // @todo: Remove cy.wait usages and instead wait for a change in the UI.
    //    https://www.drupal.org/project/experience_builder/issues/3470490
    it('Drag a component from the column one slot to the root level then to the column two slot', () => {
      cy.loadURLandWaitForXBLoaded();
      cy.get('.primaryMenuContent').find('.treeItem[data-xb-uuid="two-column-uuid"]').find('button').click();
      cy.get('.primaryMenuContent').find('.treeItem[data-xb-uuid="two-column-uuid-slot-column_one"]').find('button').click();

      // Before dragging, check that the component is in the column one slot in the layers menu and preview.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('exist');
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').siblings('.treeItem[data-xb-uuid="two-column-uuid"]').should('not.exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').siblings('[data-xb-uuid="two-column-uuid"]').should('not.exist');

      // Drag image component out of the slot and to the root level.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').realDnd('.rootNodeWrapper[data-xb-uuid="root"]');
      cy.wait(1000);

      // After dragging, check that the component is now in the root level of the layers menu and preview by checking that the two column SDC is a sibling.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('not.exist');
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').siblings('.treeItem[data-xb-uuid="two-column-uuid"]').should('exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('not.exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').siblings('[data-xb-uuid="two-column-uuid"]').should('exist');

      // Next, drag the image component from the root level to column two's slot.
      cy.get('.primaryMenuContent').find('.treeItem[data-xb-uuid="two-column-uuid-slot-column_two"]').find('button').click();
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').realDnd('[data-state="open"][data-xb-uuid="two-column-uuid-slot-column_two"]');
      cy.wait(1000);

      // After dragging, check that the component is now in column two's slot in the layers menu and preview.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_two"]').should('exist');
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').siblings('.treeItem[data-xb-uuid="two-column-uuid"]').should('not.exist');
      // Also check the preview updated.
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_two"]').should('exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').siblings('[data-xb-uuid="two-column-uuid"]').should('not.exist');
    });

    it('Check undo/redo works with the layers menu', () => {
      cy.loadURLandWaitForXBLoaded();
      cy.get('.primaryMenuContent').find('.treeItem[data-xb-uuid="two-column-uuid"]').find('button').click();
      cy.get('.primaryMenuContent').find('.treeItem[data-xb-uuid="two-column-uuid-slot-column_one"]').find('button').click();
      // Before dragging, check that the component is in the column one slot in the layers menu.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('exist');
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').siblings('.treeItem[data-xb-uuid="two-column-uuid"]').should('not.exist');
      // Also check the preview.
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').siblings('[data-xb-uuid="two-column-uuid"]').should('not.exist');

      // Drag image component out of the slot and to the root level.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').realDnd('.rootNodeWrapper[data-xb-uuid="root"]');
      cy.wait(1000);

      // After dragging, check that the component is now in the root level of the layers menu and preview by checking that the two column SDC is a sibling.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('not.exist');
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').siblings('.treeItem[data-xb-uuid="two-column-uuid"]').should('exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('not.exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').siblings('[data-xb-uuid="two-column-uuid"]').should('exist');

      // Hit the undo button.
      cy.get('button[aria-label="Undo"]').click();
      cy.wait(1000);
      // Check that the component is back in its original column one slot in the layers menu and preview.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('exist');
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').siblings('.treeItem[data-xb-uuid="two-column-uuid"]').should('not.exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').siblings('[data-xb-uuid="two-column-uuid"]').should('not.exist');

      // Hit Redo
      cy.get('button[aria-label="Redo"]').click();
      cy.wait(1000);
      // Check that the component is back in the root level of the layers menu and preview.
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('not.exist');
      cy.get('.treeItem[data-xb-uuid="dynamic-image-udf7d"]').siblings('.treeItem[data-xb-uuid="two-column-uuid"]').should('exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').parent('[data-xb-uuid="two-column-uuid-slot-column_one"]').should('not.exist');
      cy.getIframeBody().find('[data-xb-uuid="dynamic-image-udf7d"]').siblings('[data-xb-uuid="two-column-uuid"]').should('exist');
    });
  },
);
