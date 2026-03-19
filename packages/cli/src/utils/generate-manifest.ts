import { promises as fs } from 'node:fs';
import path from 'node:path';

import type { ImportMap } from '../lib/build-vendor';

/**
 * Sort an object's keys alphabetically and return a new object.
 */
function sortObjectByKeys<T>(obj: Record<string, T>): Record<string, T> {
  const sorted: Record<string, T> = {};
  for (const key of Object.keys(obj).sort()) {
    sorted[key] = obj[key];
  }
  return sorted;
}

export interface ComponentManifestData {
  /** Machine name from component.yml */
  machineName: string;
  /** Absolute path to the JS entry file */
  entryPath: string;
  /** Relative directory from scan root */
  relativeDirectory: string;
  /** Absolute path to the component.yml metadata file */
  metadataPath: string;
}

export interface ManifestInput {
  outputDir: string;
  vendorImportMap: ImportMap | null;
  localImportMap: Record<string, string>;
  sharedChunks: string[];
}

export interface Manifest {
  vendor: Record<string, string>;
  local: Record<string, string>;
  shared: string[];
}

export interface ManifestResult {
  success: boolean;
  manifestPath: string;
  manifest: Manifest;
  error?: string;
  warnings?: string[];
}

/**
 * Generate a canvas-manifest.json file with component-centric format.
 * Groups JS, CSS, and metadata under each component, with vendor
 * and local alias imports as separate top-level keys.
 */
export async function generateManifest(
  input: ManifestInput,
): Promise<ManifestResult> {
  const { outputDir, vendorImportMap, localImportMap, sharedChunks } = input;
  const absoluteOutputDir = path.resolve(outputDir);
  const manifestPath = path.join(absoluteOutputDir, 'canvas-manifest.json');

  const manifest: Manifest = {
    vendor: {},
    local: {},
    shared: sharedChunks ?? [],
  };

  const warnings: string[] = [];

  try {
    // 1. Build vendor section from vendor import map
    if (vendorImportMap) {
      for (const [pkg, vendorPath] of Object.entries(vendorImportMap.imports)) {
        manifest.vendor[pkg] = vendorPath;
      }
    }

    // 2. Add local alias imports.
    for (const [source, outputPath] of Object.entries(localImportMap)) {
      manifest.local[source] = outputPath;
    }

    // Sort all sections for consistent output
    manifest.vendor = sortObjectByKeys(manifest.vendor);
    manifest.local = sortObjectByKeys(manifest.local);

    // Write canvas-manifest.json
    await fs.mkdir(absoluteOutputDir, { recursive: true });
    await fs.writeFile(manifestPath, JSON.stringify(manifest, null, 2));

    return {
      success: true,
      manifestPath,
      manifest,
      warnings: warnings.length > 0 ? warnings : undefined,
    };
  } catch (error) {
    return {
      success: false,
      manifestPath,
      manifest,
      error: error instanceof Error ? error.message : String(error),
      warnings: warnings.length > 0 ? warnings : undefined,
    };
  }
}
