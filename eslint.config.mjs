import prettier from 'eslint-config-prettier';
import { defineConfig } from 'eslint/config';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import js from '@eslint/js';
import vitest from "@vitest/eslint-plugin"
import mochaPlugin from "eslint-plugin-mocha";
import drupal from "eslint-config-drupal"
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import pluginChaiFriendly from 'eslint-plugin-chai-friendly';
import cypress from "eslint-plugin-cypress"

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
    files: ['**/*.cy.*', "ui/tests/e2e/entity-form-fields/*"],
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
    }
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
    }
  },
  {
    files: ["**/*.{mjs,cjs,js,jsx}"],
    rules: {
      '@typescript-eslint/no-unused-expressions': 'off',
      '@typescript-eslint/no-unused-vars': 'off',
    }
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
        ...drupal.globals,
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
    ignores: [
      "**/dist",
      "**/.astro",
      "js/astro-bundles/*",
      "js/assets/**/*",
      "ui/src/local_packages",
    ]
  }
]);
