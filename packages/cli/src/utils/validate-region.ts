import fs from 'fs/promises';
import path from 'path';
import addFormats from 'ajv-formats';
import Ajv from 'ajv/dist/2020.js';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import regionSpecSchema from '../../../workbench/src/lib/schemas/region-spec.schema.json';
import { collectUnreconciledMediaProps } from './prop-transforms';
import {
  buildElementsValidationContext,
  validateElements,
} from './validate-elements';

import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { Result } from '../types/Result';

export async function validateRegions(
  discoveryResult: DiscoveryResult,
): Promise<{ results: Result[] }> {
  const ajv = new Ajv();
  addFormats(ajv);
  const validateRegionSpec = ajv.compile(regionSpecSchema);

  const metadata = await loadComponentsMetadata(discoveryResult);
  const context = buildElementsValidationContext(metadata);
  const results: Result[] = [];

  for (const region of discoveryResult.regions) {
    const fileName = path.basename(region.path);
    const itemName = region.region;

    try {
      const fileContent = await fs.readFile(region.path, 'utf-8');
      const spec = JSON.parse(fileContent) as Record<string, unknown>;

      const details: { heading?: string; content: string }[] = [];

      if (!validateRegionSpec(spec)) {
        for (const error of validateRegionSpec.errors ?? []) {
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

      const elements = (spec.elements as AuthoredSpecElementMap) ?? {};
      const elementsResult = validateElements(elements, context);
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
          content: `Unreconciled external media URL "${entry.src}". Run \`npx canvas reconcile-media\` to resolve.`,
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

  return { results };
}
