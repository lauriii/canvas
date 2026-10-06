import path from 'node:path';
import prettier from 'eslint-config-prettier';
import pluginChaiFriendly from 'eslint-plugin-chai-friendly';
import cypress from 'eslint-plugin-cypress';
import mochaPlugin from 'eslint-plugin-mocha';
import playwright from 'eslint-plugin-playwright';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import { defineConfig } from 'eslint/config';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import js from '@eslint/js';
import vitest from '@vitest/eslint-plugin';

const drupalGlobals = {
  Drupal: true,
  drupalSettings: true,
};

const isolatedPerTestPlugin = {
  rules: {
    'require-isolated-per-test-import': {
      meta: {
        type: 'problem',
        docs: {
          description:
            "Require `import { isolatedPerTest as test } from '../../fixtures/test.js'` in isolatedPerTest spec files",
        },
        schema: [],
        messages: {
          missingImport:
            "isolatedPerTest spec files must import `{ isolatedPerTest as test }` from '../../fixtures/test.js'.",
        },
      },
      create(context) {
        let found = false;
        return {
          ImportDeclaration(node) {
            if (node.source.value !== '../../fixtures/test.js') return;
            const hasSpecifier = node.specifiers.some(
              (s) =>
                s.type === 'ImportSpecifier' &&
                s.imported.name === 'isolatedPerTest' &&
                s.local.name === 'test',
            );
            if (hasSpecifier) found = true;
          },
          'Program:exit'(node) {
            if (!found) {
              context.report({ node, messageId: 'missingImport' });
            }
          },
        };
      },
    },
  },
};

// Number of test cases each existing Cypress spec had when this rule was added. Do not raise.
// cspell:ignore autosave
const cypressTestBaseline = {
  'ui/tests/e2e/auto-path-alias.cy.js': 4,
  'ui/tests/e2e/autocomplete-props.cy.js': 3,
  'ui/tests/e2e/autosave.cy.js': 1,
  'ui/tests/e2e/canary.cy.js': 2,
  'ui/tests/e2e/ckeditor5.cy.js': 4,
  'ui/tests/e2e/code-component-image.cy.js': 1,
  'ui/tests/e2e/component-transforms-and-evolution.cy.js': 1,
  'ui/tests/e2e/components-slots.cy.js': 1,
  'ui/tests/e2e/contextual-panel.cy.js': 6,
  'ui/tests/e2e/copy-and-paste.cy.js': 2,
  'ui/tests/e2e/dom-to-redux.cy.js': 5,
  'ui/tests/e2e/drag-and-drop.cy.js': 2,
  'ui/tests/e2e/editor-frame.cy.js': 2,
  'ui/tests/e2e/empty-canvas.cy.js': 2,
  'ui/tests/e2e/entity-form-field-types-test.cy.js': 3,
  'ui/tests/e2e/error-handling.cy.js': 2,
  'ui/tests/e2e/expand-slots.cy.js': 1,
  'ui/tests/e2e/extension-legacy.cy.js': 1,
  'ui/tests/e2e/image-code-component.cy.js': 1,
  'ui/tests/e2e/link-code-component.cy.js': 1,
  'ui/tests/e2e/media-library-component-instance.cy.js': 2,
  'ui/tests/e2e/media-library-entity-form.cy.js': 2,
  'ui/tests/e2e/media-library.cy.js': 4,
  'ui/tests/e2e/media-video-prop.cy.js': 2,
  'ui/tests/e2e/multi-select-components.cy.js': 6,
  'ui/tests/e2e/multivalue-date-time-form-design.cy.js': 12,
  'ui/tests/e2e/multivalue-form-design-link.cy.js': 13,
  'ui/tests/e2e/multivalue-form-design-list.cy.js': 11,
  'ui/tests/e2e/multivalue-form-design-number-integer.cy.js': 10,
  'ui/tests/e2e/multivalue-form-design.cy.js': 8,
  'ui/tests/e2e/multivalue-media-form-design.cy.js': 5,
  'ui/tests/e2e/navigation.cy.js': 11,
  'ui/tests/e2e/overlay-ui.cy.js': 1,
  'ui/tests/e2e/page-data-form.cy.js': 1,
  'ui/tests/e2e/pattern.cy.js': 1,
  'ui/tests/e2e/primary-panel.cy.js': 2,
  'ui/tests/e2e/prop-types.cy.js': 20,
  'ui/tests/e2e/publish-review.cy.js': 3,
  'ui/tests/e2e/publish-validation.cy.js': 4,
  'ui/tests/e2e/realtime-preview-code-component.cy.js': 1,
  'ui/tests/e2e/scope-css.cy.js': 2,
  'ui/tests/e2e/states.cy.js': 11,
  'ui/tests/e2e/topbar-layout-preview-mode.cy.js': 7,
  'ui/tests/e2e/undo-redo.cy.js': 2,
  'ui/tests/e2e/vh-units.cy.js': 2,
  'ui/tests/unit/code-editor-component-data-props.cy.jsx': 8,
  'ui/tests/unit/code-editor-component-data-slots.cy.jsx': 2,
  'ui/tests/unit/code-editor-preview.cy.jsx': 1,
  'ui/tests/unit/error-boundary.cy.jsx': 7,
  'ui/tests/unit/validation.cy.js': 3,
};

