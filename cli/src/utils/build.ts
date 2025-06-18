import { promises as fs } from 'fs';
import path from 'path';
import { compileJS } from '../lib/compile-js';
import type { Result } from '../types/Result';

export async function buildComponent(componentDir: string): Promise<Result> {
  const componentName = path.basename(componentDir);
  const result: Result = {
    itemName: componentName,
    success: true,
    details: [],
  };

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

  // @todo Transpile CSS: https://drupal.org/i/3525590
  await fs.writeFile(
    path.join(distDir, 'index.css'),
    `/* @todo Transpile CSS for ${componentName} in https://drupal.org/i/3525590 */`,
  );

  return result;
}
