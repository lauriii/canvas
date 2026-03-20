import { promises as fs } from 'node:fs';
import path from 'path';
import { build as viteBuild } from 'vite';
import { ASSET_EXTENSIONS } from '@drupal-canvas/discovery';

import {
  createCanvasViteBuildConfig,
  DRUPAL_CANVAS_EXTERNALS,
} from './vite-build-config';

import type { Manifest } from 'vite';

export interface LocalImportBuildResult {
  success: boolean;
  /** Maps original alias import strings to their compiled output paths.
   * JS imports map to .js paths, CSS side effect imports map to .css paths. */
  localImportMap: Record<string, string>;
  sharedChunks: string[];
  error?: string;
}

function isAssetFile(filePath: string): boolean {
  const ext = path.extname(filePath).toLowerCase();
  return (ASSET_EXTENSIONS as readonly string[]).includes(ext);
}

/**
 * Bundle local alias imports with Vite's bundler.
 * @todo: Assets not supported yet, will be handled in a future issue.
 */
export async function bundleLocalAliasImports(
  aliasImports: Map<string, string>,
  scanRoot: string,
  aliasBaseDir: string,
  outputDir: string,
): Promise<LocalImportBuildResult> {
  const localImportMap: Record<string, string> = {};
  const sharedChunks: string[] = [];
  if (aliasImports.size === 0) {
    return { success: true, localImportMap, sharedChunks };
  }

  const codeEntries: Record<string, string> = {};
  const entryNameCounts = new Map<string, number>();

  // Track resolved paths to their original source imports for post-build mapping
  const sourceByResolvedPath: Map<string, string> = new Map();

  for (const [source, resolvedPath] of aliasImports) {
    // Skip asset files (images, SVGs, audio, video, fonts) - they are not
    // supported as alias imports yet and should not be added to the manifest.
    if (isAssetFile(resolvedPath)) {
      continue;
    }
    const entryBaseName = path.parse(resolvedPath).name;
    const previousCount = entryNameCounts.get(entryBaseName) ?? 0;
    const entryName =
      previousCount === 0
        ? entryBaseName
        : `${entryBaseName}--${previousCount}`;
    entryNameCounts.set(entryBaseName, previousCount + 1);
    // JS and CSS both go through Vite
    codeEntries[entryName] = resolvedPath;
    sourceByResolvedPath.set(resolvedPath, source);
  }

  const baseConfig = createCanvasViteBuildConfig({ scanRoot, aliasBaseDir });
  const absoluteOutputDir = path.resolve(outputDir);
  const outputDirForLocalImports = path.join(absoluteOutputDir, 'local');

  // Build JS and CSS entries via Vite
  if (Object.keys(codeEntries).length > 0) {
    await viteBuild({
      ...baseConfig,
      build: {
        outDir: outputDirForLocalImports,
        emptyOutDir: false,
        manifest: true,
        rollupOptions: {
          treeshake: false,
          input: codeEntries,
          external: DRUPAL_CANVAS_EXTERNALS,
          preserveEntrySignatures: 'exports-only',
          output: {
            entryFileNames: '[name]-[hash].js',
            chunkFileNames: '[name]-[hash].js',
            assetFileNames: '[name]-[hash][extname]',
          },
        },
        cssCodeSplit: true,
        minify: true,
        sourcemap: false,
      },
    });

    // Read the manifest to map entries to their hashed output filenames
    const viteManifestPath = path.join(
      outputDirForLocalImports,
      '.vite/manifest.json',
    );
    const viteManifestContent = await fs.readFile(viteManifestPath, 'utf-8');
    const viteManifest: Manifest = JSON.parse(viteManifestContent);

    for (const [entryPath, entry] of Object.entries(viteManifest)) {
      if (!entry.isEntry) {
        // Extract shared chunks (non-entry files in the manifest)
        sharedChunks.push(`./local/${entry.file}`);
      }
      // Only add JS files to the import map (JS entries have a name field, CSS does not)
      if (entry.name) {
        // "source" is the original import specifier,
        const source = sourceByResolvedPath.get(path.resolve(entryPath));
        if (source) {
          localImportMap[source] = `./local/${entry.file}`.replace(/\\/g, '/');
        }
      }
    }
  }

  return { success: true, localImportMap, sharedChunks };
}
