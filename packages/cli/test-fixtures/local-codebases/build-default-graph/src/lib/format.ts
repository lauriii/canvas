import { kebabCase } from 'lodash-es';

export function formatTitle(value: string): string {
  return kebabCase(value);
}
