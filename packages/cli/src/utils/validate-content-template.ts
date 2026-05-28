import fs from 'fs/promises';
import path from 'path';
import addFormats from 'ajv-formats';
import Ajv from 'ajv/dist/2020.js';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import contentTemplateSpecSchema from '../../../workbench/src/lib/schemas/content-template-spec.schema.json';
import { authoredElementMapToComponentTree } from './authored-elements';
import { collectUnreconciledMediaProps } from './prop-transforms';

import type {
  ComponentMetadata,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { ApiService } from '../services/api';
import type { Result } from '../types/Result';

export interface ContentTemplateValidationContext {
  allComponentIds: Set<string>;
  enabledComponentIds: Set<string>;
}

export function buildContentTemplateValidationContext(
  metadata: ComponentMetadata[],
): ContentTemplateValidationContext {
  return {
    allComponentIds: new Set(metadata.map((m) => `js.${m.machineName}`)),
    enabledComponentIds: new Set(
      metadata.filter((m) => m.status).map((m) => `js.${m.machineName}`),
    ),
  };
}

interface ValidationDetail {
  heading?: string;
  content: string;
}

export function validateContentTemplateElements(
  elements: AuthoredSpecElementMap,
  context: ContentTemplateValidationContext,
): ValidationDetail[] {
  const details: ValidationDetail[] = [];

  for (const [elementId, element] of Object.entries(elements)) {
    if (!context.allComponentIds.has(element.type)) {
      details.push({
        heading: `elements.${elementId}.type`,
        content: `Unknown component "${element.type}". Make sure it is present in the component directory.`,
      });
      continue;
    }
    if (!context.enabledComponentIds.has(element.type)) {
      details.push({
        heading: `elements.${elementId}.type`,
        content: `Component "${element.type}" is disabled. Set "status: true" in its component.yml to enable it.`,
      });
    }
  }

  const elementIds = new Set(Object.keys(elements));
  for (const [elementId, element] of Object.entries(elements)) {
    if (!element.slots) continue;
    for (const [slotName, childIds] of Object.entries(element.slots)) {
      childIds.forEach((childId, index) => {
        if (!elementIds.has(childId)) {
          details.push({
            heading: `elements.${elementId}.slots.${slotName}.${index}`,
            content: `Unknown element "${childId}" referenced from slot "${slotName}".`,
          });
        }
      });
    }
  }

  return details;
}

export async function validateContentTemplates(
  discoveryResult: DiscoveryResult,
  options?: {
    apiService?: Pick<
      ApiService,
      | 'fetchPreviewEntitySuggestions'
      | 'validateContentTemplateDraft'
      | 'listComponentVersions'
      | 'listViewModes'
    >;
  },
): Promise<{ results: Result[] }> {
  const ajv = new Ajv({ allErrors: true });
  addFormats(ajv);
  const validateSpec = ajv.compile(contentTemplateSpecSchema);

  const metadata = await loadComponentsMetadata(discoveryResult);
  const context = buildContentTemplateValidationContext(metadata);
  const templates = discoveryResult.contentTemplates;
  const results: Result[] = [];

  const apiService = options?.apiService;
  let componentVersions: Map<string, string> | undefined;
  let viewModes: Awaited<ReturnType<ApiService['listViewModes']>> | undefined;
  if (apiService) {
    try {
      [componentVersions, viewModes] = await Promise.all([
        apiService.listComponentVersions(),
        apiService.listViewModes(),
      ]);
    } catch {
      // Server unreachable — skip draft validation entirely.
    }
  }
  const suggestionCache = new Map<string, string | null>();

  for (const template of templates) {
    const fileName = path.basename(template.path);
    try {
      const fileContent = await fs.readFile(template.path, 'utf-8');
      const spec = JSON.parse(fileContent) as Record<string, unknown>;

      const details: ValidationDetail[] = [];

      if (!validateSpec(spec)) {
        for (const error of validateSpec.errors ?? []) {
          details.push({
            heading: error.instancePath || undefined,
            content:
              error.keyword === 'additionalProperties' &&
              error.params?.additionalProperty
                ? `${error.message}: '${String(error.params.additionalProperty)}'`
                : (error.message ?? 'Unknown validation error'),
          });
        }
      }

      const elements = (spec.elements as AuthoredSpecElementMap) ?? {};
      details.push(...validateContentTemplateElements(elements, context));

      const unreconciledMedia = collectUnreconciledMediaProps(
        elements,
        metadata,
      );
      for (const entry of unreconciledMedia) {
        details.push({
          heading: `elements.${entry.elementId}.props.${entry.propName}`,
          content: `Unreconciled external media URL "${entry.src}". Run \`npx canvas reconcile-media\` to resolve.`,
        });
      }

      if (
        viewModes &&
        typeof spec.entityType === 'string' &&
        typeof spec.bundle === 'string' &&
        typeof spec.viewMode === 'string'
      ) {
        const bundleViewModes = viewModes[spec.entityType]?.[spec.bundle];
        if (!bundleViewModes) {
          details.push({
            heading: 'bundle',
            content: `Unknown entity type or bundle "${spec.entityType}.${spec.bundle}".`,
          });
        } else if (!(spec.viewMode in bundleViewModes)) {
          const available = Object.keys(bundleViewModes).sort().join(', ');
          details.push({
            heading: 'viewMode',
            content: `Unknown view mode "${spec.viewMode}" for ${spec.entityType}.${spec.bundle}. Available: ${available}.`,
          });
        }
      }

      // Best-effort server-side draft validation when an API service and
      // the required metadata are available.
      if (
        details.length === 0 &&
        apiService &&
        componentVersions &&
        typeof spec.entityType === 'string' &&
        typeof spec.bundle === 'string' &&
        typeof spec.viewMode === 'string'
      ) {
        const cacheKey = `${spec.entityType}:${spec.bundle}`;
        if (!suggestionCache.has(cacheKey)) {
          const suggestions = await apiService.fetchPreviewEntitySuggestions(
            spec.entityType,
            spec.bundle,
          );
          suggestionCache.set(
            cacheKey,
            suggestions.length > 0 ? suggestions[0].id : null,
          );
        }
        const previewEntityId = suggestionCache.get(cacheKey)!;
        if (previewEntityId) {
          try {
            const tree = authoredElementMapToComponentTree(
              elements,
              componentVersions,
            );
            await apiService.validateContentTemplateDraft(
              spec.entityType,
              previewEntityId,
              spec.bundle,
              spec.viewMode,
              tree,
            );
          } catch (error) {
            details.push({
              heading: 'Draft validation',
              content:
                error instanceof Error
                  ? error.message
                  : `Draft validation failed: ${String(error)}`,
            });
          }
        }
      }

      results.push({
        itemName: template.slug,
        success: details.length === 0,
        details: details.length > 0 ? details : undefined,
      });
    } catch (error) {
      results.push({
        itemName: template.slug,
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
