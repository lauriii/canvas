import jsxA11y from 'eslint-plugin-jsx-a11y';
import { defineConfig } from 'eslint/config';
import tseslint from 'typescript-eslint';

import recommended from './recommended.js';

import type { Config } from '@eslint/config-helpers';

const strict: Config[] = defineConfig([
  recommended,
  {
    files: ['**/*.{ts,tsx,js,jsx}'],
    rules: {
      ...jsxA11y.flatConfigs.strict.rules,
    },
  },
  ...tseslint.configs.strict,
]);

export default strict;
