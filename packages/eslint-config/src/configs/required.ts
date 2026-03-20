import eslintPluginYml from 'eslint-plugin-yml';
import { defineConfig, globalIgnores } from 'eslint/config';
import globals from 'globals';
import tseslint from 'typescript-eslint';

import componentDirNameRule from '../rules/component-dir-name.js';
import componentExportsRule from '../rules/component-exports.js';
import componentImportsRule from '../rules/component-imports.js';
import componentPropNamesRule from '../rules/component-prop-names.js';

import type { Config } from '@eslint/config-helpers';

const required: Config[] = defineConfig([
  globalIgnores(['**/dist/**']),
  {
    files: ['**/*.{ts,tsx,js,jsx}'],
    languageOptions: {
      parser: tseslint.parser,
      ecmaVersion: 2020,
      globals: globals.browser,
      parserOptions: {
        ecmaVersion: 'latest',
        ecmaFeatures: { jsx: true },
        sourceType: 'module',
      },
    },
    settings: { react: { version: '19.0' } },
  },
  eslintPluginYml.configs['flat/base'],
  {
    plugins: {
      'drupal-canvas': {
        rules: {
          'component-dir-name': componentDirNameRule,
          'component-exports': componentExportsRule,
          'component-imports': componentImportsRule,
          'component-prop-names': componentPropNamesRule,
        },
      },
    },
    rules: {
      'drupal-canvas/component-dir-name': 'error',
      'drupal-canvas/component-exports': 'error',
      'drupal-canvas/component-imports': 'error',
      'drupal-canvas/component-prop-names': 'error',
    },
  },
]);

export default required;
