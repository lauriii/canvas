import { resolvedComponentTreeToAuthoredElementMap } from './authored-elements';

import type { Page } from '../types/Page';

export function pageToAuthoredSpec(page: Page): Record<string, unknown> {
  const meta: Record<string, unknown> = {
    uuid: page.uuid,
    title: page.title,
    path: page.path,
    description: page.description,
    ...(page.pageVariant ? { pageVariant: page.pageVariant } : {}),
  };

  if (page.components.length === 0) {
    return { ...meta, elements: {} };
  }

  const elements = resolvedComponentTreeToAuthoredElementMap(page.components);

  return { ...meta, elements };
}
