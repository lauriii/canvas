/**
 * @file
 * The build-time manifest writer. Config-time code only (withCanvas(),
 * the Astro/Nuxt/TanStack build plugins): it runs discovery, whose
 * dynamic filesystem work must never enter a route's module graph — the
 * request-time reader lives in ./manifest-read.ts for exactly that
 * reason.
 */

import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

import { buildComponentMetadataPayload } from './component-metadata';
import { COMPONENT_MANIFEST_PATH } from './manifest-read';

import type { ComponentMetadataPayload } from './component-metadata';
import type { ComponentManifestOptions } from './manifest-read';

/**
 * Runs component discovery and writes the manifest the metadata endpoint
 * serves in production, where the component sources are typically absent at
 * runtime (standalone output, serverless). Returns the payload it wrote.
 */
export async function writeComponentManifest(
  options: ComponentManifestOptions = {},
): Promise<ComponentMetadataPayload> {
  const payload = await buildComponentMetadataPayload({
    projectRoot: options.projectRoot,
  });
  const manifestPath = path.resolve(
    options.projectRoot ?? process.cwd(),
    options.manifestPath ?? COMPONENT_MANIFEST_PATH,
  );
  await mkdir(path.dirname(manifestPath), { recursive: true });
  await writeFile(manifestPath, `${JSON.stringify(payload, null, 2)}\n`);
  return payload;
}
