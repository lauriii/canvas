import type { AST } from 'yaml-eslint-parser';

export function getYAMLStringValue(
  node: AST.YAMLNode | null | undefined,
): string | null {
  if (node && node.type === 'YAMLScalar' && typeof node.value === 'string') {
    return node.value;
  }
  return null;
}

export function isYAMLMapping(
  node: AST.YAMLNode | null | undefined,
): node is AST.YAMLMapping {
  return node?.type === 'YAMLMapping';
}

export function isYAMLSequence(
  node: AST.YAMLNode | null | undefined,
): node is AST.YAMLSequence {
  return node?.type === 'YAMLSequence';
}

export function getYAMLMappingPair(
  mapping: AST.YAMLMapping,
  key: string,
): AST.YAMLPair | undefined {
  return mapping.pairs.find((pair) => getYAMLStringValue(pair.key) === key);
}
