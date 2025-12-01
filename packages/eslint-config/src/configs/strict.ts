import jsxA11y from 'eslint-plugin-jsx-a11y';
import { defineConfig } from 'eslint/config';

import recommended from './recommended.js';

import type { Config } from '@eslint/config-helpers';

const strict: Config[] = defineConfig([
  recommended,
  {
    files: ['**/*.{js,jsx}'],
    rules: {
      ...jsxA11y.flatConfigs.strict.rules,
    },
  },
]);

export default strict;
