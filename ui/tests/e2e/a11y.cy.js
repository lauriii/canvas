function terminalLog(violations) {
  cy.task(
    'log',
    `${violations.length} accessibility violation${
      violations.length === 1 ? '' : 's'
    } ${violations.length === 1 ? 'was' : 'were'} detected`,
  );
  // pluck specific keys to keep the table readable
  const violationData = violations.map(
    ({ id, impact, description, nodes }) => ({
      id,
      impact,
      description,
      nodes: nodes.length,
    }),
  );

  cy.task('table', violationData);
}

describe('UI a11y Scan', () => {
  before(() => {
    cy.drupalXbInstall();
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('a11y scan without any interaction', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();
    cy.injectAxe();
    // @todo there are several a11y rules not being checked in order for the
    // test to pass. These need to be fixed.
    cy.checkA11y(
      'body',
      {
        rules: {
          'aria-required-children': { enabled: false },
          'button-name': { enabled: false },
          region: { enabled: false },
          'scrollable-region-focusable': { enabled: false },
          'color-contrast': { enabled: false },
        },
      },
      terminalLog,
    );
  });

  it('a11y scan library panel', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();
    cy.openLibraryPanel();
    cy.injectAxe();
    // @todo there are several a11y rules not being checked in order for the
    // test to pass. These need to be fixed.
    cy.checkA11y(
      'body',
      {
        rules: {
          'aria-required-children': { enabled: false },
          'button-name': { enabled: false },
          region: { enabled: false },
          'scrollable-region-focusable': { enabled: false },
          'color-contrast': { enabled: false },
        },
      },
      terminalLog,
    );
  });

  it('a11y scan primary panel', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();
    cy.get('.primaryPanelContent').should('exist');

    cy.injectAxe();
    // @todo there are several a11y rules not being checked in order for the
    // test to pass. These need to be fixed.
    cy.checkA11y(
      'body',
      {
        rules: {
          'aria-required-children': { enabled: false },
          'button-name': { enabled: false },
          region: { enabled: false },
          'scrollable-region-focusable': { enabled: false },
          'color-contrast': { enabled: false },
        },
      },
      terminalLog,
    );
  });

  it('a11y scan open props edit form', () => {
    cy.drupalLogin('xbUser', 'xbUser');
    cy.loadURLandWaitForXBLoaded();
    cy.findByTestId('xb-contextual-panel--page-data').should(
      'have.attr',
      'data-state',
      'active',
    );
    cy.clickComponentInPreview('Hero');

    cy.findByTestId('xb-contextual-panel--page-data').should(
      'have.attr',
      'data-state',
      'inactive',
    );
    cy.findByTestId('xb-contextual-panel--settings').should(
      'have.attr',
      'data-state',
      'active',
    );

    cy.injectAxe();
    // @todo there are several a11y rules not being checked in order for the
    // test to pass. These need to be fixed.
    cy.checkA11y(
      'body',
      {
        rules: {
          'aria-required-children': { enabled: false },
          'color-contrast': { enabled: false },
          'button-name': { enabled: false },
          region: { enabled: false },
          'scrollable-region-focusable': { enabled: false },
          'aria-allowed-attr': { enabled: false },
          'aria-dialog-name': { enabled: false },
        },
      },
      terminalLog,
    );
  });
});
