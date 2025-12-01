import jsxA11y from 'eslint-plugin-jsx-a11y';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import eslintPluginYml from 'eslint-plugin-yml';
import { defineConfig } from 'eslint/config';
import js from '@eslint/js';

import required from './required.js';

import type { Config } from '@eslint/config-helpers';
import type { ESLint } from 'eslint';

const recommended: Config[] = defineConfig([
  required,
  {
    files: ['**/*.{js,jsx}'],
    plugins: {
      react,
      'react-hooks': reactHooks as ESLint.Plugin,
      'jsx-a11y': jsxA11y,
    },
    settings: {
      'jsx-a11y': {
        components: {
          Image: 'img',
        },
      },
    },
    rules: {
      ...js.configs.recommended.rules,
      ...react.configs.recommended.rules,
      ...react.configs['jsx-runtime'].rules,
      ...reactHooks.configs.recommended.rules,
      ...jsxA11y.flatConfigs.recommended.rules,
      'react/jsx-no-target-blank': 'off',
      'react/prop-types': 'off',
    },
  },
  ...eslintPluginYml.configs['flat/recommended'],
]);

export default recommended;
