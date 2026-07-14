/**
 * @file
 * The manifest reader, deliberately separated from the build-time writer
 * (./manifest.ts) and never imported by the endpoint handler: bundlers'
 * file tracers (Next.js's NFT) trace every filesystem operation reachable
 * from a route's module graph, and a path they cannot fold statically
 * makes them sweep the entire project into the route's output. Adapters
 * inline the manifest into their server bundles instead and hand the
 * handler a loader; this module serves build/config-time code and custom
 * setups that manage their own deployment shape.
 */

import { readFile } from 'node:fs/promises';
import path from 'node:path';

import { COMPONENT_METADATA_PAYLOAD_VERSION } from './payload-version';

import type { ComponentMetadataPayload } from './component-metadata';

/**
 * Where the build-time component manifest lives, relative to the project
 * root. Deliberately not under a publicly served directory (such as
 * Next.js's `public/`): the metadata endpoint that serves the manifest is
 * authenticated, so the file must not be reachable unauthenticated by
 * another route. The `.canvas/` directory is a build artifact and belongs
 * in .gitignore.
 */
export const COMPONENT_MANIFEST_PATH = '.canvas/components.manifest.json';

export interface ComponentManifestOptions {
  /** The app project root. Default: process.cwd(). */
  projectRoot?: string;
  /** Manifest location relative to the project root. */
  manifestPath?: string;
}

function resolveManifestPath(options: ComponentManifestOptions): string {
  return path.resolve(
    options.projectRoot ?? process.cwd(),
    options.manifestPath ?? COMPONENT_MANIFEST_PATH,
  );
}

/**
 * Reads a previously written manifest (see writeComponentManifest() in
 * ./manifest.ts). Returns null when the file does not exist; rejects when
 * the file exists but is unreadable or carries an unknown payload version
 * — a corrupt manifest is an error, not an empty registry.
 */
export async function readComponentManifest(
  options: ComponentManifestOptions = {},
): Promise<ComponentMetadataPayload | null> {
  const manifestPath = resolveManifestPath(options);
  let content: string;
  try {
    content = await readFile(manifestPath, 'utf-8');
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return null;
    }
    throw error;
  }
  const payload = JSON.parse(content) as ComponentMetadataPayload;
  if (payload.version !== COMPONENT_METADATA_PAYLOAD_VERSION) {
    throw new Error(
      `Unsupported component manifest version ${String(payload.version)} in ${manifestPath}; expected ${COMPONENT_METADATA_PAYLOAD_VERSION}. Rebuild the app to regenerate the manifest.`,
    );
  }
  return payload;
}
