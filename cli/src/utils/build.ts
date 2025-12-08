import { promises as fs } from 'fs';
import path from 'path';
import { compilePartialCss } from 'tailwindcss-in-browser';

import { compileJS } from '../lib/compile-js';
import { transformCss } from '../lib/transform-css';
import { getGlobalCss } from './build-tailwind';
import { fileExists } from './utils';
import { validateComponent } from './validate';

import type { Result } from '../types/Result';

export async function buildComponent(
  componentDir: string,
  useLocalGlobalCss: boolean = true,
): Promise<Result> {
  const componentName = path.basename(componentDir);
  const result: Result = {
    itemName: componentName,
    success: true,
    details: [],
  };

  // Validate component before building.
  const validationResult = await validateComponent(componentDir);
  if (!validationResult.success) {
    result.success = false;
    result.details = validationResult.details;
    return result;
  }

  // Create `dist` directory
  const distDir = path.join(componentDir, 'dist');
  try {
    await fs.mkdir(distDir, { recursive: true });
  } catch (error) {
    result.success = false;
    result.details?.push({
      heading: 'Error while creating `dist` directory',
      content: String(error),
    });
    return result;
  }

  // Read JS source and compile it.
  try {
    const jsSource = await fs.readFile(
      path.join(componentDir, 'index.jsx'),
      'utf-8',
    );
    const jsCompiled = compileJS(jsSource);
    await fs.writeFile(path.join(distDir, 'index.js'), jsCompiled);
  } catch (error) {
    result.success = false;
    result.details?.push({
      heading: 'Error while transforming JavaScript',
      content: String(error),
    });
  }

  // Get global CSS for component CSS build.
  const globalSourceCodeCss = await getGlobalCss(useLocalGlobalCss);

  // Read the CSS source and transpile it.
  try {
    const cssPath = path.join(componentDir, 'index.css');
    const cssFileExists = await fileExists(cssPath);
    if (cssFileExists) {
      const cssSource = await fs.readFile(cssPath, 'utf-8');
      const cssCompiled = await compilePartialCss(
        cssSource,
        globalSourceCodeCss,
      );
      const cssTranspiled = await transformCss(cssCompiled);
      await fs.writeFile(path.join(distDir, 'index.css'), cssTranspiled);
    }
  } catch (error) {
    result.success = false;
    result.details?.push({
      heading: 'Error while transforming CSS',
      content: String(error),
    });
  }

  return result;
}
