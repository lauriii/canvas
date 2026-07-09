import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  discoverCanvasProject,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';

import { validateComponents } from './validate';

const currentDir = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(currentDir, '../..');
const fixtureRoot = path.join(packageRoot, 'test-fixtures/local-codebases');

async function withWorkingDirectory<T>(
  directory: string,
  callback: () => Promise<T>,
): Promise<T> {
  const previousDirectory = process.cwd();
  process.chdir(directory);

  try {
    return await callback();
  } finally {
    process.chdir(previousDirectory);
  }
}

async function validateFixtureProject(fixtureName: string) {
  const projectRoot = path.join(fixtureRoot, fixtureName);
  const config = resolveCanvasConfig({ hostRoot: projectRoot });
  const componentRoot = path.resolve(projectRoot, config.componentDir);
  const discoveryResult = await discoverCanvasProject({
    componentRoot,
    projectRoot,
  });

  return withWorkingDirectory(projectRoot, () =>
    validateComponents(discoveryResult),
  );
}

describe('local codebase fixture projects', () => {
  it('reports imports and assets examples documented as unsupported', async () => {
    const { results } = await validateFixtureProject(
      'imports-and-assets-unsupported-caught-by-eslint',
    );
    const messages = results
      .flatMap((componentResult) => componentResult.details ?? [])
      .map((detail) => detail.content)
      .join('\n\n');

    expect(results.some((componentResult) => !componentResult.success)).toBe(
      true,
    );
    expect(messages).toContain(
      'Importing "@/components/pricing-card/helpers" from a component directory is not supported.',
    );
    expect(messages).toContain(
      'Importing "@/components/heading-utils" from a component directory is not supported.',
    );
    expect(messages).toContain(
      "Relative JavaScript and TypeScript module imports are not supported. Use '@/...' alias instead of './formatDate'",
    );
    expect(messages).toContain(
      'CSS side-effect imports are not supported in components. Remove "swiper/css"',
    );
    expect(messages).toContain(
      'CSS side-effect imports are not supported in components. Remove "@/lib/styles/carousel.css"',
    );
    expect(messages).toContain(
      'Importing font packages ("@fontsource/inter") is not supported in components.',
    );
    expect(messages).toContain(
      'Component directories must be direct children of configured componentDir "src/components". Found "heading" inside "src/components/marketing".',
    );
  });
});
