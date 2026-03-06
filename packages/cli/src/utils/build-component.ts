import { promises as fs } from 'node:fs';
import path from 'path';
import { compilePartialCss } from 'tailwindcss-in-browser';

import { compileJS } from '../lib/compile-js';
import { transformCss } from '../lib/transform-css';
import { getGlobalCss } from './build-tailwind';
import { validateComponent } from './validate';

import type { DiscoveredComponent } from '@drupal-canvas/discovery';
import type { Result } from '../types/Result';

export async function buildComponent(
  component: DiscoveredComponent,
  useLocalGlobalCss: boolean = true,
  outputDir: string = 'dist',
): Promise<Result> {
  const { name, kind, jsEntryPath, cssEntryPath } = component;

  // For 'index' kind: output files are index.js / index.css / component.yml
  // For 'named' kind: output files are [component-name].js / [component-name].css / [component-name].component.yml.
  const outputBaseName = kind === 'index' ? 'index' : name;

  const result: Result = {
    itemName: name,
    success: true,
    details: [],
  };

  // Validate component before building.
  // Use absolute path from metadataPath since relativeDirectory is relative to scanRoot, not cwd
  const componentAbsoluteDir = path.dirname(component.metadataPath);
  const validationResult = await validateComponent(componentAbsoluteDir);
  if (!validationResult.success) {
    result.success = false;
    result.details = validationResult.details;
    return result;
  }

  // Create output directory for this component
  const distDir = path.join(outputDir, 'components', name);
  try {
    await fs.mkdir(distDir, { recursive: true });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    result.success = false;
    result.details!.push({
      heading: 'Error while creating `dist` directory',
      content: errorMessage,
    });
    return result;
  }

  try {
    const metadataFileName = path.basename(component.metadataPath);
    await fs.copyFile(
      component.metadataPath,
      path.join(distDir, metadataFileName),
    );
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    result.success = false;
    result.details!.push({
      heading: 'Error while copying metadata file',
      content: errorMessage,
    });
    return result;
  }

  // Read JS source and compile it.
  if (jsEntryPath) {
    try {
      const jsSource = await fs.readFile(jsEntryPath, 'utf-8');
      const jsCompiled = compileJS(jsSource);
      await fs.writeFile(
        path.join(distDir, `${outputBaseName}.js`),
        jsCompiled,
      );
    } catch (error) {
      const errorMessage =
        error instanceof Error ? error.message : String(error);
      result.success = false;
      result.details!.push({
        heading: 'Error while transforming JavaScript',
        content: errorMessage,
      });
      return result;
    }
  }

  // Get global CSS for component CSS build.
  const globalSourceCodeCss = await getGlobalCss(useLocalGlobalCss);

  // Read the CSS source and transpile it, if a CSS entry was found during discovery.
  if (cssEntryPath) {
    try {
      const cssSource = await fs.readFile(cssEntryPath, 'utf-8');
      const cssCompiled = await compilePartialCss(
        cssSource,
        globalSourceCodeCss,
      );
      const cssTranspiled = await transformCss(cssCompiled);
      await fs.writeFile(
        path.join(distDir, `${outputBaseName}.css`),
        cssTranspiled,
      );
    } catch (error) {
      const errorMessage =
        error instanceof Error ? error.message : String(error);
      result.success = false;
      result.details!.push({
        heading: 'Error while transforming CSS',
        content: errorMessage,
      });
      return result;
    }
  }

  return result;
}
