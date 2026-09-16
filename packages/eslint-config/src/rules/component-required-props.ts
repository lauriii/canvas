import { isComponentYmlFile } from '../utils/components.js';
import {
  getYAMLMappingPair,
  getYAMLStringValue,
  isYAMLMapping,
  isYAMLSequence,
} from '../utils/yaml.js';

import type { Rule as EslintRule } from 'eslint';
import type { AST } from 'yaml-eslint-parser';

function hasDefaultExample(prop: AST.YAMLMapping): boolean {
  const examples = getYAMLMappingPair(prop, 'examples')?.value;
  if (!isYAMLSequence(examples) || examples.entries.length === 0) {
    return false;
  }

  const firstExample = examples.entries[0];
  return !(
    firstExample === null ||
    (firstExample.type === 'YAMLScalar' && firstExample.value === null)
  );
}

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Validates that required component props are defined and provide a default example',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context.filename)) {
      return {};
    }

    return {
      YAMLPair(node: AST.YAMLPair) {
        if (
          getYAMLStringValue(node.key) !== 'required' ||
          !isYAMLSequence(node.value) ||
          !isYAMLMapping(node.parent) ||
          node.parent.parent.type !== 'YAMLDocument'
        ) {
          return;
        }

        const props = getYAMLMappingPair(node.parent, 'props')?.value;
        const properties = isYAMLMapping(props)
          ? getYAMLMappingPair(props, 'properties')?.value
          : undefined;

        for (const requiredEntry of node.value.entries) {
          if (requiredEntry === null) {
            continue;
          }
          const propName = getYAMLStringValue(requiredEntry);
          if (!propName) {
            continue;
          }

          const prop = isYAMLMapping(properties)
            ? getYAMLMappingPair(properties, propName)
            : undefined;
          if (!prop) {
            context.report({
              node: requiredEntry,
              message: `Required prop "${propName}" is not defined in props.properties.`,
            });
            continue;
          }

          if (isYAMLMapping(prop.value) && !hasDefaultExample(prop.value)) {
            context.report({
              node: prop,
              message: `Required prop "${propName}" must have an example value.`,
            });
          }
        }
      },
    };
  },
};

export default rule;
