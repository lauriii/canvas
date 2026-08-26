import { detectHeadlessSdk } from '@drupal-canvas/discovery';

import { isComponentEntrypoint } from '../utils/components.js';

import type { Rule as EslintRule } from 'eslint';

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description: 'Validates that component has a default export',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    // A headless app renders its own components, so it decides how they are
    // exported and consumed. Its entries may also be framework single-file
    // components with no JavaScript default export.
    if (detectHeadlessSdk(context.cwd)) {
      return {};
    }

    if (!isComponentEntrypoint(context)) {
      return {};
    }

    let hasDefaultExport = false;

    return {
      ExportDefaultDeclaration() {
        hasDefaultExport = true;
      },
      'Program:exit'(node) {
        if (!hasDefaultExport) {
          context.report({
            node,
            message: 'Component must have a default export',
          });
        }
      },
    };
  },
};

export default rule;
