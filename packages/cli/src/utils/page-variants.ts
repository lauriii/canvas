import { resolvedComponentTreeToAuthoredElementMap } from './authored-elements';

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

  const elements = resolvedComponentTreeToAuthoredElementMap(
    variant.component_tree,
    { fallbackToRawInputs: true },
  );

  return { ...meta, elements };
}
