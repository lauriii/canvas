import {
  canvasTreeToSpec,
  defineComponentCatalog,
} from 'drupal-canvas/json-render-utils';

import { authoredElementMapToComponentTree } from './authored-elements';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { Result } from '../types/Result';

export interface ElementsValidationContext {
  catalog: ReturnType<typeof defineComponentCatalog>;
  allComponentIds: Set<string>;
  enabledComponentIds: Set<string>;
}

export function buildElementsValidationContext(
  metadata: ComponentMetadata[],
): ElementsValidationContext {
  const enabledMetadata = metadata.filter((m) => m.status);
  return {
    catalog: defineComponentCatalog(enabledMetadata),
    allComponentIds: new Set(metadata.map((m) => `js.${m.machineName}`)),
    enabledComponentIds: new Set(
      enabledMetadata.map((m) => `js.${m.machineName}`),
    ),
  };
}

export function validateElements(
  elements: AuthoredSpecElementMap,
  context: ElementsValidationContext,
): Omit<Result, 'itemName'> {
  const { catalog, allComponentIds, enabledComponentIds } = context;

  if (Object.keys(elements).length === 0) {
    return {
      success: true,
      details: [{ content: 'Empty page (no elements)' }],
    };
  }

  const disabledErrors: { heading: string; content: string }[] = [];
  for (const [id, element] of Object.entries(elements)) {
    if (
      allComponentIds.has(element.type) &&
      !enabledComponentIds.has(element.type)
    ) {
      disabledErrors.push({
        heading: `elements.${id}.type`,
        content: `Component "${element.type}" is disabled. Set "status: true" in its component.yml to enable it.`,
      });
    }
  }

  if (disabledErrors.length > 0) {
    return { success: false, details: disabledErrors };
  }

  const componentTree = authoredElementMapToComponentTree(elements);
  const jsonRenderSpec = canvasTreeToSpec(componentTree);

  for (const element of Object.values(jsonRenderSpec.elements)) {
    if (element.props == null) element.props = {};
    if (!element.children) element.children = [];
    if (!element.slots) element.slots = {};
  }

  const result = catalog.validate(jsonRenderSpec);

  if (result.success) {
    return { success: true };
  }

  const details: { heading?: string; content: string }[] = [];
  if (result.error) {
    for (const issue of result.error.issues) {
      details.push({
        heading: issue.path.length > 0 ? issue.path.join('.') : undefined,
        content: issue.message,
      });
    }
  }
  return { success: false, details };
}
