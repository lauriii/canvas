/* cspell:ignore giphy */
import { promises as fs } from 'node:fs';
import path from 'path';
import { build as viteBuild } from 'vite';

import { ASSET_EXTENSIONS } from './asset-extensions';
import { createCanvasViteBuildConfig } from './vite-build-config';

export interface LocalImportBuildResult {
  success: boolean;
  /** Maps original alias import strings to their compiled output paths.
   * JS imports map to .js paths, CSS side effect imports map to .css paths,
   * asset imports (images, SVGs, audio, video) map to their original extension. */
  localImportMap: Record<string, string>;
  error?: string;
}

function buildLocalEntryName(
  resolvedPath: string,
  scanRoot: string,
  aliasBaseDir: string,
): string {
  // aliasBaseDir is relative to project root (cwd), not scanRoot
  const aliasRoot = path.resolve(aliasBaseDir);
  const relativePath = path.relative(aliasRoot, resolvedPath);
  const parsed = path.parse(relativePath);
  return path.join(parsed.dir, parsed.name).replace(/\\/g, '/');
}

function isAssetFile(filePath: string): boolean {
  const ext = path.extname(filePath).toLowerCase();
  return (ASSET_EXTENSIONS as readonly string[]).includes(ext);
}

/**
 * Bundle local alias imports with Vite's bundler.
 * JS imports are built via Vite and mapped to their .js output path.
 * CSS side effect alias imports are copied and mapped to their .css output path.
 * Asset alias imports (images, SVGs, audio, video) are copied and mapped
 * to their output path preserving the original extension.
 */
export async function bundleLocalAliasImports(
  aliasImports: Map<string, string>,
  scanRoot: string,
  aliasBaseDir: string,
  outputDir: string,
): Promise<LocalImportBuildResult> {
  const localImportMap: Record<string, string> = {};
  if (aliasImports.size === 0) {
    return { success: true, localImportMap };
  }

  const jsEntries: Record<string, string> = {};
  const cssEntries: Map<string, string> = new Map();
  const assetEntries: Map<string, string> = new Map();

  for (const [source, resolvedPath] of aliasImports) {
    const entryName = buildLocalEntryName(resolvedPath, scanRoot, aliasBaseDir);
    const ext = path.extname(resolvedPath).toLowerCase();

    // Determine how to handle the import based on its file type.
    if (ext === '.css') {
      cssEntries.set(entryName, resolvedPath);
      localImportMap[source] = `./local/${entryName}.css`.replace(/\\/g, '/');
    } else if (isAssetFile(resolvedPath)) {
      assetEntries.set(`${entryName}${ext}`, resolvedPath);
      localImportMap[source] = `./local/${entryName}${ext}`.replace(/\\/g, '/');
    } else {
      jsEntries[entryName] = resolvedPath;
      localImportMap[source] = `./local/${entryName}.js`.replace(/\\/g, '/');
    }
  }

  const baseConfig = createCanvasViteBuildConfig({ scanRoot, aliasBaseDir });
  const absoluteOutputDir = path.resolve(outputDir);
  const outputDirForLocalImports = path.join(absoluteOutputDir, 'local');

  try {
    // Build JS entries via Vite
    if (Object.keys(jsEntries).length > 0) {
      await viteBuild({
        ...baseConfig,
        build: {
          outDir: outputDirForLocalImports,
          emptyOutDir: false,
          rollupOptions: {
            treeshake: false,
            // Entry path structure is preserved in the output (e.g., 'component/hero/utils.js', 'utils/utils.js')
            // to avoid collisions of same name.
            input: jsEntries,
            preserveEntrySignatures: 'exports-only',
            output: {
              entryFileNames: '[name].js',
              chunkFileNames: 'shared/[name]-[hash].js',
              assetFileNames: '[name][extname]',
            },
          },
          cssCodeSplit: true,
          minify: true,
          sourcemap: false,
        },
      });
    }

    // Copy CSS side effect entries directly to the output directory.
    // Entry path structure is preserved in the output to avoid collisions between CSS files
    // with the same filename but different parent directories
    // (e.g., 'utils/styles/styles.css' vs 'components/hero/styles.css').
    for (const [entryName, resolvedPath] of cssEntries) {
      const outputPath = path.join(
        outputDirForLocalImports,
        `${entryName}.css`,
      );
      await fs.mkdir(path.dirname(outputPath), { recursive: true });
      await fs.copyFile(resolvedPath, outputPath);
    }

    // Copy asset entries (images, SVGs, audio, video) directly to the output directory.
    // Path structure is preserved in the output (e.g., 'components/hero/hero.png',
    // 'assets/giphy.gif') to avoid collisions between assets with the same filename
    // but different parent directories or extensions (e.g., 'hero/hero.png' vs 'hero/hero.svg').
    for (const [entryName, resolvedPath] of assetEntries) {
      const outputPath = path.join(outputDirForLocalImports, entryName);
      await fs.mkdir(path.dirname(outputPath), { recursive: true });
      await fs.copyFile(resolvedPath, outputPath);
    }

    return { success: true, localImportMap };
  } catch (error) {
    return {
      success: false,
      localImportMap,
      error: error instanceof Error ? error.message : String(error),
    };
  }
}
