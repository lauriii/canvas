import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { resolveWorkbenchPaths } from './paths';

const temporaryDirectories: string[] = [];

async function makeTemporaryDirectory(): Promise<string> {
  const directory = await fs.mkdtemp(
    path.join(os.tmpdir(), 'canvas-workbench-paths-'),
  );
  temporaryDirectories.push(directory);
  return directory;
}

afterEach(async () => {
  vi.unstubAllGlobals();
  await Promise.all(
    temporaryDirectories.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  temporaryDirectories.length = 0;
});

describe('resolveWorkbenchPaths', () => {
  it('rejects componentDir outside aliasBaseDir', async () => {
    const root = await makeTemporaryDirectory();
    await fs.writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({
        aliasBaseDir: 'src',
        componentDir: 'components',
      }),
    );
    vi.stubGlobal('process', {
      ...process,
      cwd: () => root,
    });

    expect(() =>
      resolveWorkbenchPaths({
        moduleUrl: import.meta.url,
      }),
    ).toThrow(
      'Invalid Canvas config: componentDir "components" must be inside aliasBaseDir "src".',
    );
  });
});
