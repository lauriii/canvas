
describe('Canary — verify logging in & installing a module works', {testIsolation: false},  () => {
  before( () => {
    cy.drupalInstall()
  });

  after(() => {
    cy.drupalUninstall()
  })

  beforeEach(() => {
    cy.drupalSession();
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
