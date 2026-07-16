/**
 * @file
 * Next.js component registry file generation.
 */

import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { buildComponentRegistryModule } from '@drupal-canvas/headless/component-registry';

const NEXT_COMPONENT_REGISTRY_PATH = '.canvas/components.ts';

/** Writes Next.js's generated registry and returns its absolute path. */
export async function writeComponentRegistryModule(
  projectRoot: string,
): Promise<string> {
  const modulePath = path.resolve(projectRoot, NEXT_COMPONENT_REGISTRY_PATH);
  const source = await buildComponentRegistryModule({
    projectRoot,
    modulePath,
  });
  await mkdir(path.dirname(modulePath), { recursive: true });

  let currentSource: string | undefined;
  try {
    currentSource = await readFile(modulePath, 'utf8');
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
    await writeFile(modulePath, source);
  }
  return modulePath;
}
