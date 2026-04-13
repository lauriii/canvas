import fs from 'fs/promises';
import path from 'path';
import addFormats from 'ajv-formats';
import Ajv from 'ajv/dist/2020.js';
import {
  canvasTreeToSpec,
  defineComponentCatalog,
} from 'drupal-canvas/json-render-utils';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import pageSpecSchema from '../../../workbench/src/lib/schemas/page-spec.schema.json';
import { authoredSpecToComponentTree } from './pages';

import type {
  ComponentMetadata,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
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

/**
 * Validates an AuthoredSpecElementMap against a prebuilt validation context.
 */
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

  // Check for disabled components before catalog validation.
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

  // Validate the full spec against the catalog schema.
  const componentTree = authoredSpecToComponentTree(elements);
  const jsonRenderSpec = canvasTreeToSpec(componentTree);

  // Ensure every element has children and slots defaults — the catalog
  // schema requires them even when the component has no slots.
  for (const element of Object.values(jsonRenderSpec.elements)) {
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

/**
 * Validates discovered pages against a catalog built from the discovery result.
 *
 * Builds a catalog from enabled components, then reads each page file and
 * validates its elements by converting to a json-render spec and running
 * `catalog.validate()`.
 */
export async function validatePages(
  discoveryResult: DiscoveryResult,
): Promise<{ results: Result[] }> {
  const ajv = new Ajv();
  addFormats(ajv);
  const validatePageSpec = ajv.compile(pageSpecSchema);

  const metadata = await loadComponentsMetadata(discoveryResult);
  const context = buildElementsValidationContext(metadata);
  const discoveredPages = discoveryResult.pages;
  const results: Result[] = [];

  for (const page of discoveredPages) {
    const fileName = path.basename(page.path);

    try {
      const fileContent = await fs.readFile(page.path, 'utf-8');
      const spec = JSON.parse(fileContent) as Record<string, unknown>;

      const details: { heading?: string; content: string }[] = [];

      // Validate the page file structure against the page spec schema.
      if (!validatePageSpec(spec)) {
        for (const error of validatePageSpec.errors ?? []) {
          details.push({
            heading: error.instancePath || undefined,
            content:
              error.keyword === 'additionalProperties' &&
              error.params?.additionalProperty
                ? `${error.message}: '${error.params.additionalProperty}'`
                : (error.message ?? 'Unknown validation error'),
          });
        }
      }

      // Validate page elements against the component catalog.
      const elements = (spec.elements as AuthoredSpecElementMap) ?? {};
      const elementsResult = validateElements(elements, context);
      if (elementsResult.details) {
        details.push(...elementsResult.details);
      }

      results.push({
        itemName: page.slug,
        success: details.length === 0,
        details: details.length > 0 ? details : undefined,
      });
    } catch (error) {
      results.push({
        itemName: page.slug,
        success: false,
        details: [
          {
            heading: fileName,
            content:
              error instanceof Error
                ? error.message
                : `Unknown error: ${String(error)}`,
          },
        ],
      });
    }
  }

  return { results };
}
