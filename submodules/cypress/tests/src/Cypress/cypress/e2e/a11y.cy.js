import minimist from 'minimist';
const argv = minimist(process.argv.slice(2))

const adminTestCases = [
  { name: 'User Edit', path: '/user/1/edit' },
  { name: 'Create Article', path: '/user/1/edit' },
  { name: 'Create Page', path: '/node/add/page?destination=/admin/content' },
  { name: 'Content Page', path: '/admin/content' },
  { name: 'Structure Page', path: '/admin/structure' },
  { name: 'Add content type', path: '/admin/structure/types/add' },
  { name: 'Add vocabulary', path: '/admin/structure/taxonomy/add' },
  // @todo remove the skipped rules below in https://drupal.org/i/3318394.
  {
    name: 'Structure | Block',
    path: '/admin/structure/block',
    options: {
      rules: {
        'color-contrast': { enabled: false },
        'duplicate-id-active': { enabled: false },
        region: { enabled: false },
      },
    },
  },
];

const defaultTestCases = [
  {
    name: 'Homepage',
    path: '/',
    // @todo remove the disabled 'region' rule in https://drupal.org/i/3318396.
    options: {
      rules: {
        region: { enabled: false },
        'heading-order': { enabled: false },
      },
    },
  },
  {
    name: 'Login',
    path: '/user/login',
    // @todo remove the disabled 'region' rule in https://drupal.org/i/3318396.
    options: {
      rules: {
        region: { enabled: false },
        'heading-order': { enabled: false },
      },
    },
  },
  // @todo remove the heading and duplicate id rules below in
  //   https://drupal.org/i/3318398.
  {
    name: 'Search',
    path: '/search/node',
    options: {
      rules: {
        'heading-order': { enabled: false },
        'duplicate-id-aria': { enabled: false },
        'region': { enabled: false },
      },
    },
  },
];

describe('a11y admin', {testIsolation: false}, () => {
  before(() => {
    cy.drupalInstall({ installProfile: 'nightwatch_a11y_testing' });
    // If an admin theme other than Claro is being used for testing, install it.
    if (argv.adminTheme && argv.adminTheme !== Cypress.env('adminTheme')) {
      browser.drupalEnableTheme(argv.adminTheme, true);
    }
  });
  after(() => {
   cy.drupalUninstall();
  });
  beforeEach(() => {
    cy.visit(Cypress.env('baseUrl'), {failOnStatusCode: false}).then(() => {
      cy.setCookie(
        'SIMPLETEST_USER_AGENT',
        encodeURIComponent(Cypress.env('userAgent')),
        {domain: Cypress.env('host'), path: '/'},
      )
    })
  });
  adminTestCases.forEach((testCase) => {
    it(`Accessibility - Admin Theme: ${testCase.name}`, () => {
      cy.drupalLoginAsAdmin(() => {
        cy.drupalRelativeURL(testCase.path)
        cy.injectAxe();
        cy.checkA11y('body', testCase.options || {});
      })
    })
  });
})

describe('a11y default', {testIsolation: false}, () => {
  before(() => {
    cy.drupalInstall({ installProfile: 'nightwatch_a11y_testing' });
    // If an admin theme other than Claro is being used for testing, install it.
    if (argv.adminTheme && argv.adminTheme !== Cypress.env('defaultTheme')) {
      browser.drupalEnableTheme(argv.adminTheme, true);
    }
  });
  after(() => {
    cy.drupalUninstall();
  });
  beforeEach(() => {
    cy.visit(Cypress.env('baseUrl'), {failOnStatusCode: false}).then(() => {
      cy.setCookie(
        'SIMPLETEST_USER_AGENT',
        encodeURIComponent(Cypress.env('userAgent')),
        {domain: Cypress.env('host'), path: '/'},
      )
    })
  });
  defaultTestCases.forEach((testCase) => {
    it(`Accessibility - Default Theme: ${testCase.name}`, () => {
      cy.drupalLoginAsAdmin(() => {
        cy.drupalRelativeURL(testCase.path)
        cy.injectAxe();
        cy.checkA11y('body', testCase.options || {});
      })
    })
  });
})
