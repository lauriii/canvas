import { promises as fs } from 'node:fs';
import path from 'path';
import { build as viteBuild } from 'vite';

import { createCanvasViteBuildConfig } from './vite-build-config';

import type { Manifest } from 'vite';

// The following packages are bundled by Drupal Canvas, and are provided by
// default in its import map. We don't need to bundle them.
// @todo: Reconcile this in https://www.drupal.org/i/3552914.
const DRUPAL_CANVAS_EXTERNALS = [
  'preact',
  'preact/hooks',
  'react/jsx-runtime',
  'react',
  'react-dom',
  'react-dom/client',
  'clsx',
  'class-variance-authority',
  'tailwind-merge',
  'drupal-jsonapi-params',
  'swr',
  'drupal-canvas',
];

export interface ImportMap {
  imports: Record<string, string>;
}

export interface VendorBundleResult {
  success: boolean;
  importMap: ImportMap;
  bundledPackages: string[];
  error?: string;
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
    };
  }

  try {
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
          output: {
            format: 'es',
            entryFileNames: '[name]-[hash].js',
            chunkFileNames: (chunkInfo) => {
              // For shared chunks, derive name from the module IDs
              const moduleIds = chunkInfo.moduleIds || [];

              // Find the first node_modules package in the chunk
              for (const id of moduleIds) {
                const nodeModulesMatch = id.match(
                  /node_modules\/(@[^/]+\/[^/]+|[^/]+)/,
                );
                if (nodeModulesMatch) {
                  const packageName = nodeModulesMatch[1];
                  const safeName = packageNameToFileName(packageName);
                  return `shared/${safeName}-[hash].js`;
                }
              }

              // Fallback to default name
              return `shared/${chunkInfo.name}-[hash].js`;
            },
          },
        },
        cssCodeSplit: true,
        minify: true,
        sourcemap: false,
      },
    });

    // Read Vite's build manifest to build the import map
    const manifestPath = path.join(vendorDir, '.vite', 'manifest.json');
    const manifestContent = await fs.readFile(manifestPath, 'utf-8');
    const manifest: Manifest = JSON.parse(manifestContent);

    // Build import map from manifest entries using our entry name → package name mapping
    for (const info of Object.values(manifest)) {
      if (!info.isEntry) continue;

      // Get the entry name from manifest (JS has `name` field, CSS needs extraction from filename)
      let entryName: string | undefined;
      if (info.name) {
        entryName = info.name;
      } else if (info.file.endsWith('.css')) {
        // Find which entry name this file belongs to by checking if filename starts with it
        // e.g., "assets/@fontsource--roboto-HASH.css" matches entry "@fontsource--roboto"
        // Sort by length descending to match longest entry first (e.g., "swiper--css" before "swiper")
        const fileName = path.basename(info.file, '.css');
        const sortedEntries = Object.keys(entries).sort(
          (a, b) => b.length - a.length,
        );
        for (const candidateEntry of sortedEntries) {
          if (fileName.startsWith(candidateEntry + '-')) {
            entryName = candidateEntry;
            break;
          }
        }
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
    };
  } catch (error) {
    return {
      success: false,
      importMap,
      bundledPackages,
      error: error instanceof Error ? error.message : String(error),
    };
  }
}
