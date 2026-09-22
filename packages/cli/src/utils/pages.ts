import { resolvedComponentTreeToAuthoredElementMap } from './authored-elements';
import { collapseColorPropsInElements } from './prop-transforms';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { Page } from '../types/Page';

interface PageToAuthoredSpecOptions {
  componentMetadata?: ComponentMetadata[];
}

export function pageToAuthoredSpec(
  page: Page,
  options: PageToAuthoredSpecOptions = {},
): Record<string, unknown> {
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

  const baseElements = resolvedComponentTreeToAuthoredElementMap(
    page.components,
  );
  const elements =
    options.componentMetadata && options.componentMetadata.length > 0
      ? collapseColorPropsInElements(baseElements, options.componentMetadata)
      : baseElements;

  return { ...meta, elements };
}
