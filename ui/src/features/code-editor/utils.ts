import type { CodeComponentProp } from '@/types/CodeComponent';

export function parsePropValue(prop: CodeComponentProp) {
  switch (prop.type) {
    case 'integer':
      return Number(prop.example);
    case 'number':
      return Number(prop.example);
    case 'boolean':
      return String(prop.example) === 'true';
    default:
      return prop.example;
  }
}
