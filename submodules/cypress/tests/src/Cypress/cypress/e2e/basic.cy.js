
describe('Basic Functionality', {testIsolation: false},  () => {
  before( () => {
    cy.drupalInstall()
  });

  after(() => {
    cy.drupalUninstall()
  })

  beforeEach(() => {
    cy.visit(Cypress.env('baseUrl'), {failOnStatusCode: false}).then(() => {
      cy.setCookie(
        'SIMPLETEST_USER_AGENT',
        encodeURIComponent(Cypress.env('userAgent')),
        {domain: Cypress.env('host'), path: '/'},
      )
    })
  });


  it('Test login', () => {
    cy
      .drupalCreateUser({
        name: 'user',
        password: '123',
        permissions: ['access site reports', 'administer site configuration'],
      })
  })

  it('test installing a module', () => {
    cy.drupalInstallModule('views', true)
  })
})

describe('install profiles', () => {
  before(() =>  {
    cy.drupalInstall({
      setupFile: 'core/tests/Drupal/TestSite/TestSiteInstallTestScript.php',
      installProfile: 'demo_umami',
    });
  });
  after(() =>  {
    cy.drupalUninstall();
  });
  it ('installs umami profile' , () => {
    cy.drupalRelativeURL('');
    cy.get('#block-umami-branding').should('exist')
    cy.drupalLogAndEnd({ onlyOnError: false });
  })
})
