// ***********************************************************
// This example support/component.js is processed and
// loaded automatically before your test files.
//
// This is a great place to put global configuration and
// behavior that modifies Cypress.
//
// You can change the location of this file or turn off
// automatically serving support files with the
// 'supportFile' configuration option.
//
// You can read more here:
// https://on.cypress.io/configuration
// ***********************************************************
// Import commands.js using ES2015 syntax:
import './commands';

// Alternatively you can use CommonJS syntax:
// require('./commands')

import { mount } from 'cypress/react';

import { installDrupalTranslationStub } from './drupal-translation-stub';

// Components call Drupal.t() directly, which core/misc/drupal.js provides in
// the browser but nothing provides here. Some modules call it while they are
// being imported, so this has to run before any component is mounted.
installDrupalTranslationStub();

Cypress.Commands.add('mount', mount);

// Example use:
// cy.mount(<MyComponent />)
