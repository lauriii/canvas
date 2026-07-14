import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import { writeComponentManifest } from './manifest';
import {
  COMPONENT_MANIFEST_PATH,
  readComponentManifest,
} from './manifest-read';

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

async function makeProjectRoot(): Promise<string> {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-manifest-'));
  tempDirs.push(root);
  return root;
}

async function writeComponent(root: string): Promise<void> {
  const dir = path.join(root, 'src/components/hello-card');
  await fs.mkdir(dir, { recursive: true });
  await fs.writeFile(
    path.join(dir, 'component.yml'),
    'name: Hello Card\nmachineName: hello-card\n',
  );
  await fs.writeFile(
    path.join(dir, 'index.tsx'),
    'export default function Component() { return null; }\n',
  );
}

describe('component manifest', () => {
  it('round-trips through write and read', async () => {
    const root = await makeProjectRoot();
    await writeComponent(root);

    const written = await writeComponentManifest({ projectRoot: root });
    const read = await readComponentManifest({ projectRoot: root });

    expect(read).toEqual(written);
    expect(read?.components.map((c) => c.machineName)).toEqual(['hello-card']);
  });

  it('creates the manifest directory on write', async () => {
    const root = await makeProjectRoot();
    await writeComponentManifest({ projectRoot: root });
    const stat = await fs.stat(path.join(root, COMPONENT_MANIFEST_PATH));
    expect(stat.isFile()).toBe(true);
  });

  it('reads null when no manifest exists', async () => {
    const root = await makeProjectRoot();
    expect(await readComponentManifest({ projectRoot: root })).toBeNull();
  });

  it('rejects an unknown manifest version instead of serving it', async () => {
    const root = await makeProjectRoot();
    const manifestPath = path.join(root, COMPONENT_MANIFEST_PATH);
    await fs.mkdir(path.dirname(manifestPath), { recursive: true });
    await fs.writeFile(
      manifestPath,
      JSON.stringify({ version: 99, components: [], warnings: [] }),
    );

    await expect(readComponentManifest({ projectRoot: root })).rejects.toThrow(
      /version 99/,
    );
  });

  it('honors a custom manifest path', async () => {
    const root = await makeProjectRoot();
    await writeComponentManifest({
      projectRoot: root,
      manifestPath: 'custom/manifest.json',
    });
    const read = await readComponentManifest({
      projectRoot: root,
      manifestPath: 'custom/manifest.json',
    });
    expect(read?.version).toBe(1);
  });
});