const noNewCypressPlugin = {
  rules: {
    'no-new-cypress-tests': {
      meta: {
        type: 'problem',
        docs: {
          description:
            'Disallow new Cypress specs and new test cases in existing Cypress specs',
        },
        schema: [],
        messages: {
          tooMany:
            'Do not add Cypress tests ({{count}} found, {{limit}} allowed). Write a Playwright or Vitest test instead.',
        },
      },
      create(context) {
        const key = path.relative(context.cwd, context.filename);
        const limit = cypressTestBaseline[key] ?? 0;
        let count = 0;
        return {
          CallExpression(node) {
            const c = node.callee;
            const name = c.type === 'MemberExpression' ? c.object : c;
            if (
              name.type === 'Identifier' &&
              ['it', 'specify'].includes(name.name)
            ) {
              count++;
            }
          },
          'Program:exit'(node) {
            if (count > limit) {
              context.report({
                node,
                messageId: 'tooMany',
                data: { count, limit },
              });
            }
          },
        };
      },
    },
  },
};

export default defineConfig([
  js.configs.recommended,
  tseslint.configs.recommended,
  prettier,
  react.configs.flat.recommended,
  react.configs.flat['jsx-runtime'],
  reactHooks.configs.flat.recommended,
  {
    files: ['**/*.test.*'],
    plugins: {
      vitest,
    },
    rules: {
      ...vitest.configs.recommended.rules,
      'vitest/valid-expect': 'off', // https://github.com/vitest-dev/eslint-plugin-vitest/issues/675'
      'vitest/no-conditional-expect': 'off',
    },
  },
  {
    // Cypress is being phased out: existing specs may be edited, but not gain test cases.
    files: ['**/*.cy.*'],
    plugins: { 'no-new-cypress': noNewCypressPlugin },
    rules: { 'no-new-cypress/no-new-cypress-tests': 'error' },
  },
  {
    files: ['**/*.cy.*', 'ui/tests/e2e/entity-form-fields/*'],
    plugins: {
      mocha: mochaPlugin,
      cypress: cypress,
      ['chai-friendly']: pluginChaiFriendly,
    },
    rules: {
      ...mochaPlugin.configs.recommended.rules,
      ...cypress.configs.recommended.rules,
      ...pluginChaiFriendly.configs.recommended.rules,
      'mocha/no-mocha-arrows': 'off',
      'mocha/no-top-level-hooks': 'off',
      'mocha/max-top-level-suites': 'off',
      'mocha/no-exclusive-tests': 'error',
    },
  },
  {
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
      '@typescript-eslint/no-unsafe-function-type': 'off',
      '@typescript-eslint/ban-ts-comment': 'off',
      '@typescript-eslint/no-empty-object-type': 'off',
      '@typescript-eslint/no-unused-expressions': 'off',
      '@typescript-eslint/no-unnecessary-type-constraint': 'off',
      '@typescript-eslint/consistent-type-imports': [
        2,
        {
          fixStyle: 'separate-type-imports',
        },
      ],
      '@typescript-eslint/no-restricted-imports': [
        2,
        {
          paths: [
            {
              name: 'react-redux',
              importNames: ['useSelector', 'useStore', 'useDispatch'],
              message:
                'Please use pre-typed versions from `src/app/hooks.ts` instead.',
            },
          ],
        },
      ],
      'react-hooks/immutability': 'off',
      'react-hooks/set-state-in-effect': 'off',
      'react-hooks/refs': 'off',
      'react-hooks/static-components': 'off',
      'react-hooks/globals': 'off',
      'react-hooks/rules-of-hooks': 'off',
      'jsx-no-undef': 'off',
      'react/prop-types': 'off',
      'react/no-unescaped-entities': 'off',
      'react/display-name': 'off',
      'no-shadow': 'off',
      'no-unused-vars': 'off',
      '@typescript-eslint/no-unused-vars': [
        'error',
        { args: 'none', caughtErrors: 'none' },
      ],
      'no-redeclare': ['error', { builtinGlobals: false }],
    },
  },
  {
    files: ['**/*.{mjs,cjs,js,jsx}'],
    rules: {
      '@typescript-eslint/no-unused-expressions': 'off',
      '@typescript-eslint/no-unused-vars': 'off',
    },
  },
  {
    languageOptions: {
      parserOptions: {
        ecmaFeatures: {
          jsx: true,
        },
      },
      globals: {
        ...globals.browser,
        ...globals.node,
        ...vitest.environments.env.globals,
        ...drupalGlobals,
        ...mochaPlugin.configs.recommended.languageOptions.globals,
        once: true,
        cy: true,
        Cypress: true,
        JSX: true,
        NodeJS: true,
        React: true,
        jQuery: true,
      },
    },
    settings: {
      react: {
        version: '18.2',
      },
    },
  },
  {
    files: ['tests/src/Playwright/**/*.ts'],
    extends: [playwright.configs['flat/recommended']],
  },
  {
    files: ['**/tests/isolatedPerTest/**/*.spec.ts'],
    plugins: {
      'isolated-per-test': isolatedPerTestPlugin,
    },
    rules: {
      'isolated-per-test/require-isolated-per-test-import': 'error',
    },
  },
  {
    ignores: [
      '.cache',
      '**/dist',
      '**/.astro',
      'js/astro-bundles/*',
      'js/assets/**/*',
      'ui/src/local_packages',
      '.cache/**',
    ],
  },
]);
