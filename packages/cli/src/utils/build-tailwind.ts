import { promises as fs } from 'node:fs';
import path from 'path';
import { compileCss, extractClassNameCandidates } from 'tailwindcss-in-browser';
import { upsertClassNameCandidatesInComment } from '@drupal-canvas/ui/features/code-editor/utils/classNameCandidates';
import { UNLAYERED_DISPLAY_UTILITIES } from '@drupal-canvas/ui/features/code-editor/utils/tailwindCss';
import { resolveHostGlobalCssPath } from '@drupal-canvas/vite-compat';

import { getConfig } from '../config';
import { transformCss } from '../lib/transform-css';
import { fileExists } from './utils';

import type { DiscoveredComponent } from '@drupal-canvas/discovery';
import type { Result } from '../types/Result';

/**
 * Gets global CSS from the local project.
 * @returns Promise resolving to global CSS content.
 */
export async function getGlobalCss(): Promise<string> {
  const globalCssPath = resolveHostGlobalCssPath(process.cwd());
  if (await fileExists(globalCssPath)) {
    return await fs.readFile(globalCssPath, 'utf-8');
  }

  throw new Error(
    `Missing local global CSS file at ${globalCssPath}. Create this file, or set "globalCssPath" in canvas.config.json to an existing CSS file.`,
  );
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
    {
      // Keep generated display utilities outside the utilities layer so they
      // can override Drupal System's unlayered `.hidden` class.
      unlayeredUtilities: UNLAYERED_DISPLAY_UTILITIES,
    },
  );
  const transformedTwCss = await transformCss(compiledTwCss);
  await fs.writeFile(path.join(distDir, 'index.css'), transformedTwCss);
}

async function upsertClassNameCandidatesForComponent(
  component: DiscoveredComponent,
  jsEntryPath: string,
  globalSourceCodeJs: string,
): Promise<{
  nextSource: string;
  nextClassNameCandidates: string[];
}> {
  const jsSource = await fs.readFile(jsEntryPath, 'utf-8');
  const classNameCandidates = extractClassNameCandidates(jsSource);

  // Add it to our globally tracked index of class name candidates, which
  // are extracted from all local code components. They're stored as a JS comment
  // in the global asset library.
  // @see ui/src/features/code-editor/utils/classNameCandidates.ts
  return upsertClassNameCandidatesInComment(
    globalSourceCodeJs,
    component.name,
    classNameCandidates,
  );
}

/**
 * Complete Tailwind CSS building workflow that can be shared between commands.
 * @param selectedComponents - Discovered local components to include in the build.
 * @param outputDir - Optional output directory. Defaults to the config componentDir/dist.
 */
export async function buildTailwindForComponents(
  selectedComponents: DiscoveredComponent[],
  outputDir?: string,
): Promise<Result> {
  const config = getConfig();
  const distDir = outputDir ?? path.join(config.componentDir, 'dist');

  // Get global CSS from the local project.
  let globalSourceCodeCss;
  try {
    globalSourceCodeCss = await getGlobalCss();
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      itemName: 'Tailwind CSS',
      success: false,
      details: [{ heading: 'Error getting global CSS', content: errorMessage }],
    };
  }

  let globalSourceCodeJs = '';
  let allClassNameCandidates: string[] = [];
  try {
    for (const component of selectedComponents) {
      if (!component.jsEntryPath) {
        continue;
      }
      const { nextSource, nextClassNameCandidates } =
        await upsertClassNameCandidatesForComponent(
          component,
          component.jsEntryPath,
          globalSourceCodeJs,
        );
      globalSourceCodeJs = nextSource;
      allClassNameCandidates = nextClassNameCandidates;
    }
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

  // Write the local class name candidate index to the dist directory.
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
        {
          heading: 'Error writing class name candidate index',
          content: errorMessage,
        },
      ],
    };
  }

  // Build the final Tailwind CSS.
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
