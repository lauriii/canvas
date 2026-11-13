import { promises as fs, readFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import {
  createConfigAliasedImports,
  createRegisteredComponentImports,
  discoverCanvasProject,
} from '@drupal-canvas/discovery';
import { getComponentTranslatableStrings } from '@drupal-canvas/translatable-strings';

import {
  analyzeComponentTranslations,
  createComponentTranslationTracker,
} from './translation-metadata';

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  tempDirs.length = 0;
});

const HOOK = "import { useTranslation } from 'drupal-canvas/react';";

async function writeFile(filePath: string, content: string): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content, 'utf8');
}

async function createProject(): Promise<string> {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-workbench-'));
  tempDirs.push(root);
  return root;
}

const discover = (root: string) =>
  discoverCanvasProject({
    componentRoot: path.join(root, 'src/components'),
    projectRoot: root,
  });

describe('analyzeComponentTranslations', () => {
  it('describes why the strings of a component are unknown', async () => {
    const root = await createProject();
    for (const [name, entry, source] of [
      ['broken', 'index.tsx', 'export default (;'],
      [
        'invalid',
        'index.tsx',
        `${HOOK}\nexport default () => useTranslation().t('');`,
      ],
    ]) {
      await writeFile(
        path.join(root, 'src/components', name, 'component.yml'),
        `name: ${name}\nmachineName: ${name}`,
      );
      await writeFile(path.join(root, 'src/components', name, entry), source);
    }
    const discoveryResult = await discover(root);

    const issues = Object.fromEntries(
      analyzeComponentTranslations(discoveryResult).map(
        ({ translation }, index) => [
          discoveryResult.components[index].name,
          translation.issues,
        ],
      ),
    );
    expect(issues).toEqual({
      broken: [
        expect.objectContaining({
          path: 'src/components/broken/index.tsx',
          line: 1,
        }),
      ],
      invalid: [
        {
          path: 'src/components/invalid/index.tsx',
          line: 2,
          column: 41,
          message: 'A translatable string must not be empty.',
        },
      ],
    });
  });
});

// Writes a component that translates its name, with the given imports.
async function writeComponent(
  root: string,
  directory: string,
  name: string,
  imports = '',
): Promise<string> {
  await writeFile(
    path.join(root, directory, name, 'component.yml'),
    `name: ${name}\nmachineName: ${name}`,
  );
  const entry = path.join(root, directory, name, 'index.tsx');
  await writeFile(
    entry,
    `${HOOK}\n${imports}\nexport default () => useTranslation().t('${name}');`,
  );
  return entry;
}

const stringsByName = async (root: string, componentRoot: string) => {
  const discoveryResult = await discoverCanvasProject({
    componentRoot: path.join(root, componentRoot),
    projectRoot: root,
  });
  const analyses = analyzeComponentTranslations(discoveryResult);
  return Object.fromEntries(
    discoveryResult.components.map(({ name }, index) => [
      name,
      analyses[index].translation.translatableStrings?.map(
        ({ source }) => source,
      ) ?? null,
    ]),
  );
};

describe('the resolution of imports', () => {
  it('follows the imports that previews load, with the Canvas aliases', async () => {
    const root = await createProject();
    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({ componentDir: 'src/ui', aliasBaseDir: 'src' }),
    );
    // Previews do not resolve the paths of the TypeScript configuration.
    await writeFile(
      path.join(root, 'tsconfig.json'),
      JSON.stringify({ compilerOptions: { paths: { '@/*': ['./other/*'] } } }),
    );
    await writeComponent(
      root,
      'src/ui',
      'card',
      [
        "import Badge from '@/components/badge';",
        "import Icon from '@/ui/icon';",
        "import Title from '../title';",
        "import Explicit from '@/components/explicit/index.js';",
        "import Shadowed from '../shadowed';",
      ].join('\n'),
    );
    for (const name of ['badge', 'icon', 'title', 'explicit', 'shadowed']) {
      await writeComponent(root, 'src/ui', name);
    }
    // A helper that the relative import of the shadowed component loads.
    await writeFile(path.join(root, 'src/ui/shadowed.ts'), 'export default 1;');

    expect((await stringsByName(root, 'src/ui')).card).toEqual([
      'badge',
      'card',
      'icon',
      'title',
    ]);
  });

  it('resolves imports like the Headless SDK in projects configured alike', async () => {
    const root = await createProject();
    await writeFile(
      path.join(root, 'tsconfig.json'),
      JSON.stringify({ compilerOptions: { paths: { '@/*': ['./src/*'] } } }),
    );
    await writeComponent(
      root,
      'src/components',
      'card',
      "import Badge from '@/components/badge';\nimport Icon from '../icon';",
    );
    await writeComponent(
      root,
      'src/components',
      'badge',
      "import Card from '@/components/card';",
    );
    await writeComponent(root, 'src/components', 'icon');
    const discoveryResult = await discoverCanvasProject({
      componentRoot: path.join(root, 'src/components'),
      projectRoot: root,
    });
    const entryPaths = discoveryResult.components.flatMap(({ jsEntryPath }) =>
      jsEntryPath ? [jsEntryPath] : [],
    );
    // As the Headless SDK builds the component metadata.
    const sdkImports = createRegisteredComponentImports({
      entryPaths,
      readSource: (entryPath) => readFileSync(entryPath, 'utf-8'),
      resolveAliased: createConfigAliasedImports({ projectRoot: root }),
    });
    const analyses = analyzeComponentTranslations(discoveryResult);

    discoveryResult.components.forEach(({ jsEntryPath }, index) => {
      const sdk = getComponentTranslatableStrings(
        sdkImports.load(jsEntryPath!),
        sdkImports.resolve,
      );
      expect(analyses[index].translation.translatableStrings).toEqual(
        'translatableStrings' in sdk ? sdk.translatableStrings : null,
      );
    });
    expect(await stringsByName(root, 'src/components')).toEqual({
      badge: ['badge', 'card', 'icon'],
      card: ['badge', 'card', 'icon'],
      icon: ['icon'],
    });
  });
});

