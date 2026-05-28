import { componentTreeToAuthoredElementMap } from './authored-elements';
import { isRecord } from './utils';

import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { ContentTemplate } from '../types/ContentTemplate';

/**
 * Returns the prefix (e.g. "static", "entity-field", "adapter") of a server
 * prop-source `sourceType`. The wire format uses either a bare prefix
 * ("entity-field") or a colon-separated form ("static:field_item:string",
 * "adapter:image_apply_style").
 *
 * @see \Drupal\canvas\PropSource\PropSource::parse()
 */
function sourceTypePrefix(value: unknown): string | null {
  if (!isRecord(value) || typeof value.sourceType !== 'string') {
    return null;
  }
  const colon = value.sourceType.indexOf(':');
  return colon === -1 ? value.sourceType : value.sourceType.slice(0, colon);
}

/**
 * Convert a single server prop value into the authored format. Content
 * templates are the only authored shape that carries prop expressions —
 * pages and regions don't support `sourceType`, so they skip this step.
 *
 * - Static prop sources (`{sourceType: "static:...", value: X}`) unwrap to
 *   their inner literal — in authored files, plain values without a
 *   `sourceType` key are the canonical form for static inputs.
 * - The deprecated `dynamic` alias is normalized to `entity-field` so pulled
 *   files always use the canonical sourceType name.
 * - Every other prop source (entity-field, host-entity-url, adapter,
 *   default-relative-url) passes through verbatim.
 * - Plain literals pass through unchanged.
 */
export function serverPropToAuthored(value: unknown): unknown {
  if (
    sourceTypePrefix(value) === 'static' &&
    isRecord(value) &&
    'value' in value
  ) {
    return value.value;
  }
  if (isRecord(value) && value.sourceType === 'dynamic') {
    return { ...value, sourceType: 'entity-field' };
  }
  return value;
}

function translateInputsFromServer(
  inputs: Record<string, unknown>,
): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(inputs)) {
    result[key] = serverPropToAuthored(value);
  }
  return result;
}

export interface AuthoredContentTemplateFile {
  label: string;
  entityType: string;
  bundle: string;
  viewMode: string;
  elements: AuthoredSpecElementMap;
}

/**
 * Convert a server ContentTemplate to the authored JSON representation.
 */
export function contentTemplateToAuthored(
  template: ContentTemplate,
): AuthoredContentTemplateFile {
  const elements = componentTreeToAuthoredElementMap(
    template.component_tree ?? [],
    translateInputsFromServer,
  );
  return {
    label: template.label,
    entityType: template.entityType,
    bundle: template.bundle,
    viewMode: template.viewMode,
    elements,
  };
}
