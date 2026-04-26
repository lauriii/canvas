import { isComponentYmlFile } from '../utils/components.js';
import { getYAMLStringValue } from '../utils/yaml.js';

import type { Rule as EslintRule } from 'eslint';
import type { AST } from 'yaml-eslint-parser';

const IMAGE_REF = 'json-schema-definitions://canvas.module/image';

function getMappingPair(
  mapping: AST.YAMLMapping,
  key: string,
): AST.YAMLPair | undefined {
  return mapping.pairs.find((pair) => getYAMLStringValue(pair.key) === key);
}

function isYamlMapping(
  node: AST.YAMLNode | null | undefined,
): node is AST.YAMLMapping {
  return node?.type === 'YAMLMapping';
}

function isYamlSequence(
  node: AST.YAMLNode | null | undefined,
): node is AST.YAMLSequence {
  return node?.type === 'YAMLSequence';
}

function hasImageRef(propMapping: AST.YAMLMapping): boolean {
  const ref = getYAMLStringValue(
    getMappingPair(propMapping, '$ref')?.value ?? null,
  );
  if (ref === IMAGE_REF) {
    return true;
  }

  const itemsValue = getMappingPair(propMapping, 'items')?.value;
  if (!isYamlMapping(itemsValue)) {
    return false;
  }

  return (
    getYAMLStringValue(getMappingPair(itemsValue, '$ref')?.value ?? null) ===
    IMAGE_REF
  );
}

function isFullyQualifiedUrl(value: string): boolean {
  try {
    const parsed = new URL(value);
    return parsed.protocol.length > 0 && parsed.hostname.length > 0;
  } catch {
    return false;
  }
}

function getInvalidImageExampleNodes(
  examplesValue: AST.YAMLNode | null | undefined,
): AST.YAMLScalar[] {
  if (!isYamlSequence(examplesValue)) {
    return [];
  }

  const invalidNodes: AST.YAMLScalar[] = [];

  for (const example of examplesValue.entries) {
    const imageEntries = isYamlSequence(example) ? example.entries : [example];

    for (const imageEntry of imageEntries) {
      if (!isYamlMapping(imageEntry)) {
        continue;
      }

      const srcNode = getMappingPair(imageEntry, 'src')?.value;
      if (
        srcNode?.type === 'YAMLScalar' &&
        typeof srcNode.value === 'string' &&
        !isFullyQualifiedUrl(srcNode.value)
      ) {
        invalidNodes.push(srcNode);
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
        'Validates that default examples for image props use fully-qualified URLs',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context.filename)) {
      return {};
    }

    return {
      YAMLPair(node: AST.YAMLPair) {
        const keyName = getYAMLStringValue(node.key);
        if (keyName !== 'props' || !isYamlMapping(node.value)) {
          return;
        }

        const propertiesValue = getMappingPair(node.value, 'properties')?.value;
        if (!isYamlMapping(propertiesValue)) {
          return;
        }

        for (const propPair of propertiesValue.pairs) {
          const propId = getYAMLStringValue(propPair.key);
          if (!propId || !isYamlMapping(propPair.value)) {
            continue;
          }

          if (!hasImageRef(propPair.value)) {
            continue;
          }

          const examplesValue = getMappingPair(
            propPair.value,
            'examples',
          )?.value;
          const invalidNodes = getInvalidImageExampleNodes(examplesValue);

          for (const invalidNode of invalidNodes) {
            context.report({
              node: invalidNode,
              message: `Image prop "${propId}" example src must be a fully-qualified URL with both scheme and host. Use a placeholder URL such as https://placehold.co/600x400.`,
            });
          }
        }
      },
    };
  },
};

export default rule;
