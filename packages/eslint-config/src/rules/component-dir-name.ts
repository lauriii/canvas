import { basename, dirname } from 'node:path';

import { isComponentYmlFile } from '../utils/components.js';
import { getYAMLStringValue } from '../utils/yaml.js';

import type { Rule as EslintRule } from 'eslint';
import type { AST } from 'yaml-eslint-parser';

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Validates that component directory name matches the machineName in component.yml',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context)) {
      return {};
    }
    let hasMachineName = false;
    return {
      YAMLPair(node: AST.YAMLPair) {
        const keyName = getYAMLStringValue(node.key);
        if (keyName !== 'machineName') {
          return;
        }
        hasMachineName = true;

        const machineName = getYAMLStringValue(node.value);
        if (!node.value || !machineName) {
          context.report({
            node,
            message: 'machineName must be a string.',
          });
          return;
        }

        const componentDir = dirname(context.filename);
        const componentDirName = basename(componentDir);

        if (componentDirName !== machineName) {
          context.report({
            node: node.value,
            message: `Component directory name "${componentDirName}" does not match machineName "${machineName}" from component.yml.`,
          });
        }
      },
      'Program:exit'() {
        if (!hasMachineName) {
          const componentDir = dirname(context.filename);
          const componentDirName = basename(componentDir);
          context.report({
            loc: { line: 1, column: 0 },
            message: `machineName key is missing. Its value should match the directory name: "${componentDirName}".`,
          });
        }
      },
    };
  },
};

export default rule;
