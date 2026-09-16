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

/**
 * Matches the error Zod's `fromJSONSchema` throws when component metadata
 * references a prop shape the installed drupal-canvas package does not bundle.
 */
const MISSING_PROP_SHAPE_PATTERN = /^Reference not found: #\/\$?defs\/(\S+)$/;

/**
 * Minimum drupal-canvas version bundling each prop shape added after 0.5.0.
 */
const PROP_SHAPE_MINIMUM_VERSIONS: Record<string, string> = {
  document: '0.5.1',
};

function buildComponentCatalog(
  metadata: ComponentMetadata[],
): ReturnType<typeof defineComponentCatalog> {
  try {
    return defineComponentCatalog(metadata);
  } catch (error) {
    const match =
      error instanceof Error
        ? MISSING_PROP_SHAPE_PATTERN.exec(error.message)
        : null;
    if (!match) {
      throw error;
    }
    const shape = match[1];
    const minimumVersion = PROP_SHAPE_MINIMUM_VERSIONS[shape];
    const versionHint = minimumVersion
      ? `"${shape}" props require drupal-canvas ${minimumVersion} or later.`
      : `"${shape}" props require a newer drupal-canvas version.`;
    throw new Error(
      `The installed drupal-canvas package does not support "${shape}" props. ` +
        `${versionHint} ` +
        'Run `npm install drupal-canvas@latest` in the project, then retry.',
      { cause: error },
    );
  }
}

export function buildElementsValidationContext(
  metadata: ComponentMetadata[],
): ElementsValidationContext {
  const enabledMetadata = metadata.filter((m) => m.status);
  return {
    catalog: buildComponentCatalog(enabledMetadata),
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
