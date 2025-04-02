import { onlyOn } from '@cypress/skip-test';

onlyOn('headed', () => {
  describe('multivalue widget', () => {
    before(() => {
      cy.drupalXbInstall();
      cy.drupalInstallModule('xb_test_article_fields', true);
    });

    beforeEach(() => {
      cy.drupalSession();
      cy.drupalLogin('xbUser', 'xbUser');
    });
    after(() => {
      cy.drupalUninstall();
    });

    it('can use a multivalue widget in the page data form', () => {
      cy.loadURLandWaitForXBLoaded();
      cy.findByTestId('xb-page-data-form').as('entityForm');

      // Confirms the count and visibility of the weight select dropdowns.
      // This is run many times during the test as these elements being visible
      // when they shouldn't be is a useful canary for identifying AJAX problems.
      const confirmWeightSelectCount = (count, visible = false) => {
        cy.get(
          '[data-drupal-selector="edit-field-xbt-unlimited-text"] .delta-order button',
        ).should(($buttons) => {
          expect($buttons).to.have.length(count);
          $buttons.each((index, button) => {
            if (visible) {
              expect(Cypress.$(button).is(':visible')).to.be.true;
            } else {
              expect(Cypress.$(button).is(':visible')).to.be.false;
            }
          });
        });
      };

      // Confirms the contents of every text input in the table.
      const confirmTextInputs = (inputContent) => {
        cy.get('[data-drupal-selector="edit-field-xbt-unlimited-text"]').should(
          ($table) => {
            const textInputs = $table.find('input[type="text"]');
            expect(textInputs).to.have.length(inputContent.length);
            textInputs.each((itemIndex, el) => {
              expect(el.value).to.equal(inputContent[itemIndex]);
            });
          },
        );
      };

      const items = [
        'item one',
        'item two',
        'item three',
        'item four',
        'item five',
      ];
      items.forEach((item, index) => {
        cy.get(
          '[data-drupal-selector="edit-field-xbt-unlimited-text"] input[type="text"]',
        ).should('have.length', index + 1);
        cy.get(
          '[data-drupal-selector="edit-field-xbt-unlimited-text"] input[type="text"]',
        )
          .eq(index)
          .type(item);
        if (index === items.length - 1) {
          cy.get('@entityForm').recordFormBuildId();
        }
        cy.get('[value="Add another item"]').click({ force: true });
        cy.get(
          '[data-drupal-selector="edit-field-xbt-unlimited-text"] input[type="text"]',
        ).should('have.length', index + 2);
        cy.get('[data-drupal-selector="edit-field-xbt-unlimited-text"]').should(
          ($table) => {
            $table.find('input[type="text"]').each((itemIndex, el) => {
              if (itemIndex <= index) {
                expect(
                  el.value,
                  `Input ${itemIndex} should equal ${items}`,
                ).to.equal(items[itemIndex]);
              } else {
                expect(
                  el.value,
                  `The final item, ${itemIndex}, should be empty`,
                ).to.equal('');
              }
            });
          },
        );
        confirmWeightSelectCount(index + 2);
      });

      cy.get('@entityForm').shouldHaveUpdatedFormBuildId(10000);

      cy.log('Remove "item three"');
      cy.get(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] [value="Remove"]',
      )
        .eq(2)
        .click();
      cy.get(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] input[type="text"]',
      ).should('have.length', 5);
      confirmWeightSelectCount(5);

      confirmTextInputs(['item one', 'item two', 'item four', 'item five', '']);

      cy.get('[name="field_xbt_unlimited_text[3][value]"]').click();

      cy.log('Move "item 5" to the top');
      cy.get(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] tr.draggable:nth-child(4) .handle',
      ).realDnd(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] tr.draggable:nth-child(1) [title="Change order"]',
        {
          position: 'top',
          force: true,
        },
      );
      cy.get(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] input[type="text"]',
      )
        .first()
        .should('have.value', 'item five');
      confirmTextInputs(['item five', 'item one', 'item two', 'item four', '']);
      confirmWeightSelectCount(5);

      cy.log(
        'Move an item that has been entered but "Add new item" is not clicked yet',
      );

      cy.get(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] input[type="text"]',
      )
        .eq(4)
        .type('Put me at the start!');
      confirmTextInputs([
        'item five',
        'item one',
        'item two',
        'item four',
        'Put me at the start!',
      ]);
      confirmWeightSelectCount(5);

      cy.get(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] tr.draggable:nth-child(5) .handle',
      ).realDnd(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] tr.draggable:nth-child(1) [title="Change order"]',
        {
          position: 'top',
          force: true,
        },
      );
      cy.get(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] input[type="text"]',
      )
        .first()
        .should('have.value', 'Put me at the start!');

      confirmTextInputs([
        'Put me at the start!',
        'item five',
        'item one',
        'item two',
        'item four',
      ]);
      confirmWeightSelectCount(5);

      cy.findByText('Hide row weights').should('not.exist');
      cy.findByText('Show row weights').click();
      cy.findByText('Hide row weights').should('exist');

      //
      confirmWeightSelectCount(5, true);

      cy.get(
        '[data-drupal-selector="edit-field-xbt-unlimited-text"] .handle',
      ).should(($handles) => {
        expect($handles).to.have.length(5);
        $handles.each((index, handle) => {
          expect(
            Cypress.$(handle).is(':visible'),
            `Drag handle ${index} is hidden`,
          ).to.be.false;
        });
      });
    });
  });
});
