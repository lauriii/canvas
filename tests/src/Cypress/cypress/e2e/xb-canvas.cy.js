describe(
  'Experience Builder canvas controls/navigation',
  { testIsolation: false },
  () => {
    before(() => {
      cy.drupalXbInstall();
    });

    after(() => {
      cy.drupalUninstall();
    });

    beforeEach(() => {
      cy.drupalLogin('xbUser', 'xbUser');
    });

    it('Can zoom the canvas with the Zoom Controls', () => {
      cy.loadURLandWaitForXBLoaded();

      cy.log('Zoom by clicking the buttons');
      cy.findByLabelText('Zoom in').click();
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(1.1, 0, 0, 1.1, 0, 0)',
      );
      cy.findByText('110%');
      cy.findByLabelText('Zoom in').click();
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(1.25, 0, 0, 1.25, 0, 0)',
      );
      cy.findByText('125%');
      cy.findByLabelText('Zoom out').click();
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(1.1, 0, 0, 1.1, 0, 0)',
      );
      cy.findByText('110%');

      cy.log(
        "The selected value in the drop down should match the zoom level if it's one of the available steps",
      );
      cy.findByLabelText('Select zoom level').click();
      cy.findByTestId('zoom-select-menu')
        .get('[role="option"][aria-selected="true"]')
        .should('have.text', '110%');
      cy.get('html').click(); // close the select menu

      Array(4)
        .fill()
        .forEach(() => {
          cy.findByLabelText('Zoom out').click();
        });
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(0.75, 0, 0, 0.75, 0, 0)',
      );
      cy.findByText('75%');

      cy.log('Zoom by adjusting the slider');
      cy.findByLabelText('Canvas zoom level').setRangeValue('200');
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(2, 0, 0, 2, 0, 0)',
      );
      cy.findByText('200%');

      cy.findByLabelText('Canvas zoom level').setRangeValue('100');
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(1, 0, 0, 1, 0, 0)',
      );
      cy.findByText('100%');

      cy.log(
        "The selected value in the drop down should match the zoom level if it's one of the available steps",
      );
      cy.findByLabelText('Select zoom level').click();
      cy.findByTestId('zoom-select-menu')
        .get('[role="option"][aria-selected="true"]')
        .should('have.text', '100%');
      cy.get('html').click(); // close the select menu

      cy.log(
        'Zoom to non-step zoom level and ensure nothing is selected in the drop down.',
      );
      cy.findByLabelText('Canvas zoom level').setRangeValue('101');
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(1.01, 0, 0, 1.01, 0, 0)',
      );
      cy.findByText('101%');
      cy.findByLabelText('Select zoom level').click();
      cy.findByTestId('zoom-select-menu')
        .get('[role="option"][aria-selected="true"]')
        .should('not.exist');
      cy.get('html').click(); // close the select menu
    });

    it('Can zoom the canvas with the keyboard', () => {
      cy.loadURLandWaitForXBLoaded();

      cy.log('Zoom in by pressing + key');
      cy.get('html').type('+');
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(1.1, 0, 0, 1.1, 0, 0)',
      );
      cy.findByText('110%');

      cy.log(
        "The selected value in the drop down should match the zoom level if it's one of the available steps",
      );
      cy.findByLabelText('Select zoom level').click();
      cy.findByTestId('zoom-select-menu')
        .get('[role="option"][aria-selected="true"]')
        .should('have.text', '110%');
      cy.get('html').click(); // close the select menu

      cy.log('Zoom out by pressing - key (4 times)');
      cy.get('html').type('----');
      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(0.75, 0, 0, 0.75, 0, 0)',
      );
      cy.findByText('75%');

      cy.log(
        "The selected value in the drop down should match the zoom level if it's one of the available steps",
      );
      cy.findByLabelText('Select zoom level').click();
      cy.findByTestId('zoom-select-menu')
        .get('[role="option"][aria-selected="true"]')
        .should('have.text', '75%');
      cy.get('html').click(); // close the select menu
    });

    it('Can zoom the canvas with the mouse', () => {
      cy.loadURLandWaitForXBLoaded();

      cy.log(
        'Zoom out by holding ctrl and using the mousewheel (or pinch on track pad)',
      );

      cy.findByTestId('canvasElement').click({ force: true }); // Hold down the Control key
      cy.findByTestId('canvasElement').triggerMouseWheelWithCtrl(-10); // Simulate mouse wheel roll

      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(1.1, 0, 0, 1.1, 0, 0)',
      );
      cy.findByText('110%');

      cy.log(
        'Zoom in by holding ctrl and using the mousewheel (or pinch on track pad)',
      );

      cy.findByTestId('canvasElement').click({ force: true }); // Hold down the Control key
      cy.findByTestId('canvasElement').triggerMouseWheelWithCtrl(20); // Simulate mouse wheel roll

      cy.findByTestId('canvasElement').should(
        'have.css',
        'transform',
        'matrix(0.9, 0, 0, 0.9, 0, 0)',
      );
      cy.findByText('90%');
    });
  },
);
