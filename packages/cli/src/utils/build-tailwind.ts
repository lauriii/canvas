import { promises as fs } from 'node:fs';
import path from 'path';
import { compileCss, extractClassNameCandidates } from 'tailwindcss-in-browser';
import * as p from '@clack/prompts';
import { JS_EXTENSIONS } from '@drupal-canvas/discovery';
import { upsertClassNameCandidatesInComment } from '@drupal-canvas/ui/features/code-editor/utils/classNameCandidates';
import { resolveHostGlobalCssPath } from '@drupal-canvas/vite-compat';

import { getConfig } from '../config';
import { transformCss } from '../lib/transform-css';
import { createApiService } from '../services/api';
import {
  CANVAS_CACHE_DIR,
  cleanUpCacheDirectory,
  copyLocalJsSourceNext,
  downloadJsSourceFromCanvas,
} from './process-cache-dir';
import { fileExists } from './utils';

import type { DiscoveredComponent } from '@drupal-canvas/discovery';
import type { Component } from '../types/Component';
import type { Result } from '../types/Result';

/**
 * Downloads global CSS from Canvas without updating local files
 * Used for building with remote CSS when local file shouldn't be used
 */
export async function downloadGlobalCssInBackground(): Promise<string> {
  const apiService = await createApiService();
  const globalAssetLibrary = await apiService.getGlobalAssetLibrary();
  return globalAssetLibrary?.css?.original || '';
}

/**
 * Gets global CSS with local-first approach
 * @param useLocal - Whether to prefer local file over remote
 * @returns Promise resolving to global CSS content
 */
export async function getGlobalCss(useLocal: boolean = true): Promise<string> {
  // If local-first and file exists, use local file
  const globalCssPath = resolveHostGlobalCssPath(process.cwd());
  if (useLocal && (await fileExists(globalCssPath))) {
    return await fs.readFile(globalCssPath, 'utf-8');
  }

  // Otherwise, download from Canvas (background download)
  return await downloadGlobalCssInBackground();
}

export async function getAllClassNameCandidatesFromCacheDir(
  componentsToDownload: Record<string, Component>,
  localComponentsToCopy: DiscoveredComponent[],
  distDir?: string,
) {
  if (Object.keys(componentsToDownload).length > 0) {
    // Download the JS source of all online code components to ~/.canvas.
    await downloadJsSourceFromCanvas(componentsToDownload);
  }
  // Copy local JS source files to ~/.canvas.
  await copyLocalJsSourceNext(localComponentsToCopy);

  const cacheEntries = await fs.readdir(CANVAS_CACHE_DIR, {
    withFileTypes: true,
  });
  const cacheDirs = cacheEntries
    .filter((entry) => entry.isDirectory())
    .map((dir) => path.join(CANVAS_CACHE_DIR, dir.name));

  let allClassNameCandidates: string[] = [];

  try {
    // Get the class name candidates from all components in the cache directory.
    for (const cacheDir of cacheDirs) {
      const componentClassNameCandidates =
        await getClassNameCandidatesForComponent(cacheDir, distDir);
      allClassNameCandidates = [
        ...allClassNameCandidates,
        ...componentClassNameCandidates,
      ];
    }
  } finally {
    // Always clean up the cache directory, even if an error occurred.
    await cleanUpCacheDirectory().catch(() => {});
  }

  return allClassNameCandidates;
}

// Builds CSS using the given class name candidates.
export async function buildTailwindCss(
  classNameCandidates: string[],
  globalSourceCodeCss: string,
  distDir: string,
) {
  const compiledTwCss = await compileCss(
    classNameCandidates,
    globalSourceCodeCss,
  );
  const transformedTwCss = await transformCss(compiledTwCss);
  await fs.writeFile(path.join(distDir, 'index.css'), transformedTwCss);
}

