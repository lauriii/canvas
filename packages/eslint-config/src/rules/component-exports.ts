import { isInComponentDir } from '../utils/components.js';

import type { Rule as EslintRule } from 'eslint';

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description: 'Validates that component has a default export',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (
      !context.filename.endsWith('.jsx') &&
      !context.filename.endsWith('.js')
    ) {
      return {};
    }
    if (!isInComponentDir(context)) {
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
