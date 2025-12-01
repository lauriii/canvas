import type { AST } from 'yaml-eslint-parser';

export function getYAMLStringValue(node: AST.YAMLNode | null): string | null {
  if (node && node.type === 'YAMLScalar' && typeof node.value === 'string') {
    return node.value;
  }
  return null;
}
