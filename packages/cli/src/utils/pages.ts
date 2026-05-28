import { canvasTreeToSpec } from 'drupal-canvas/json-render-utils';

import { jsonRenderSpecToAuthoredElementMap } from './authored-elements';
import { isRecord } from './utils';

import type { Page } from '../types/Page';

function isResolvedMediaValue(
  value: unknown,
): value is Record<string, unknown> {
  return isRecord(value) && typeof value.src === 'string';
}

function isMediaProvenanceValue(value: unknown): boolean {
  return (
    isRecord(value) &&
    (typeof value.target_id === 'number' ||
      typeof value.target_id === 'string' ||
      typeof value.target_uuid === 'string')
  );
}

function extractMediaProvenance(
  inputs: Record<string, unknown>,
  resolvedInputs: Record<string, unknown>,
): Record<string, unknown> | undefined {
  const provenance = Object.fromEntries(
    Object.entries(inputs).filter(([key, value]) => {
      return (
        isResolvedMediaValue(resolvedInputs[key]) &&
        isMediaProvenanceValue(value)
      );
    }),
  );

  return Object.keys(provenance).length > 0 ? provenance : undefined;
}
export function pageToAuthoredSpec(page: Page): Record<string, unknown> {
  const meta: Record<string, unknown> = {
    uuid: page.uuid,
    title: page.title,
    path: page.path,
    description: page.description,
  };

  if (page.components.length === 0) {
    return { ...meta, elements: {} };
  }

  const components = page.components.map((node) => ({
    ...node,
    inputs: isRecord(node.inputs_resolved)
      ? node.inputs_resolved
      : ({} as Record<string, unknown>),
  }));

  const spec = canvasTreeToSpec(components);
  const elements = jsonRenderSpecToAuthoredElementMap(spec);

  for (const node of page.components) {
    const element = elements[node.uuid];
    if (!element) {
      continue;
    }

    const provenance = extractMediaProvenance(
      isRecord(node.inputs) ? node.inputs : {},
      isRecord(node.inputs_resolved) ? node.inputs_resolved : {},
    );
    if (provenance) {
      element._provenance = provenance;
    }
  }

  return { ...meta, elements };
}
