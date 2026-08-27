import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { planColorPull, writeBrandKitColorsConfig } from './color-pull';
import { pushColors } from './color-push';

import type { BrandKitColorFileEntry } from '@drupal-canvas/discovery';
import type { ApiService } from '../../services/api';
import type { BrandKitColorEntry } from '../../types/Component';

function remoteColor(
  overrides: Partial<BrandKitColorEntry> = {},
): BrandKitColorEntry {
  return {
    id: 'uuid-red',
    name: 'Brand Red',
    cssVariable: '--brand-red',
    value: {
      colorSpace: 'srgb',
      components: [0.8, 0, 0],
      alpha: null,
      hex: '#cc0000',
    },
    displayFormat: null,
    weight: 0,
    ...overrides,
  };
}

describe('planColorPull', () => {
  it('serializes new server colors as hand-editable entries', () => {
    const plan = planColorPull([remoteColor()], undefined);
    expect(plan.colors).toEqual([
      { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
    ]);
    expect(plan.added).toEqual(['Brand Red (--brand-red)']);
    expect(plan.changed).toBe(true);
  });

  it('keeps a semantically equal local entry verbatim', () => {
    const local: BrandKitColorFileEntry = {
      name: 'Brand Red',
      cssVariable: '--brand-red',
      value: '#CC0000',
    };
    const plan = planColorPull([remoteColor()], [local]);
    expect(plan.colors[0]).toBe(local);
    expect(plan.unchanged).toBe(1);
    expect(plan.changed).toBe(false);
  });

  it('updates a local entry whose value differs on the server', () => {
    const plan = planColorPull(
      [
        remoteColor({
          value: {
            colorSpace: 'srgb',
            components: [0, 0, 0.8],
            alpha: null,
            hex: '#0000cc',
          },
        }),
      ],
      [{ name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' }],
    );
    expect(plan.colors).toEqual([
      { name: 'Brand Red', cssVariable: '--brand-red', value: '#0000cc' },
    ]);
    expect(plan.updated).toEqual(['Brand Red (--brand-red)']);
    expect(plan.changed).toBe(true);
  });

  it('reorders the file to the server palette order', () => {
    const red: BrandKitColorFileEntry = {
      name: 'Brand Red',
      cssVariable: '--brand-red',
      value: '#cc0000',
    };
    const blue: BrandKitColorFileEntry = {
      name: 'Brand Blue',
      cssVariable: '--brand-blue',
      value: '#0000cc',
    };
    const plan = planColorPull(
      [
        remoteColor({
          id: 'uuid-blue',
          name: 'Brand Blue',
          cssVariable: '--brand-blue',
          value: {
            colorSpace: 'srgb',
            components: [0, 0, 0.8],
            alpha: null,
            hex: '#0000cc',
          },
          weight: 0,
        }),
        remoteColor({ weight: 1 }),
      ],
      [red, blue],
    );
    expect(plan.colors).toEqual([blue, red]);
    expect(plan.changed).toBe(true);
    expect(plan.unchanged).toBe(2);
  });

  it('keeps local-only entries at the end and reports them', () => {
    const localOnly: BrandKitColorFileEntry = {
      name: 'Draft',
      cssVariable: '--draft',
      value: '#123456',
    };
    const plan = planColorPull([remoteColor()], [localOnly]);
    expect(plan.colors).toEqual([
      { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
      localOnly,
    ]);
    expect(plan.localOnly).toEqual(['Draft (--draft)']);
  });

  it('serializes a translucent color as a token object', () => {
    const plan = planColorPull(
      [
        remoteColor({
          value: {
            colorSpace: 'srgb',
            components: [0.8, 0, 0],
            alpha: 0.5,
            hex: '#cc0000',
          },
        }),
      ],
      undefined,
    );
    expect(plan.colors[0].value).toEqual({
      colorSpace: 'srgb',
      components: [0.8, 0, 0],
      alpha: 0.5,
      hex: '#cc0000',
    });
  });

  it('includes displayFormat only when the server has one', () => {
    const plan = planColorPull(
      [remoteColor({ displayFormat: 'rgb' })],
      undefined,
    );
    expect(plan.colors[0]).toEqual({
      name: 'Brand Red',
      cssVariable: '--brand-red',
      value: '#cc0000',
      displayFormat: 'rgb',
    });
  });

  it('does not mark an empty file changed when the server has no colors', () => {
    expect(planColorPull([], undefined).changed).toBe(false);
    expect(planColorPull([], []).changed).toBe(false);
  });

  it('keeps the first duplicate cssVariable entry and reports the rest', () => {
    const first: BrandKitColorFileEntry = {
      name: 'Brand Red',
      cssVariable: '--brand-red',
      value: '#CC0000',
    };
    const second: BrandKitColorFileEntry = {
      name: 'Second',
      cssVariable: '--brand-red',
      value: '#0000cc',
    };
    const plan = planColorPull([remoteColor()], [first, second]);
    expect(plan.colors).toEqual([first]);
    expect(plan.colors[0]).toBe(first);
    expect(plan.duplicates).toEqual(['Second (--brand-red)']);
  });

  it('does not crash on a malformed local entry and repairs it from the server', () => {
    const broken = {
      name: 'Brand Red',
      cssVariable: '--brand-red',
    } as BrandKitColorFileEntry;
    const plan = planColorPull([remoteColor()], [broken]);
    expect(plan.colors).toEqual([
      { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
    ]);
    expect(plan.updated).toEqual(['Brand Red (--brand-red)']);
  });

  it('reports a junk local-only entry without crashing', () => {
    const junk = {} as BrandKitColorFileEntry;
    const plan = planColorPull([], [junk]);
    expect(plan.localOnly).toEqual(['(unnamed) (no cssVariable)']);
    expect(plan.colors).toEqual([junk]);
  });

  it('leaves existing local entries alone with skipOverwrite', () => {
    const local: BrandKitColorFileEntry = {
      name: 'Brand Red',
      cssVariable: '--brand-red',
      value: '#123456',
    };
    const plan = planColorPull(
      [
        remoteColor(),
        remoteColor({
          id: 'uuid-blue',
          name: 'Brand Blue',
          cssVariable: '--brand-blue',
          value: {
            colorSpace: 'srgb',
            components: [0, 0, 0.8],
            alpha: null,
            hex: '#0000cc',
          },
          weight: 1,
        }),
      ],
      [local],
      { skipOverwrite: true },
    );
    expect(plan.colors).toEqual([
      local,
      { name: 'Brand Blue', cssVariable: '--brand-blue', value: '#0000cc' },
    ]);
    expect(plan.added).toEqual(['Brand Blue (--brand-blue)']);
    expect(plan.unchanged).toBe(1);
    expect(plan.changed).toBe(true);
  });
});

describe('writeBrandKitColorsConfig', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'color-pull-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('preserves other top-level keys', async () => {
    const configPath = path.join(tmpDir, 'canvas.brand-kit.json');
    await fs.writeFile(
      configPath,
      `${JSON.stringify({ fonts: { families: [{ name: 'Inter' }] } }, null, 2)}\n`,
      'utf-8',
    );
    await writeBrandKitColorsConfig(tmpDir, [
      { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
    ]);
    const raw = await fs.readFile(configPath, 'utf-8');
    expect(JSON.parse(raw)).toEqual({
      fonts: { families: [{ name: 'Inter' }] },
      colors: [
        { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
      ],
    });
    expect(raw.endsWith('\n')).toBe(true);
  });

  it('creates the file when it does not exist', async () => {
    await writeBrandKitColorsConfig(tmpDir, []);
    const raw = await fs.readFile(
      path.join(tmpDir, 'canvas.brand-kit.json'),
      'utf-8',
    );
    expect(JSON.parse(raw)).toEqual({ colors: [] });
  });
});

describe('round trip', () => {
  // A stateful fake server: colors live in a store, push mutates it the way
  // the real API endpoints would, pull plans read from it.
  function fakeServer(initial: BrandKitColorEntry[]) {
    const store = [...initial];
    let nextId = 100;
    const sort = () =>
      store.sort((a, b) => a.weight - b.weight || a.name.localeCompare(b.name));
    const api = {
      getBrandKit: vi
        .fn()
        .mockImplementation(() =>
          Promise.resolve({ id: 'global', colors: [...sort()] }),
        ),
      createColor: vi.fn().mockImplementation((payload) => {
        const entry: BrandKitColorEntry = {
          id: `uuid-${nextId++}`,
          displayFormat: null,
          weight: 0,
          ...payload,
        };
        store.push(entry);
        return Promise.resolve(entry);
      }),
      updateColor: vi.fn().mockImplementation((id, changes) => {
        const entry = store.find((c) => c.id === id);
        Object.assign(entry as object, changes);
        return Promise.resolve(entry);
      }),
      deleteColor: vi.fn().mockImplementation((id) => {
        store.splice(
          store.findIndex((c) => c.id === id),
          1,
        );
        return Promise.resolve();
      }),
    } as unknown as ApiService;
    return { api, store };
  }

  it('pull right after push produces no file change', async () => {
    const { api } = fakeServer([
      remoteColor({ weight: 0 }),
      remoteColor({
        id: 'uuid-overlay',
        name: 'Overlay',
        cssVariable: '--overlay',
        value: {
          colorSpace: 'hsl',
          components: [220, 60, 50],
          alpha: 0.5,
          hex: '#3366cc',
        },
        displayFormat: 'hsl',
        weight: 1,
      }),
    ]);

    // First pull into an empty project.
    const firstPull = planColorPull(
      (await api.getBrandKit()).colors ?? [],
      undefined,
    );
    let fileColors = firstPull.colors;

    // Edit: change a value, add a color, reorder.
    fileColors = [
      { name: 'Brand Blue', cssVariable: '--brand-blue', value: '#0000cc' },
      { ...fileColors[1] },
      { ...fileColors[0], value: '#dd0000' },
    ];

    await pushColors(fileColors, api);

    // Pull right after the push: the file must not change.
    const secondPull = planColorPull(
      (await api.getBrandKit()).colors ?? [],
      fileColors,
    );
    expect(secondPull.changed).toBe(false);
    expect(secondPull.colors).toEqual(fileColors);

    // And a second push right after that pull writes nothing.
    vi.mocked(api.createColor).mockClear();
    vi.mocked(api.updateColor).mockClear();
    const result = await pushColors(fileColors, api);
    expect(api.createColor).not.toHaveBeenCalled();
    expect(api.updateColor).not.toHaveBeenCalled();
    expect(result).toMatchObject({ created: 0, updated: 0, unchanged: 3 });
  });
});
