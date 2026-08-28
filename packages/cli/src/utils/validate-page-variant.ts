import fs from 'fs/promises';
import path from 'path';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';
import { createCanvasAjv } from '@drupal-canvas/json-schema-validation';

import pageTemplateSpecSchema from '../../../workbench/src/lib/schemas/page-template-spec.schema.json';
import { collectUnreconciledMediaProps } from './prop-transforms';
import {
  buildElementsValidationContext,
  validateElements,
} from './validate-elements';

import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { Result } from '../types/Result';

/**
 * Removes `marker.*` elements (and references to them) before catalog
 * validation. The intrinsic "Page content" marker is part of every page
 * template but is not a locally discovered component and carries no props or
 * slots; the server enforces the marker rules on push.
 */
function withoutMarkerElements(
  elements: AuthoredSpecElementMap,
): AuthoredSpecElementMap {
  const markerIds = new Set(
    Object.entries(elements)
      .filter(([, element]) => element.type.startsWith('marker.'))
      .map(([id]) => id),
  );
  if (markerIds.size === 0) {
    return elements;
  }
  const result: AuthoredSpecElementMap = {};
  for (const [id, element] of Object.entries(elements)) {
    if (markerIds.has(id)) {
      continue;
    }
    if (!element.slots) {
      result[id] = element;
      continue;
    }
    const slots = Object.fromEntries(
      Object.entries(element.slots).map(([slot, children]) => [
        slot,
        children.filter((child) => !markerIds.has(child)),
      ]),
    );
    result[id] = { ...element, slots };
  }
  return result;
}

export async function validatePageTemplates(
  discoveryResult: DiscoveryResult,
): Promise<{ results: Result[] }> {
  const validatePageTemplateSpec = createCanvasAjv().compile(
    pageTemplateSpecSchema,
  );

  const metadata = await loadComponentsMetadata(discoveryResult);
  const context = buildElementsValidationContext(metadata);
  const results: Result[] = [];
  const defaultIds: string[] = [];

  for (const pageTemplate of discoveryResult.pageTemplates) {
    const fileName = path.basename(pageTemplate.path);
    const itemName = pageTemplate.id;

    try {
      const fileContent = await fs.readFile(pageTemplate.path, 'utf-8');
      const spec = JSON.parse(fileContent) as Record<string, unknown>;

      const details: { heading?: string; content: string }[] = [];

      if (!validatePageTemplateSpec(spec)) {
        for (const error of validatePageTemplateSpec.errors ?? []) {
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

      if (spec.default === true) {
        defaultIds.push(itemName);
        if (spec.status === false) {
          details.push({
            heading: 'status',
            content:
              'A page template with "default": true must not set "status": false.',
          });
        }
      }

      const elements = (spec.elements as AuthoredSpecElementMap) ?? {};

      // Mirror the server's PageVariantHasContentMarkerConstraint here, so a
      // push fails with an actionable message instead of a server-side 422.
      const contentMarkerCount = Object.values(elements).filter(
        (element) => element.type === 'marker.page_content',
      ).length;
      if (contentMarkerCount !== 1) {
        details.push({
          heading: 'elements',
          content:
            contentMarkerCount === 0
              ? 'Every page template contains exactly one "Page content" marker: add an element with type "marker.page_content" where the page content should render.'
              : `Every page template contains exactly one "Page content" marker, found ${contentMarkerCount}.`,
        });
      }

      const elementsResult = validateElements(
        withoutMarkerElements(elements),
        context,
      );
      if (!elementsResult.success && elementsResult.details) {
        details.push(...elementsResult.details);
      }

      const unreconciledMedia = collectUnreconciledMediaProps(
        elements,
        metadata,
      );
      for (const entry of unreconciledMedia) {
        details.push({
          heading: `elements.${entry.elementId}.props.${entry.propName}`,
          content: `Unreconciled external media URL "${entry.src}". Run \`canvas reconcile-media\` to resolve.`,
        });
      }

      results.push({
        itemName,
        success: details.length === 0 && elementsResult.success,
        details: details.length > 0 ? details : undefined,
      });
    } catch (error) {
      results.push({
        itemName,
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

  // The site has one default page variant, so at most one authored file may
  // claim it.
  if (defaultIds.length > 1) {
    for (const id of defaultIds) {
      const result = results.find((r) => r.itemName === id);
      if (result) {
        result.success = false;
        (result.details ??= []).push({
          content: `Only one page template may set "default": true, found ${defaultIds.length} (${defaultIds.join(', ')}).`,
        });
      }
    }
  }

  return { results };
}
