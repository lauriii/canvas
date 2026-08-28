import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { buildWorkbenchPreviewManifest } from './create-workbench-plugin';

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  tempDirs.length = 0;
});

async function writeComponent(
  root: string,
  directory: string,
  metadata: string,
): Promise<void> {
  const componentDirectory = path.join(root, 'src/components', directory);
  await fs.mkdir(componentDirectory, { recursive: true });
  await Promise.all([
    fs.writeFile(
      path.join(componentDirectory, 'component.yml'),
      metadata,
      'utf8',
    ),
    fs.writeFile(
      path.join(componentDirectory, 'index.tsx'),
      'export default function Component() { return null; }',
      'utf8',
    ),
  ]);
}

describe('buildWorkbenchPreviewManifest', () => {
  it('scopes metadata errors to their component', async () => {
    const root = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-workbench-'));
    tempDirs.push(root);
    await Promise.all([
      writeComponent(
        root,
        'valid',
        ['name: Valid', 'machineName: valid'].join('\n'),
      ),
      writeComponent(
        root,
        'invalid',
        ['name: Invalid', 'machineName: invalid', 'type: react'].join('\n'),
      ),
      writeComponent(
        root,
        'malformed',
        ['name: Malformed', 'name: Duplicate', 'machineName: malformed'].join(
          '\n',
        ),
      ),
    ]);
    const discoveryResult = await discoverCanvasProject({
      componentRoot: path.join(root, 'src/components'),
      projectRoot: root,
    });

    const manifest = await buildWorkbenchPreviewManifest(discoveryResult);

    expect(manifest.components).toHaveLength(3);
    expect(
      manifest.components.find((component) => component.name === 'valid'),
    ).toMatchObject({
      label: 'Valid',
      previewable: true,
      ineligibilityReason: null,
      metadataErrors: [],
    });
    expect(
      manifest.components.find((component) => component.name === 'invalid'),
    ).toMatchObject({
      label: 'Invalid',
      previewable: false,
      ineligibilityReason: 'invalid_metadata',
      metadataErrors: [
        {
          sourcePath: 'src/components/invalid/component.yml',
          path: '$.type',
          line: 3,
          column: 7,
          message: "must not contain unknown property 'type'",
        },
      ],
    });
    expect(
      manifest.components.find((component) => component.name === 'malformed'),
    ).toMatchObject({
      label: 'malformed',
      previewable: false,
      ineligibilityReason: 'invalid_metadata',
      metadataErrors: [
        {
          sourcePath: 'src/components/malformed/component.yml',
          path: '$',
          message: expect.stringContaining('Map keys must be unique'),
        },
      ],
    });
  });
});
