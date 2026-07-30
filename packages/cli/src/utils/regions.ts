import { resolvedComponentTreeToAuthoredElementMap } from './authored-elements';

import type {
  AuthoredSpecElementMap,
  CanvasComponentTree,
} from 'drupal-canvas/json-render-utils';
import type { Region } from '../types/Region';

/**
 * On-disk shape of a region JSON file.
 *
 * The region's machine name comes from the filename (`<region>.json`); the
 * theme is implicit and resolved server-side from the site's default theme.
 */
export interface AuthoredRegionSpec {
  status?: boolean;
  elements: AuthoredSpecElementMap;
}

/**
 * Convert a wire-format Region (from the Drupal API) to its authored spec
 * form for writing to disk.
 */
export function regionToAuthoredSpec(region: Region): AuthoredRegionSpec {
  const meta = { status: region.status };

  if (region.component_tree.length === 0) {
    return { ...meta, elements: {} };
  }

  return {
    ...meta,
    elements: resolvedComponentTreeToAuthoredElementMap(
      region.component_tree,
      // The CLI can target older Canvas sites whose region response does not
      // expose inputs_resolved yet.
      { fallbackToRawInputs: true },
    ),
  };
}

/**
 * Convert an authored region spec (loaded from disk) to a wire-format POST
 * body for `/canvas/api/v0/config/page_region`. `region` is supplied by the
 * caller (derived from the filename); `theme` is omitted so the server fills
 * it in from the site's default theme.
 */
export function authoredRegionToPayload(
  spec: AuthoredRegionSpec,
  region: string,
  componentTree: CanvasComponentTree,
): {
  region: string;
  status: boolean;
  component_tree: CanvasComponentTree;
} {
  return {
    region,
    status: spec.status ?? true,
    component_tree: componentTree,
  };
}
