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
  source = 'export default function Component() { return null; }',
): Promise<void> {
  const componentDirectory = path.join(root, 'src/components', directory);
  await fs.mkdir(componentDirectory, { recursive: true });
  await Promise.all([
    fs.writeFile(
      path.join(componentDirectory, 'component.yml'),
      metadata,
      'utf8',
    ),
    fs.writeFile(path.join(componentDirectory, 'index.tsx'), source, 'utf8'),
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

  it('adds the interface translation of components', async () => {
    const root = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-workbench-'));
    tempDirs.push(root);
    const hook = "import { useTranslation } from 'drupal-canvas/react';";
    await fs.mkdir(path.join(root, 'src/lib'), { recursive: true });
    await fs.writeFile(
      path.join(root, 'src/lib/labels.ts'),
      `${hook}\nexport const useLabel = () => useTranslation().t('Label');`,
    );
    await Promise.all([
      writeComponent(
        root,
        'card',
        'name: Card\nmachineName: card',
        `${hook}
import { useLabel } from '@/lib/labels';
import Badge from '@/components/badge';
export default function Card() { return [useTranslation().t('Card'), useLabel(), Badge()]; }`,
      ),
      writeComponent(
        root,
        'badge',
        'name: Badge\nmachineName: badge',
        `${hook}\nexport default () => useTranslation().t('Badge');`,
      ),
      writeComponent(root, 'plain', 'name: Plain\nmachineName: plain'),
      writeComponent(
        root,
        'invalid',
        'name: Invalid\nmachineName: invalid',
        `${hook}\nexport default () => useTranslation().t('');`,
      ),
      // Helpers are not scanned, also when they are missing.
      writeComponent(
        root,
        'helpers',
        'name: Helpers\nmachineName: helpers',
        "import { label } from '@/lib/missing';\nexport default () => label;",
      ),
      writeComponent(
        root,
        'broken',
        'name: Broken\nmachineName: broken',
        'export default (;',
      ),
    ]);
    const discoveryResult = await discoverCanvasProject({
      componentRoot: path.join(root, 'src/components'),
      projectRoot: root,
    });

    const manifest = await buildWorkbenchPreviewManifest(discoveryResult);

    expect(
      Object.fromEntries(
        manifest.components.map(({ name, translation }) => [name, translation]),
      ),
    ).toEqual({
      // With the strings of the components it imports, not of helpers.
      card: {
        usesTranslationHook: true,
        translatableStrings: [{ source: 'Badge' }, { source: 'Card' }],
      },
      badge: {
        usesTranslationHook: true,
        translatableStrings: [{ source: 'Badge' }],
      },
      plain: { usesTranslationHook: false, translatableStrings: [] },
      // With why the strings are unknown.
      invalid: {
        usesTranslationHook: true,
        translatableStrings: null,
        issues: [
          {
            path: 'src/components/invalid/index.tsx',
            line: 2,
            column: 41,
            message: 'A translatable string must not be empty.',
          },
        ],
      },
      helpers: { usesTranslationHook: false, translatableStrings: [] },
      broken: {
        usesTranslationHook: null,
        translatableStrings: null,
        issues: [
          expect.objectContaining({
            path: 'src/components/broken/index.tsx',
            line: 1,
          }),
        ],
      },
    });
  });
});
