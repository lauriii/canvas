import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { bundleLocalAliasImports } from './build-local-import';

describe('bundleLocalAliasImports integration', () => {
  let projectDir: string;
  let originalNodeEnv: string | undefined;

  beforeEach(async () => {
    originalNodeEnv = process.env.NODE_ENV;
    process.env.NODE_ENV = 'production';
    projectDir = await fs.realpath(
      await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-local-import-')),
    );
  });

  afterEach(async () => {
    if (originalNodeEnv === undefined) {
      delete process.env.NODE_ENV;
    } else {
      process.env.NODE_ENV = originalNodeEnv;
    }
    await fs.rm(projectDir, { recursive: true, force: true });
  });

  it('compiles JSX local imports with the automatic JSX runtime', async () => {
    const examplePath = path.join(projectDir, 'src/lib/Example.jsx');
    const outputDir = path.join(projectDir, 'dist');

    await fs.mkdir(path.dirname(examplePath), { recursive: true });
    await fs.writeFile(
      examplePath,
      `
const Example = () => <div>Example</div>;

export default Example;
`,
    );

    const result = await bundleLocalAliasImports(
      new Map([['@/lib/Example.jsx', examplePath]]),
      path.join(projectDir, 'src/components'),
      'src',
      outputDir,
    );

    const compiledPath = path.join(
      outputDir,
      result.localImportMap['@/lib/Example.jsx'],
    );
    const compiledJs = await fs.readFile(compiledPath, 'utf-8');

    expect(compiledJs).toContain('react/jsx-runtime');
    expect(compiledJs).not.toContain('React.createElement');
  });
});
