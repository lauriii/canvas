import { isComponentYmlFile } from '../utils/components.js';
import {
  getYAMLMappingPair,
  getYAMLStringValue,
  isYAMLMapping,
  isYAMLSequence,
} from '../utils/yaml.js';

import type { Rule as EslintRule } from 'eslint';
import type { AST } from 'yaml-eslint-parser';

function isEmptyStringScalar(
  node: AST.YAMLNode | null | undefined,
): node is AST.YAMLScalar {
  return node?.type === 'YAMLScalar' && node.value === '';
}

function getStringExampleMode(
  propMapping: AST.YAMLMapping,
): 'string' | 'string-array' | null {
  const type = getYAMLStringValue(
    getYAMLMappingPair(propMapping, 'type')?.value ?? null,
  );
  if (type === 'string') {
    return 'string';
  }

  if (type !== 'array') {
    return null;
  }

  const itemsValue = getYAMLMappingPair(propMapping, 'items')?.value;
  if (!isYAMLMapping(itemsValue)) {
    return null;
  }

  return getYAMLStringValue(
    getYAMLMappingPair(itemsValue, 'type')?.value ?? null,
  ) === 'string'
    ? 'string-array'
    : null;
}

function getInvalidExampleNodes(
  examplesValue: AST.YAMLNode | null | undefined,
  mode: 'string' | 'string-array',
): AST.YAMLScalar[] {
  if (!isYAMLSequence(examplesValue)) {
    return [];
  }

  if (mode === 'string') {
    const invalidNodes: AST.YAMLScalar[] = [];
    for (const example of examplesValue.entries) {
      if (isEmptyStringScalar(example)) {
        invalidNodes.push(example);
      }
    }
    return invalidNodes;
  }

  const invalidNodes: AST.YAMLScalar[] = [];
  for (const example of examplesValue.entries) {
    if (!isYAMLSequence(example)) {
      continue;
    }

    for (const item of example.entries) {
      if (isEmptyStringScalar(item)) {
        invalidNodes.push(item);
      }
    }
  }

  return invalidNodes;
}

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Validates that string prop examples do not contain empty string values',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context.filename)) {
      return {};
    }

    return {
      YAMLPair(node: AST.YAMLPair) {
        const keyName = getYAMLStringValue(node.key);
        if (keyName !== 'props' || !isYAMLMapping(node.value)) {
          return;
        }

        const propertiesValue = getYAMLMappingPair(
          node.value,
          'properties',
        )?.value;
        if (!isYAMLMapping(propertiesValue)) {
          return;
        }

        for (const propPair of propertiesValue.pairs) {
          const propId = getYAMLStringValue(propPair.key);
          if (!propId || !isYAMLMapping(propPair.value)) {
            continue;
          }

          const mode = getStringExampleMode(propPair.value);
          if (!mode) {
            continue;
          }

          const examplesValue = getYAMLMappingPair(
            propPair.value,
            'examples',
          )?.value;
          const invalidNodes = getInvalidExampleNodes(examplesValue, mode);

          for (const invalidNode of invalidNodes) {
            context.report({
              node: invalidNode,
              message: `Prop "${propId}" example values must not be empty strings. Remove the empty example or use a non-empty placeholder value.`,
            });
          }
        }
      },
    };
  },
};

export default rule;
