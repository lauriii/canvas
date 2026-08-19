import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

/**
 * Wire format of a page variant ("page template" in the UI), as returned by
 * `/canvas/api/v0/config/page_variant`.
 *
 * @see \Drupal\canvas\Entity\PageVariant::normalizeForClientSide()
 */
export interface PageVariant {
  id: string;
  label: string;
  description?: string | null;
  status: boolean;
  component_tree: CanvasComponentTree;
}
