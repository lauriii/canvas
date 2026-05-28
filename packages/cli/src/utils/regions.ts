import { canvasTreeToSpec } from 'drupal-canvas/json-render-utils';

import { jsonRenderSpecToAuthoredElementMap } from './authored-elements';
import { isRecord } from './utils';

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

  // The PageRegion config schema omits `parent_uuid`, `slot`, and `label`
  // when they have no value, so the server returns root-level components
  // with those keys absent. canvasTreeToSpec requires explicit `null` to
  // recognize root components, so normalize back here.
  const components = region.component_tree.map((node) => ({
    ...node,
    parent_uuid: node.parent_uuid ?? null,
    slot: node.slot ?? null,
    label: node.label ?? null,
    inputs: isRecord(node.inputs) ? node.inputs : {},
  }));

  const spec = canvasTreeToSpec(components);
  const elements = jsonRenderSpecToAuthoredElementMap(spec);

  return { ...meta, elements };
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