// Extracts class name candidates from a component's JS source code.
export async function getClassNameCandidatesForComponent(
  dir: string,
  distDir?: string,
): Promise<string[]> {
  const componentName = path.basename(dir);
  const config = getConfig();
  const componentsDir = config.componentDir;
  const resolvedDistDir = distDir ?? path.join(componentsDir, 'dist');

  // Find the JS entry file in the cache directory.
  const cacheEntries = await fs.readdir(dir);
  const jsFile = cacheEntries.find((file) =>
    (JS_EXTENSIONS as readonly string[]).includes(
      path.extname(file).toLowerCase(),
    ),
  );
  if (!jsFile) {
    p.log.warn(`No JS file found in cache directory: ${dir}`);
    return [];
  }

  // Read the JS source code of the component from the cache directory.
  const jsSource = await fs.readFile(path.join(dir, jsFile), 'utf-8');

  // Read the current global source code JS from the components' directory.
  // components/dist/index.js read the global source code JS.
  const currentGlobalSourceCodeJs = await fs.readFile(
    path.join(resolvedDistDir, 'index.js'),
    'utf-8',
  );
  // Extract class name candidates from the source code.
  const classNameCandidates = extractClassNameCandidates(jsSource);

  // Add it to our globally tracked index of class name candidates, which
  // are extracted from all code components. They're stored as a JS comment
  // in the global asset library.
  // @see ui/src/features/code-editor/utils/classNameCandidates.ts
  const { nextSource: globalJSClassNameIndex, nextClassNameCandidates } =
    upsertClassNameCandidatesInComment(
      currentGlobalSourceCodeJs,
      componentName,
      classNameCandidates,
    );

  // Write this to dist/index.js.
  await fs.writeFile(
    path.join(resolvedDistDir, 'index.js'),
    globalJSClassNameIndex,
  );

  return nextClassNameCandidates;
}

/**
 * Complete Tailwind CSS building workflow that can be shared between commands
 * @param selectedComponents - List of component names to build
 * @param useLocalGlobalCss - Whether to prefer local global CSS over remote
 * @param outputDir - Optional output directory. Defaults to the config componentDir/dist.
 */
export async function buildTailwindForComponents(
  selectedComponents: DiscoveredComponent[],
  useLocalGlobalCss: boolean = true,
  outputDir?: string,
): Promise<Result> {
  const config = getConfig();
  const distDir = outputDir ?? path.join(config.componentDir, 'dist');

  // Create API service
  let apiService;
  try {
    apiService = await createApiService();
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      itemName: 'Tailwind CSS',
      success: false,
      details: [
        { heading: 'Error creating API service', content: errorMessage },
      ],
    };
  }

  // Fetch all components from the API
  let onlineComponents;
  try {
    onlineComponents = await apiService.listComponents();
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      itemName: 'Tailwind CSS',
      success: false,
      details: [
        { heading: 'Error fetching online components', content: errorMessage },
      ],
    };
  }

  // Get global CSS using local-first approach
  let globalSourceCodeCss;
  try {
    globalSourceCodeCss = await getGlobalCss(useLocalGlobalCss);
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      itemName: 'Tailwind CSS',
      success: false,
      details: [{ heading: 'Error getting global CSS', content: errorMessage }],
    };
  }

  // Get global JS from remote (contains class name candidates)
  let globalSourceCodeJs;
  try {
    const globalAssetLibrary = await apiService.getGlobalAssetLibrary();
    globalSourceCodeJs = globalAssetLibrary?.js?.original || '';
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      itemName: 'Tailwind CSS',
      success: false,
      details: [
        {
          heading: 'Error fetching global asset library',
          content: errorMessage,
        },
      ],
    };
  }

  // Write the existing global JS source code to the dist directory
  try {
    await fs.mkdir(distDir, { recursive: true });
    const targetFile = path.join(distDir, 'index.js');
    await fs.writeFile(targetFile, globalSourceCodeJs, 'utf-8');
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      itemName: 'Tailwind CSS',
      success: false,
      details: [
        { heading: 'Error writing global JS to dist', content: errorMessage },
      ],
    };
  }

  // Gather all class name candidates from both local and online components
  let allClassNameCandidates;
  try {
    allClassNameCandidates = await getAllClassNameCandidatesFromCacheDir(
      onlineComponents,
      selectedComponents,
      distDir,
    );
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      itemName: 'Tailwind CSS',
      success: false,
      details: [
        {
          heading: 'Error extracting class name candidates',
          content: errorMessage,
        },
      ],
    };
  }

  // Build the final Tailwind CSS
  try {
    await buildTailwindCss(
      allClassNameCandidates,
      globalSourceCodeCss,
      distDir,
    );
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      itemName: 'Tailwind CSS',
      success: false,
      details: [
        { heading: 'Error compiling Tailwind CSS', content: errorMessage },
      ],
    };
  }

  return {
    itemName: 'Tailwind CSS',
    success: true,
  };
}
