import { basename, dirname } from 'node:path';

import { isComponentYmlFile } from '../utils/components.js';
import { getYAMLStringValue } from '../utils/yaml.js';

import type { Rule as EslintRule } from 'eslint';
import type { AST } from 'yaml-eslint-parser';

const NAMED_SUFFIX = '.component.yml';

/**
 * For index-style metadata (`component.yml`), the expected name is the
 * directory name. For named metadata (`button.component.yml`), the expected
 * name is the filename prefix before `.component.yml`.
 */
function getExpectedMachineName(filename: string): {
  name: string;
  source: string;
} {
  const fileName = basename(filename);

  if (fileName !== 'component.yml' && fileName.endsWith(NAMED_SUFFIX)) {
    return {
      name: fileName.slice(0, -NAMED_SUFFIX.length),
      source: `metadata filename "${fileName}"`,
    };
  }

  const dirName = basename(dirname(filename));
  return { name: dirName, source: `directory name "${dirName}"` };
}

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Validates that the machineName in component metadata matches the component directory name (index-style) or filename prefix (named-style)',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context.filename)) {
      return {};
    }
    let hasMachineName = false;
    const { name: expectedName, source: expectedSource } =
      getExpectedMachineName(context.filename);

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

        if (expectedName !== machineName) {
          context.report({
            node: node.value,
            message: `${expectedSource[0].toUpperCase()}${expectedSource.slice(1)} does not match machineName "${machineName}".`,
          });
        }
      },
      'Program:exit'() {
        if (!hasMachineName) {
          context.report({
            loc: { line: 1, column: 0 },
            message: `machineName key is missing. Its value should be "${expectedName}" based on ${expectedSource.toLowerCase()}.`,
          });
        }
      },
    };
  },
};

export default rule;
