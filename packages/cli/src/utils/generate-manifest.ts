import { promises as fs } from 'node:fs';
import path from 'node:path';

interface ImportMap {
  imports: Record<string, string>;
}

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

/**
 * The original source behind a `local` artifact: its relative disk path and,
 * for text modules, its verbatim source code. Where `local` records the built
 * artifact a component loads at runtime, this records what a subsequent CLI
 * pull writes back to disk to reconstruct the editable source file.
 */
export interface LocalSource {
  path: string;
  source?: string;
}

/**
 * The verbatim source of a local module that was bundled into another artifact
 * and so has no standalone runtime artifact. Kept only so a pull can restore
 * the editable file. Keyed by disk `path` (relative deps have no specifier);
 * carries no `uri` and is never part of the runtime import map.
 *
 * Structurally a `LocalSource` whose `source` is always present: a bundled
 * module is always text, so there is no binary-asset (source-less) case.
 */
export type BundledSource = Required<LocalSource>;

export interface ManifestInput {
  outputDir: string;
  vendorImportMap: ImportMap | null;
  localImportMap: Record<string, string>;
  localSources?: Record<string, LocalSource>;
  bundledSources?: BundledSource[];
  sharedChunks: string[];
}

export interface Manifest {
  vendor: Record<string, string>;
  local: Record<string, string>;
  /**
   * Original sources for `local` artifacts, keyed by the same specifier used in
   * `local`. Absent when no local artifacts carry a source to reconstruct.
   */
  localSources?: Record<string, LocalSource>;
  /**
   * Verbatim sources of local modules bundled into other artifacts, so a pull
   * can restore them.
   */
  bundledSources?: BundledSource[];
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
  const {
    outputDir,
    vendorImportMap,
    localImportMap,
    localSources,
    bundledSources,
    sharedChunks,
  } = input;
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

    // 3. Add original sources (disk path + text source) for the local imports.
    if (localSources && Object.keys(localSources).length > 0) {
      manifest.localSources = sortObjectByKeys(localSources);
    }

    // 4. Add verbatim sources of local modules bundled into other artifacts, so
    // a pull can restore them. These carry no runtime artifact.
    if (bundledSources && bundledSources.length > 0) {
      manifest.bundledSources = [...bundledSources].sort((a, b) =>
        a.path.localeCompare(b.path),
      );
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
