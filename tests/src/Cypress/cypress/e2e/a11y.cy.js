function terminalLog(violations) {
  cy.task(
    'log',
    `${violations.length} accessibility violation${
      violations.length === 1 ? '' : 's'
    } ${violations.length === 1 ? 'was' : 'were'} detected`
  )
  // pluck specific keys to keep the table readable
  const violationData = violations.map(
    ({ id, impact, description, nodes }) => ({
      id,
      impact,
      description,
      nodes: nodes.length
    })
  )

  cy.task('table', violationData)
}

describe('UI a11y Scan', () => {
  before( () => {
    cy.drupalXbInstall()
  });

  after(() => {
    cy.drupalUninstall()
  })

  beforeEach(() => {
    cy.drupalSession();
  });

  it('a11y scan without any interaction', () => {
    cy.drupalLogin('xbUser', 'xbUser')
    cy.drupalRelativeURL('xb/node/1')
    cy.waitForElementInIframe('[data-xb-type="experience_builder:image"]')
    cy.injectAxe();
    // @todo there are several a11y rules not being checked in order for the
    // test to pass. These need to be fixed.
    cy.checkA11y('body', {
      rules: {
        'aria-required-children': { enabled: false },
        'button-name': { enabled: false },
        'region': { enabled: false },
        'scrollable-region-focusable': { enabled: false },
      },
    }, terminalLog);
  })
  it('a11y scan open first left drawer', () => {
    cy.drupalLogin('xbUser', 'xbUser')
    cy.drupalRelativeURL('xb/node/1')
    cy.waitForElementInIframe('[data-xb-type="experience_builder:image"]')
    cy.get('[data-radix-menubar-content]').should('have.length', 0)
    cy.get('[data-hover-overlay="addElement"]').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 1)

    cy.injectAxe();
    // @todo there are several a11y rules not being checked in order for the
    // test to pass. These need to be fixed.
    cy.checkA11y('body', {
      rules: {
        'aria-required-children': { enabled: false },
        'button-name': { enabled: false },
        'region': { enabled: false },
        'scrollable-region-focusable': { enabled: false },
      },
    }, terminalLog);
  })
  it('a11y scan open secondary left drawer', () => {
    cy.drupalLogin('xbUser', 'xbUser')
    cy.drupalRelativeURL('xb/node/1')
    cy.waitForElementInIframe('[data-xb-type="experience_builder:image"]')
    cy.get('[data-radix-menubar-content]').should('have.length', 0)
    cy.get('[data-hover-overlay="addElement"]').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 1)
    cy.get('[role="menuitem"][aria-expanded="false"]').contains('Default components').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 2)

    cy.injectAxe();
    // @todo there are several a11y rules not being checked in order for the
    // test to pass. These need to be fixed.
    cy.checkA11y('body', {
      rules: {
        'aria-required-children': { enabled: false },
        'button-name': { enabled: false },
        'region': { enabled: false },
        'scrollable-region-focusable': { enabled: false },
      },
    }, terminalLog);
  })
  it('a11y scan open props edit form', () => {
    cy.drupalLogin('xbUser', 'xbUser')
    cy.drupalRelativeURL('xb/node/1')
    cy.waitForElementInIframe('[data-xb-type="experience_builder:image"]')
    cy.get('[data-radix-menubar-content]').should('have.length', 0)
    cy.get('[data-hover-overlay="addElement"]').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 1)
    cy.get('[role="menuitem"][aria-expanded="false"]').contains('Default components').click()
    cy.get('[data-radix-menubar-content]').should('have.length', 2)
    cy.get('[class*="contextualPanel"]').should('not.exist')
    cy.getIframeBody().find('[data-component-id="experience_builder:my-hero"] h1')
      .first()
      .trigger('click')
    cy.get('[class*="contextualPanel"] [data-drupal-selector="component-props-form"].component-props-form').should('exist')

    cy.injectAxe();
    // @todo there are several a11y rules not being checked in order for the
    // test to pass. These need to be fixed.
    cy.checkA11y('body', {
      rules: {
        'aria-required-children': { enabled: false },
        'button-name': { enabled: false },
        'region': { enabled: false },
        'scrollable-region-focusable': { enabled: false },
        'aria-allowed-attr': { enabled: false },
        'aria-dialog-name': { enabled: false },
      },
    }, terminalLog);
  })
})
