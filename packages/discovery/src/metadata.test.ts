import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import { discoverCanvasProject } from './discover';
import { loadComponentsMetadata } from './metadata';

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

async function createComponent(metadata: string): Promise<string> {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-metadata-'));
  tempDirs.push(root);
  const componentDirectory = path.join(root, 'src/components/example');
  await fs.mkdir(componentDirectory, { recursive: true });
  await Promise.all([
    fs.writeFile(
      path.join(componentDirectory, 'component.yml'),
      metadata,
      'utf8',
    ),
    fs.writeFile(
      path.join(componentDirectory, 'index.tsx'),
      'export default function Example() { return null; }',
      'utf8',
    ),
  ]);
  return root;
}

describe('component metadata', () => {
  it('normalizes schema-valid authored metadata', async () => {
    const root = await createComponent(
      ['name: Example', 'machineName: example'].join('\n'),
    );
    const discoveryResult = await discoverCanvasProject({
      componentRoot: path.join(root, 'src/components'),
      projectRoot: root,
    });

    await expect(loadComponentsMetadata(discoveryResult)).resolves.toEqual([
      {
        name: 'Example',
        machineName: 'example',
        status: true,
        required: [],
        props: { properties: {} },
        slots: {},
        dataDependencies: {},
      },
    ]);
  });

  it('keeps discovery available and reports schema errors with locations', async () => {
    const root = await createComponent(
      ['name: Example', 'machineName: example', 'type: react'].join('\n'),
    );
    const discoveryResult = await discoverCanvasProject({
      componentRoot: path.join(root, 'src/components'),
      projectRoot: root,
    });

    expect(discoveryResult.components).toHaveLength(1);
    await expect(loadComponentsMetadata(discoveryResult)).rejects.toMatchObject(
      {
        authoredName: 'Example',
        message: [
          'Invalid component metadata in src/components/example/component.yml:',
          "Line 3, Column 7: $.type must not contain unknown property 'type'",
        ].join('\n'),
      },
    );
  });
});
