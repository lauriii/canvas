import { resolvedComponentTreeToAuthoredElementMap } from './authored-elements';
import { collapseColorPropsInElements } from './prop-transforms';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { PageVariant } from '../types/PageVariant';

/**
 * On-disk shape of a page template JSON file.
 *
 * The page variant's machine name comes from the filename (`<id>.json`).
 * `default: true` marks the variant as the site default; at most one file may
 * set it.
 */
export interface AuthoredPageTemplateSpec {
  label: string;
  description?: string;
  status?: boolean;
  default?: boolean;
  elements: AuthoredSpecElementMap;
}

/**
 * Convert a wire-format PageVariant (from the Drupal API) to its authored
 * spec form for writing to disk.
 */
export function pageVariantToAuthoredSpec(
  variant: PageVariant,
  isDefault: boolean,
  componentMetadata: ComponentMetadata[] = [],
): AuthoredPageTemplateSpec {
  const meta: Omit<AuthoredPageTemplateSpec, 'elements'> = {
    label: variant.label,
    ...(variant.description ? { description: variant.description } : {}),
    status: variant.status,
    ...(isDefault ? { default: true } : {}),
  };

  if (variant.component_tree.length === 0) {
    return { ...meta, elements: {} };
  }

  const baseElements = resolvedComponentTreeToAuthoredElementMap(
    variant.component_tree,
    { fallbackToRawInputs: true },
  );
  const elements =
    componentMetadata.length > 0
      ? collapseColorPropsInElements(baseElements, componentMetadata)
      : baseElements;

  return { ...meta, elements };
}
