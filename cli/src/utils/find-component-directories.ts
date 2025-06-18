import path from 'path';
import { glob } from 'glob';

export async function findComponentDirectories(
  baseDir: string,
): Promise<string[]> {
  try {
    // Find directories containing component.yml files (with variants)
    const standardYmls = await glob(`${baseDir}/**/component.yml`);
    const namedYmls = await glob(`${baseDir}/**/*.component.yml`);

    // Combine results and remove duplicates
    const allComponentPaths = [...standardYmls, ...namedYmls];
    const uniqueDirs = new Set(
      allComponentPaths.map((filePath) => path.dirname(filePath)),
    );

    // Convert to array
    const componentDirs = Array.from(uniqueDirs).sort();

    return componentDirs;
  } catch (error) {
    console.error('Error finding component directories:', error);
    return [];
  }
}
