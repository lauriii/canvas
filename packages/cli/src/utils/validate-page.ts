import fs from 'fs/promises';
import path from 'path';
import addFormats from 'ajv-formats';
import Ajv from 'ajv/dist/2020.js';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import pageSpecSchema from '../../../workbench/src/lib/schemas/page-spec.schema.json';
import {
  formatPagePathAliasChangeError,
  getPathAliasChange,
} from './page-path-alias-validation';
import { pageResultName } from './page-result-name';
import { collectUnreconciledMediaProps } from './prop-transforms';
import {
  buildElementsValidationContext,
  validateElements,
} from './validate-elements';

import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { PageListItem } from '../types/Page';
import type { Result } from '../types/Result';

export interface PageValidationOptions {
  remotePageByUuid?: Map<string, PageListItem>;
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
  options: PageValidationOptions = {},
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
      const pageTitle = typeof spec.title === 'string' ? spec.title : undefined;

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

      // Prefer the UUID from the parsed spec, but fall back to discovery so
      // remote-aware validation can still run if discovery already found one.
      let uuid: string | null = null;
      if (typeof spec.uuid === 'string') {
        uuid = spec.uuid;
      } else if (typeof page.uuid === 'string') {
        uuid = page.uuid;
      }
      const pagePath = typeof spec.path === 'string' ? spec.path : '';
      if (options.remotePageByUuid && uuid) {
        const remotePage = options.remotePageByUuid.get(uuid);
        const pathAliasChange = remotePage
          ? getPathAliasChange(pagePath, remotePage.path)
          : null;
        if (pathAliasChange) {
          details.push({
            heading: 'path',
            content: formatPagePathAliasChangeError(pathAliasChange),
          });
        }
      }

      const success = details.length === 0 && elementsResult.success;
      results.push({
        itemName: pageResultName(pageTitle, page, { includePath: !success }),
        success,
        details: details.length > 0 ? details : undefined,
      });
    } catch (error) {
      results.push({
        itemName: pageResultName(undefined, page, { includePath: true }),
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
