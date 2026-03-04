import { promises as fs } from 'node:fs';
import path from 'node:path';

import { FONT_EXTENSIONS } from '../lib/asset-extensions';

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
}

export interface ComponentManifest {
  js: string;
  css?: string;
  metadata: string;
}

export interface Manifest {
  vendor: Record<string, string>;
  local: Record<string, string>;
  fonts: Record<string, string>;
}

export interface ManifestResult {
  success: boolean;
  manifestPath: string;
  manifest: Manifest;
  error?: string;
  warnings?: string[];
}

/**
 * Check if a package name is a vendor font (e.g., @fontsource/* packages).
 */
function isVendorFont(pkg: string): boolean {
  return pkg.startsWith('@fontsource/');
}

/**
 * Check if a path refers to a local font file based on its extension.
 */
function isLocalFont(filePath: string): boolean {
  const ext = path.extname(filePath).toLowerCase();
  return (FONT_EXTENSIONS as readonly string[]).includes(ext);
}

/**
 * Generate a canvas-manifest.json file with component-centric format.
 * Groups JS, CSS, and metadata under each component, with vendor
 * and local alias imports as separate top-level keys.
 */
export async function generateManifest(
  input: ManifestInput,
): Promise<ManifestResult> {
  const { outputDir, vendorImportMap, localImportMap } = input;
  const absoluteOutputDir = path.resolve(outputDir);
  const manifestPath = path.join(absoluteOutputDir, 'canvas-manifest.json');

  const manifest: Manifest = {
    vendor: {},
    local: {},
    fonts: {},
  };

  const warnings: string[] = [];

  try {
    // 1. Build vendor section from vendor import map (extract fonts to fonts section)
    if (vendorImportMap) {
      for (const [pkg, vendorPath] of Object.entries(vendorImportMap.imports)) {
        if (isVendorFont(pkg)) {
          manifest.fonts[pkg] = vendorPath;
        } else {
          manifest.vendor[pkg] = vendorPath;
        }
      }
    }

    // 2. Add local alias imports (extract fonts to fonts section)
    for (const [source, outputPath] of Object.entries(localImportMap)) {
      if (isLocalFont(source)) {
        manifest.fonts[source] = outputPath;
      } else {
        manifest.local[source] = outputPath;
      }
    }

    // Sort all sections for consistent output
    manifest.vendor = sortObjectByKeys(manifest.vendor);
    manifest.local = sortObjectByKeys(manifest.local);
    manifest.fonts = sortObjectByKeys(manifest.fonts);

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
