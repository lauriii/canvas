import type { ComponentType } from 'react';

interface ResolverResult {
  component: ComponentType | null;
  reason: string | null;
}

function isComponentCandidate(value: unknown): value is ComponentType {
  if (typeof value === 'function') {
    return true;
  }

  if (typeof value === 'object' && value !== null) {
    // ForwardRef, memo, and lazy components are React objects with $$typeof.
    return '$$typeof' in value;
  }

  return false;
}

export function resolvePreviewComponent(
  moduleRecord: Record<string, unknown>,
): ResolverResult {
  const defaultExport = moduleRecord.default;
  if (isComponentCandidate(defaultExport)) {
    return { component: defaultExport, reason: null };
  }

  const exportNames = Object.keys(moduleRecord);
  return {
    component: null,
    reason: `No renderable default export found. Available exports: ${exportNames.join(', ') || '(none)'}.`,
  };
}