describe('createComponentTranslationTracker', () => {
  it('tells when a component that components import changes their metadata', async () => {
    const root = await createProject();
    const card = path.join(root, 'src/components/card/index.tsx');
    const badge = path.join(root, 'src/components/badge/index.tsx');
    const labels = path.join(root, 'src/lib/labels.ts');
    for (const name of ['card', 'badge']) {
      await writeFile(
        path.join(root, 'src/components', name, 'component.yml'),
        `name: ${name}\nmachineName: ${name}`,
      );
    }
    await writeFile(
      card,
      "import Badge from '../badge';\nimport { useLabel } from '@/lib/labels';\nexport default () => Badge;",
    );
    const label = (string: string) =>
      `${HOOK}\nexport default () => useTranslation().t('${string}');`;
    await writeFile(badge, label('Before'));
    await writeFile(labels, label('Helper'));
    const discoveryResult = await discover(root);
    const tracker = createComponentTranslationTracker(() =>
      analyzeComponentTranslations(discoveryResult),
    );
    const cardStrings = (
      analyses: ReturnType<typeof tracker.update>['analyses'],
    ) =>
      analyses[
        discoveryResult.components.findIndex(({ name }) => name === 'card')
      ]?.translation.translatableStrings;

    const first = tracker.update();
    expect(first.changed).toBe(true);
    // Not the helper's strings.
    expect(cardStrings(first.analyses)).toEqual([{ source: 'Before' }]);
    expect(tracker.revision).toBe(1);
    expect(tracker.dependsOn(card)).toBe(true);
    expect(tracker.dependsOn(badge)).toBe(true);
    // The helper's file was checked to resolve its import, but its strings
    // are not scanned: a change to them changes nothing.
    expect(tracker.dependsOn(labels)).toBe(true);
    await writeFile(labels, label('Changed helper'));
    expect(tracker.update().changed).toBe(false);
    expect(tracker.dependsOn(path.join(root, 'src/lib/unrelated.ts'))).toBe(
      false,
    );

    // A change that keeps the strings.
    await writeFile(badge, `${label('Before')}\n// A comment.`);
    expect(tracker.update().changed).toBe(false);
    expect(tracker.revision).toBe(1);

    await writeFile(badge, label('After'));
    const changed = tracker.update();
    expect(changed.changed).toBe(true);
    expect(cardStrings(changed.analyses)).toEqual([{ source: 'After' }]);
    expect(tracker.revision).toBe(2);

    // A helper created where the import of a component now resolves to.
    const shadow = path.join(root, 'src/components/badge.ts');
    expect(tracker.dependsOn(shadow)).toBe(true);
    await writeFile(shadow, 'export default 1;');
    expect(cardStrings(tracker.update().analyses)).toEqual([]);
    await fs.rm(shadow);
    expect(cardStrings(tracker.update().analyses)).toEqual([
      { source: 'After' },
    ]);
    expect(tracker.revision).toBe(4);

    // A package.json file that makes the directory a package.
    const manifest = path.join(root, 'src/components/badge/package.json');
    expect(tracker.dependsOn(manifest)).toBe(true);
    await writeFile(manifest, '{}');
    expect(cardStrings(tracker.update().analyses)).toEqual([]);
    await fs.rm(manifest);
    expect(cardStrings(tracker.update().analyses)).toEqual([
      { source: 'After' },
    ]);
    expect(tracker.revision).toBe(6);

    // A component that cannot be parsed makes the metadata unknown.
    await writeFile(badge, 'export default (;');
    expect(cardStrings(tracker.update().analyses)).toBeNull();
    expect(tracker.revision).toBe(7);
  });
});
