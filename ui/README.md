# Experience Builder

## Prerequisites
- Enable the Experience Builder module

## Build steps
1. `npm install` from /modules/experience_builder/ui
2. `npm run build`

## Development mode
1. `npm install` from /modules/experience_builder/ui
2. Make sure nothing is running on localhost:5173
2. Choose one of
   1. `npm run dev` - Will use MSW to serve mock endpoint data
   2. `npm run drupaldev` - Will retrieve data from Drupal endpoints
3. Enable the Experience Builder Vite Integration module (`xb_vite`)
4. Clear cache (`drush cr` or `/admin/config/development/performance`)
5. Navigate to `/xb` to view app

## Running Unit/Component Tests
- `npm run cy:component`

## Running E2E Tests
- In your `.env` file, set `BASE_URL` and `DB_URL`. See `.env.example` for an example.
- The e2e tests use the application file in /ui/dist, which is only updated by
  running `npm run build`. Be sure to do this before running e2e tests.
- Then, _either_:
  - Use `npm run cy:open` to run e2e with the (very helpful) Cypress GUI test runner (do that in its own terminal). This runs the test in a visible browser.
  - Use `npm run cy:run` to run the same e2e tests in the terminal (this is also the command used by Gitlab CI). This runs the test in a "headless" browser.

## Testing Strategy
Our testing strategy leverages [Cypress.io](https://www.cypress.io) for both end-to-end (e2e) and component testing, integrated with [Testing Library](https://testing-library.com/) to ensure robust and maintainable tests.

### Principles
1. We are not testing Drupal core functionality outside the Experience Builder — any global setup tasks should be in a base install profile where possible
2. All specs are isolated and start from a fresh database and filesystem import created (e.g. no dependencies between tests)
3. Every spec file is responsible for setting up the test environment for that set of scenarios (e.g. package imports, enabling contrib modules outside the basic install)

### Why Cypress?
1. **Ease of Use:** Cypress is highly approachable and user-friendly, enabling contributors to quickly become productive.
2. **Consistency:** Using Cypress for e2e, component and unit testing ensures a consistent testing environment and reduces the learning curve.
3. **Debugging:** Cypress provides an intuitive interface for debugging, which is consistent across both e2e and component tests.
4. **Proven:** Cypress is a long-established and well-supported tool capable of meeting our needs.

Points 1 and 3 in particular have led to our choice to implement Cypress testing for this application over the Nightwatch-based solution provided by Drupal Core.

### Best Practices
To mitigate potential issues such as flakiness and to ensure our tests reflect actual user interactions as closely as possible we adhere to the following best practices:

1. **Avoid Direct DOM Manipulation:** We use `@testing-library/cypress` to interact with the DOM in a way that reflects user interactions. This means avoiding direct `querySelector` calls and instead using methods like `getByRole`, `getByText`, etc.
2. **ESLint Rules:** We enforce `eslint-plugin-testing-library` rules to ensure tests are written in a maintainable and user-centric manner.
3. **Centralize repeated actions:** In e2e test in particular, where possible, testing actions (such as logging in) should be centralized in a commands file in the `cypress/support/` directory.

### Continuous Integration
We are working on integrating Cypress tests into our CI pipeline to ensure that all tests are run consistently and reliably. This includes setting up the necessary infrastructure and addressing any performance concerns.

We will periodically evaluate using Cypress for our **unit tests** and compare it with other testing frameworks (e.g. `vitest`) to ensure we are making the best trade-offs between ease of use, functionality and speed/performance.

