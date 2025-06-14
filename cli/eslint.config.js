import typescriptEslint from '@typescript-eslint/eslint-plugin';
import tsParser from '@typescript-eslint/parser';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import js from '@eslint/js';
import { FlatCompat } from '@eslint/eslintrc';
import globals from 'globals';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const compat = new FlatCompat({
  baseDirectory: __dirname,
  recommendedConfig: js.configs.recommended,
  allConfig: js.configs.all,
});

export default [
  {
    files: ['**/*.{c,m,}{t,j}s', '**/*.{t,j}sx'],
  },
  {
    ignores: ['**/dist', '**/node_modules'],
  },
  ...compat.extends('eslint:recommended', 'plugin:prettier/recommended'),
  {
    plugins: {
      '@typescript-eslint': typescriptEslint,
    },

    languageOptions: {
      parser: tsParser,
      ecmaVersion: 5,
      globals: {
        ...globals.node,
      },
      parserOptions: {
        project: true,
      },
    },

    rules: {
      '@typescript-eslint/consistent-type-imports': [
        2,
        {
          fixStyle: 'separate-type-imports',
        },
      ],

      'no-shadow': 'off',
      'no-unused-vars': 'off',
      '@typescript-eslint/no-unused-vars': [
        'error',
        { args: 'none', caughtErrors: 'none' },
      ],
      'no-redeclare': ['error', { builtinGlobals: false }],
    },
  },
];
