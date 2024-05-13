# Drupal + Cypress
Has the same helper functions as Drupal core Nightwatch tests,
as well as Axe accessiblity testing

##React component testing examples 
Since there are no existing Drupal React component tests to port, 
a Webpack based setup based on https://github.com/cypress-io/cypress-component-testing-apps is 
provided (much easier to have a working setup to reference)

##Limitation 1
This is directory-sensitive at the moment

Place the `cypress_setup` module in modules/custom
(there isn't any module code at the moment, however, so nothing to enable for now...)

##Limitation 2
No .env support yet, so the test URL will need to be specified in the CLI
` node ./node_modules/.bin/cypress  run --env baseUrl=<yourBaseURL>`
Run the tests from  `cypress_setup/tests/src/Cypress`

Open the Cypress app
`` node ./node_modules/.bin/cypress open --env baseUrl=<yourBaseURL>``

