import { isComponentYmlFile } from '../utils/components.js';
import {
  getYAMLMappingPair,
  getYAMLStringValue,
  isYAMLMapping,
  isYAMLSequence,
} from '../utils/yaml.js';

import type { Rule as EslintRule } from 'eslint';
import type { AST } from 'yaml-eslint-parser';

const CONTENT_ENTITY_REFERENCE_REF =
  'json-schema-definitions://canvas.module/content-entity-reference';

function isTopLevelPair(node: AST.YAMLPair): boolean {
  const parent = node.parent;
  return (
    parent?.type === 'YAMLMapping' && parent.parent?.type === 'YAMLDocument'
  );
}

function isContentEntityReferenceProp(propMapping: AST.YAMLMapping): boolean {
  return (
    getYAMLStringValue(getYAMLMappingPair(propMapping, '$ref')?.value) ===
    CONTENT_ENTITY_REFERENCE_REF
  );
}

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description: 'Validates content-entity-reference component prop metadata',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context.filename)) {
      return {};
    }

    let propsNode: AST.YAMLPair | undefined;
    let requiredNode: AST.YAMLPair | undefined;
    let dataDependenciesNode: AST.YAMLPair | undefined;

    return {
      YAMLPair(node: AST.YAMLPair) {
        if (!isTopLevelPair(node)) {
          return;
        }

        const keyName = getYAMLStringValue(node.key);
        if (keyName === 'props') {
          propsNode = node;
        } else if (keyName === 'required') {
          requiredNode = node;
        } else if (keyName === 'dataDependencies') {
          dataDependenciesNode = node;
        }
      },
      'Program:exit'() {
        const propertiesValue =
          propsNode && isYAMLMapping(propsNode.value)
            ? getYAMLMappingPair(propsNode.value, 'properties')?.value
            : undefined;
        if (!isYAMLMapping(propertiesValue)) {
          return;
        }

        const contentEntityReferenceProps = new Map<string, AST.YAMLPair>();
        for (const propPair of propertiesValue.pairs) {
          const propName = getYAMLStringValue(propPair.key);
          if (!propName || !isYAMLMapping(propPair.value)) {
            continue;
          }
          if (!isContentEntityReferenceProp(propPair.value)) {
            continue;
          }
          contentEntityReferenceProps.set(propName, propPair);

          const entityTypeId = getYAMLStringValue(
            getYAMLMappingPair(propPair.value, 'x-allowed-entity-type-id')
              ?.value,
          );
          if (!entityTypeId) {
            context.report({
              node: propPair,
              message: `Prop "${propName}" is a content-entity-reference prop but is missing x-allowed-entity-type-id.`,
            });
          }
          const bundle = getYAMLStringValue(
            getYAMLMappingPair(propPair.value, 'x-allowed-bundle')?.value,
          );
          if (!bundle) {
            context.report({
              node: propPair,
              message: `Prop "${propName}" is a content-entity-reference prop but is missing x-allowed-bundle.`,
            });
          }

          const examplesPair = getYAMLMappingPair(propPair.value, 'examples');
          if (examplesPair) {
            context.report({
              node: examplesPair,
              message: `Prop "${propName}" is a content-entity-reference prop and must not have examples.`,
            });
          }
        }

        if (contentEntityReferenceProps.size === 0) {
          return;
        }

        if (requiredNode && isYAMLSequence(requiredNode.value)) {
          for (const entry of requiredNode.value.entries) {
            const propName = getYAMLStringValue(entry);
            if (!propName || !contentEntityReferenceProps.has(propName)) {
              continue;
            }
            if (!entry) {
              continue;
            }
            context.report({
              node: entry,
              message: `Prop "${propName}" is required, but content-entity-reference props must be optional.`,
            });
          }
        }

        const entityFieldsPair =
          dataDependenciesNode && isYAMLMapping(dataDependenciesNode.value)
            ? getYAMLMappingPair(dataDependenciesNode.value, 'entityFields')
            : undefined;

        if (!entityFieldsPair) {
          for (const [propName, propPair] of contentEntityReferenceProps) {
            context.report({
              node: propPair,
              message: `Prop "${propName}" is a content-entity-reference prop but is missing dataDependencies.entityFields.${propName}.`,
            });
          }
          return;
        }

        if (!isYAMLMapping(entityFieldsPair.value)) {
          context.report({
            node: entityFieldsPair,
            message: 'dataDependencies.entityFields must be an object.',
          });
          return;
        }

        if (entityFieldsPair.value.pairs.length === 0) {
          context.report({
            node: entityFieldsPair,
            message:
              "There must be >=1 content-entity-reference prop; otherwise the 'entityFields' key should be omitted.",
          });
        }

        const entityFieldPropNames = new Set<string>();
        for (const entityFieldPair of entityFieldsPair.value.pairs) {
          const propName = getYAMLStringValue(entityFieldPair.key);
          if (!propName) {
            continue;
          }
          entityFieldPropNames.add(propName);
          if (!contentEntityReferenceProps.has(propName)) {
            context.report({
              node: entityFieldPair,
              message: `dataDependencies.entityFields.${propName} references a prop that is not a content-entity-reference prop.`,
            });
            continue;
          }

          if (
            !isYAMLSequence(entityFieldPair.value) ||
            entityFieldPair.value.entries.length === 0
          ) {
            context.report({
              node: entityFieldPair,
              message: `There must be >=1 entity field expression for content-entity-reference prop "${propName}".`,
            });
            continue;
          }

          for (const expressionNode of entityFieldPair.value.entries) {
            const expression = getYAMLStringValue(expressionNode);
            if (expression === null) {
              context.report({
                node: expressionNode ?? entityFieldPair,
                message: `dataDependencies.entityFields.${propName} contains a non-string expression.`,
              });
              continue;
            }
          }
        }

        for (const [propName, propPair] of contentEntityReferenceProps) {
          if (entityFieldPropNames.has(propName)) {
            continue;
          }
          context.report({
            node: propPair,
            message: `Prop "${propName}" is a content-entity-reference prop but is missing dataDependencies.entityFields.${propName}.`,
          });
        }
      },
    };
  },
};

export default rule;
