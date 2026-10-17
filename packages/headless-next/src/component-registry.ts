/**
 * @file
 * Next.js component registry and brand kit stylesheet file generation.
 */

import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import {
  buildBrandKitStylesheet,
  buildComponentRegistryModule,
} from '@drupal-canvas/headless/component-registry';

const NEXT_COMPONENT_REGISTRY_PATH = '.canvas/components.ts';
const NEXT_BRAND_KIT_CSS_PATH = '.canvas/brand-kit.css';

/** Writes Next.js's generated registry and returns its absolute path. */
export async function writeComponentRegistryModule(
  projectRoot: string,
): Promise<string> {
  const modulePath = path.resolve(projectRoot, NEXT_COMPONENT_REGISTRY_PATH);
  await writeIfChanged(
    modulePath,
    await buildComponentRegistryModule({ projectRoot, modulePath }),
  );
  return modulePath;
}

/**
 * Writes the generated brand kit stylesheet and returns its absolute path.
 * It is written even when empty, so the app's import always resolves.
 */
export async function writeBrandKitStylesheet(
  projectRoot: string,
): Promise<string> {
  const stylesheetPath = path.resolve(projectRoot, NEXT_BRAND_KIT_CSS_PATH);
  await writeIfChanged(
    stylesheetPath,
    await buildBrandKitStylesheet({
      projectRoot,
      stylesheetPath,
      onFontError: (family, error) =>
        console.warn(
          `[canvas] Could not resolve brand kit font "${family}": ${String(error)}`,
        ),
    }),
  );
  return stylesheetPath;
}

async function writeIfChanged(filePath: string, source: string): Promise<void> {
  await mkdir(path.dirname(filePath), { recursive: true });

  let currentSource: string | undefined;
  try {
    currentSource = await readFile(filePath, 'utf8');
  } catch (error) {
    if (
      !(error instanceof Error) ||
      !('code' in error) ||
      error.code !== 'ENOENT'
    ) {
      throw error;
    }
  }

  if (currentSource !== source) {
    await writeFile(filePath, source);
  }
}
