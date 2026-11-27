import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  createComponentsPullTask,
  createStylesPullTask,
} from '../../commands/pull';
import { readValidatedComponentMetadata } from '../../utils/component-metadata';
import { createComponentPayload } from '../../utils/process-component-files';
import { readLocalStyleLibraries } from './style-library';
import { planStyleLibraryPush } from './style-library-push';

import type { StyleDefinition } from '@drupal-canvas/ui/types/Styles';
import type { ApiService } from '../../services/api';
import type { Component } from '../../types/Component';
import type { StyleLibraryPayload } from './style-library';

vi.mock('@clack/prompts', () => ({
  log: { info: vi.fn(), warn: vi.fn(), message: vi.fn() },
  note: vi.fn(),
}));

const component = (machineName: string, styles?: Component['styles']) =>
  ({
    name: machineName,
    machineName,
    status: true,
    required: [],
    props: {},
    slots: {},
    sourceCodeJs: `export default function C({ className }) { return <div className={className} />; }`,
    sourceCodeCss: '',
    dataDependencies: {},
    ...(styles !== undefined && { styles }),
  }) as Component;

const library: StyleLibraryPayload = {
  id: 'brand',
  label: 'Brand',
  styles: {
    surface: {
      label: 'Surface',
      weight: 0,
      options: {
        light: { label: 'Light', value: 'bg-white' },
        dark: { label: 'Dark', value: 'bg-black text-white' },
      },
    },
  },
};

const snapshot: StyleDefinition[] = [
  {
    id: 'surface',
    label: 'Surface',
    originalLabel: 'Surface',
    description: '',
    category: '',
    weight: 0,
    status: 'stable',
    provider: 'canvas',
    providerLabel: 'Brand',
    providerType: 'site',
    library: '',
    fallbacks: {},
    notOffered: {},
    tokens: null,
    custom: null,
    options: [
      {
        id: 'light',
        label: 'Light',
        originalLabel: 'Light',
        description: '',
        value: 'bg-white',
        attribute: 'class',
        status: 'stable',
        icon: null,
        kind: 'option',
        swatch: null,
        cssVariable: null,
        folder: null,
      },
      {
        id: 'dark',
        label: 'Dark',
        originalLabel: 'Dark',
        description: '',
        value: 'bg-black text-white',
        attribute: 'class',
        status: 'stable',
        icon: null,
        kind: 'option',
        swatch: null,
        cssVariable: null,
        folder: null,
      },
    ],
  },
];

describe('styles pull and push round trip', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'styles-round-trip-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  async function pullComponents(components: Record<string, Component>) {
    const componentDir = path.join(tmpDir, 'components');
    const task = createComponentsPullTask(
      {
        listComponents: vi.fn().mockResolvedValue(components),
      } as unknown as ApiService,
      componentDir,
      false,
      { colors: [] },
      { folders: [] },
    );
    await task.prepare();
    await task.execute();
    return componentDir;
  }

  it('round trips component styles between the site and component.yml', async () => {
    const componentDir = await pullComponents({
      hero: component('hero', ['surface']),
      card: component('card', '*'),
      button: component('button', []),
    });

    for (const [name, expected] of [
      ['hero', ['surface']],
      ['card', '*'],
      ['button', undefined],
    ] as const) {
      const metadata = await readValidatedComponentMetadata(
        path.join(componentDir, name, 'component.yml'),
      );
      expect(metadata.styles).toEqual(expected);
      const payload = createComponentPayload({
        metadata,
        machineName: name,
        componentName: name,
        dataDependencies: {},
        type: 'external',
      });
      expect(payload.styles).toEqual(expected);
    }
    // Components that accept no styles keep component.yml unchanged.
    expect(
      await fs.readFile(
        path.join(componentDir, 'button', 'component.yml'),
        'utf-8',
      ),
    ).not.toContain('styles');
  });

  it('round trips named parts in the authored shape', async () => {
    const componentDir = await pullComponents({
      card: {
        ...component('card', ['surface']),
        styleParts: {
          heading: {
            label: 'Heading',
            styles: ['surface'],
            limits: { surface: { options: ['light'] } },
          },
          body: { label: 'Body', styles: ['surface'] },
        },
      },
    });
    const metadata = await readValidatedComponentMetadata(
      path.join(componentDir, 'card', 'component.yml'),
    );
    const authored = {
      heading: {
        label: 'Heading',
        styles: { surface: { options: ['light'] } },
      },
      body: { label: 'Body', styles: ['surface'] },
    };
    expect(metadata.styleParts).toEqual(authored);
    expect(
      createComponentPayload({
        metadata,
        machineName: 'card',
        componentName: 'card',
        dataDependencies: {},
        type: 'external',
      }).styleParts,
    ).toEqual(authored);
  });

  it('pulls libraries and .canvas outputs, and a push right after changes nothing', async () => {
    const componentDir = await pullComponents({
      hero: component('hero', ['surface']),
    });
    const task = createStylesPullTask(
      {
        getStyles: vi.fn().mockResolvedValue({
          active: true,
          styles: snapshot,
          permissions: { use: true, administer: true },
        }),
        listStyleLibraries: vi.fn().mockResolvedValue([library]),
      },
      {
        projectRoot: tmpDir,
        stylesDir: 'styles',
        componentDir,
        includeLibraries: true,
      },
    );
    expect((await task.prepare()).summaryLines).toEqual([
      'Styles: 1 style (.canvas/ outputs)',
      'Style libraries: 1 pull (1 new)',
    ]);
    const outcome = await task.execute();
    expect(outcome.results.every((result) => result.success)).toBe(true);

    const local = await readLocalStyleLibraries(path.join(tmpDir, 'styles'));
    expect(local).toHaveLength(1);
    expect(planStyleLibraryPush(local!, [library])).toEqual([
      expect.objectContaining({ operation: 'unchanged' }),
    ]);

    const canvasDir = path.join(tmpDir, '.canvas');
    expect(
      JSON.parse(
        await fs.readFile(path.join(canvasDir, 'styles.json'), 'utf-8'),
      ),
    ).toEqual(snapshot);
    expect(
      await fs.readFile(path.join(canvasDir, 'styles.d.ts'), 'utf-8'),
    ).toContain('    hero: {\n      surface?: "light" | "dark";\n    };');
    expect(
      await fs.readFile(path.join(canvasDir, 'styles.css'), 'utf-8'),
    ).toContain('@source inline("bg-black bg-white text-white");');
  });

  it('does nothing when the site has component styles switched off or predates them', async () => {
    for (const response of [
      null,
      {
        active: false,
        styles: [],
        permissions: { use: false, administer: false },
      },
    ]) {
      const listStyleLibraries = vi.fn();
      const task = createStylesPullTask(
        { getStyles: vi.fn().mockResolvedValue(response), listStyleLibraries },
        {
          projectRoot: tmpDir,
          stylesDir: 'styles',
          componentDir: path.join(tmpDir, 'components'),
          includeLibraries: true,
        },
      );
      expect((await task.prepare()).summaryLines).toEqual([]);
      expect(listStyleLibraries).not.toHaveBeenCalled();
    }
  });
});
