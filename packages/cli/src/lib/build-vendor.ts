import { promises as fs } from 'node:fs';
import path from 'path';
import { build as viteBuild } from 'vite';
import { FONT_EXTENSIONS } from '@drupal-canvas/discovery';

import {
  createCanvasViteBuildConfig,
  DRUPAL_CANVAS_EXTERNALS,
} from './vite-build-config';

import type { Manifest } from 'vite';

export interface ImportMap {
  imports: Record<string, string>;
}

export interface VendorBundleResult {
  success: boolean;
  importMap: ImportMap;
  bundledPackages: string[];
  sharedChunks: string[];
}

/**
 * Convert a package name to a safe filename.
 * e.g., "@radix-ui/react-dialog" -> "radix-ui--react-dialog"
 */
function packageNameToFileName(packageName: string): string {
  return packageName.replace(/\//g, '--');
}

/**
 * Create entry points object for Vite build.
 * Maps output names to package names.
 */
function createEntryPoints(packages: Set<string>): Record<string, string> {
  const entries: Record<string, string> = {};
  for (const pkg of packages) {
    const flatName = packageNameToFileName(pkg);
    entries[flatName] = pkg;
  }
  return entries;
}

/**
 * Check if a package should be treated as external.
 */
function isExternalPackage(packageName: string): boolean {
  return DRUPAL_CANVAS_EXTERNALS.some(
    (ext) => packageName === ext || packageName.startsWith(`${ext}/`),
  );
}

/**
 * Bundle third-party dependencies using Vite.
 * Each package is split into its own file in the vendor directory.
 * Packages provided by Drupal Canvas are excluded (marked as external).
 */
export async function bundleVendorDependencies(
  packages: Set<string>,
  scanRoot: string,
  aliasBaseDir: string,
  outputDir: string,
): Promise<VendorBundleResult> {
  const absoluteOutputDir = path.resolve(outputDir);
  const vendorDir = path.join(absoluteOutputDir, 'vendor');
  const importMap: ImportMap = { imports: {} };
  const bundledPackages: string[] = [];

  // Filter out packages that are provided by Drupal Canvas
  const packagesToBundle = new Set<string>();
  for (const pkg of packages) {
    if (!isExternalPackage(pkg)) {
      packagesToBundle.add(pkg);
    }
  }

  if (packagesToBundle.size === 0) {
    return {
      success: true,
      importMap,
      bundledPackages,
      sharedChunks: [],
    };
  }

  // Create vendor output directory
  await fs.mkdir(vendorDir, { recursive: true });

  // Create entry points - each package becomes its own entry
  const entries = createEntryPoints(packagesToBundle);

  const baseConfig = createCanvasViteBuildConfig({
    scanRoot,
    aliasBaseDir,
  });

  // Bundle with Vite using direct package entries
  await viteBuild({
    ...baseConfig,
    build: {
      outDir: vendorDir,
      emptyOutDir: true,
      manifest: true, // Generate manifest for reliable import map building
      rollupOptions: {
        input: entries,
        external: DRUPAL_CANVAS_EXTERNALS,
        treeshake: false,
        preserveEntrySignatures: 'exports-only',
        output: {
          format: 'esm',
          entryFileNames: '[name]-[hash].js',
          chunkFileNames: '[name]-[hash].js',
        },
      },
      cssCodeSplit: true,
      minify: true,
      sourcemap: false,
    },
  });

  // Read Vite's build manifest to build the import map
  const viteManifestPath = path.join(vendorDir, '.vite', 'manifest.json');
  const viteManifestContent = await fs.readFile(viteManifestPath, 'utf-8');
  const viteManifest: Manifest = JSON.parse(viteManifestContent);

  // Extract shared chunks (non-entry files), excluding font files
  const sharedChunks: string[] = [];

  // Build import map from manifest entries using our entry name -> package name mapping
  for (const info of Object.values(viteManifest)) {
    if (!info.isEntry) {
      const ext = path.extname(info.file).toLowerCase();
      // Do not include font files in shared chunks, that will be handled
      // in a future issue.
      if (!(FONT_EXTENSIONS as readonly string[]).includes(ext)) {
        sharedChunks.push(`./vendor/${info.file}`);
      }
    }
    // Get the entry name from manifest (JS has `name` field)
    let entryName: string | undefined;
    if (info.name) {
      entryName = info.name;
    }
    // Look up the original package name from our entries mapping
    const packageName = entryName ? entries[entryName] : undefined;
    if (!packageName) continue;
    importMap.imports[packageName] = `./vendor/${info.file}`;
    bundledPackages.push(packageName);
  }

  return {
    success: true,
    importMap,
    bundledPackages,
    sharedChunks,
  };
}
