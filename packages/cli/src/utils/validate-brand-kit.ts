import fs from 'fs/promises';
import path from 'path';
import Ajv from 'ajv/dist/2020.js';
import { BRAND_KIT_CONFIG_FILENAME } from '@drupal-canvas/discovery';

import brandKitSchema from '../../../workbench/src/lib/schemas/brand-kit.schema.json';
import { collectColorConfigErrors } from '../lib/colors/color-validate.js';
import { collectFontConfigErrors } from '../lib/fonts/font-validate.js';

import type { FontsConfig } from '../config.js';
import type { Result } from '../types/Result.js';

/**
 * Validates canvas.brand-kit.json: JSON syntax, structure against the brand
 * kit JSON Schema, and the semantic checks a schema cannot express (two keys
 * naming the same variable, numeric ranges inside color strings, font files
 * existing on disk). Returns no results when the file does not exist.
 */
export async function validateBrandKit(
  projectRoot: string,
): Promise<{ results: Result[] }> {
  const configPath = path.resolve(projectRoot, BRAND_KIT_CONFIG_FILENAME);
  let raw: string;
  try {
    raw = await fs.readFile(configPath, 'utf-8');
  } catch {
    // The brand kit file is optional.
    return { results: [] };
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch (error) {
    return {
      results: [
        {
          itemName: BRAND_KIT_CONFIG_FILENAME,
          success: false,
          details: [
            {
              content: `Invalid JSON: ${error instanceof Error ? error.message : String(error)}`,
            },
          ],
        },
      ],
    };
  }

  const details: { heading?: string; content: string }[] = [];

  // allErrors so every entry's problem reports in one run, matching the
  // semantic validators.
  const ajv = new Ajv({ allErrors: true, allowUnionTypes: true });
  const validateSchema = ajv.compile(brandKitSchema);
  if (!validateSchema(parsed)) {
    // oneOf entries repeat some errors across branches; report each once.
    const seen = new Set<string>();
    for (const error of validateSchema.errors ?? []) {
      const heading = error.instancePath || undefined;
      const content =
        error.keyword === 'additionalProperties' &&
        error.params?.additionalProperty
          ? `${error.message}: '${error.params.additionalProperty}'`
          : (error.message ?? 'Unknown validation error');
      const key = `${heading ?? ''}\0${content}`;
      if (seen.has(key)) {
        continue;
      }
      seen.add(key);
      details.push({ heading, content });
    }
  }

  const file =
    parsed && typeof parsed === 'object' && !Array.isArray(parsed)
      ? (parsed as Record<string, unknown>)
      : {};

  if ('colors' in file) {
    for (const error of collectColorConfigErrors(
      file.colors as Parameters<typeof collectColorConfigErrors>[0],
    )) {
      details.push({ heading: 'colors', content: error });
    }
  }

  if (
    file.fonts &&
    typeof file.fonts === 'object' &&
    !Array.isArray(file.fonts)
  ) {
    for (const error of await collectFontConfigErrors(
      file.fonts as FontsConfig,
      projectRoot,
    )) {
      details.push({ heading: 'fonts', content: error });
    }
  }

  return {
    results: [
      {
        itemName: BRAND_KIT_CONFIG_FILENAME,
        success: details.length === 0,
        details: details.length > 0 ? details : undefined,
      },
    ],
  };
}
