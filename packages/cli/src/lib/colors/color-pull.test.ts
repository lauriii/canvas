import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BRAND_KIT_SCHEMA_URL } from '@drupal-canvas/discovery';

import {
  planColorPull,
  readBrandKitColorsFile,
  writeBrandKitColorsConfig,
} from './color-pull';
import { pushColors } from './color-push';

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
      components: [204 / 255, 0, 0],
      alpha: null,
      hex: '#cc0000',
    },
    displayFormat: null,
    weight: 0,
    ...overrides,
  };
}

function remoteBlue(
  overrides: Partial<BrandKitColorEntry> = {},
): BrandKitColorEntry {
  return remoteColor({
    id: 'uuid-blue',
    name: 'Brand Blue',
    cssVariable: '--brand-blue',
    value: {
      colorSpace: 'srgb',
      components: [0, 0, 204 / 255],
      alpha: null,
      hex: '#0000cc',
    },
    ...overrides,
  });
}

describe('planColorPull', () => {
  it('writes new server colors as one-line entries when the name derives from the key', () => {
    const plan = planColorPull([remoteColor()], undefined);
    expect(plan.colors).toEqual({ 'brand-red': '#cc0000' });
    expect(plan.added).toEqual(['Brand Red (--brand-red)']);
    expect(plan.changed).toBe(true);
  });

  it('writes a wrapper entry when the server name does not derive from the key', () => {
    const plan = planColorPull(
      [remoteColor({ name: 'My Fancy Red' })],
      undefined,
    );
    expect(plan.colors).toEqual({
      'brand-red': { value: '#cc0000', name: 'My Fancy Red' },
    });
  });

  it('serializes an RGB-displayed color as an rgb() string carrying the format', () => {
    const plan = planColorPull(
      [
        remoteColor({
          cssVariable: '--ink',
          name: 'Ink',
          displayFormat: 'rgb',
          value: {
            colorSpace: 'srgb',
            components: [20 / 255, 24 / 255, 31 / 255],
            alpha: null,
            hex: '#14181f',
          },
        }),
      ],
      undefined,
    );
    expect(plan.colors).toEqual({ ink: 'rgb(20, 24, 31)' });
  });

  it('asserts a display format the serialized string cannot carry', () => {
    // A display format that mismatches the value form (possible via the
    // API) survives the pull as an explicit wrapper field.
    const plan = planColorPull(
      [remoteColor({ displayFormat: 'hsl' })],
      undefined,
    );
    expect(plan.colors).toEqual({
      'brand-red': { value: '#cc0000', displayFormat: 'hsl' },
    });
  });

  it('serializes hsl colors as hsl strings', () => {
    const plan = planColorPull(
      [
        remoteColor({
          cssVariable: '--overlay',
          name: 'Overlay',
          value: {
            colorSpace: 'hsl',
            components: [220, 60, 50],
            alpha: 0.5,
            hex: '#3366cc',
          },
        }),
      ],
      undefined,
    );
    expect(plan.colors).toEqual({ overlay: 'hsla(220, 60%, 50%, 0.5)' });
  });

  it('keeps a semantically equal local entry and its key spelling verbatim', () => {
    const local = { '--brand-red': '#CC0000' };
    const plan = planColorPull([remoteColor()], local);
    expect(plan.colors).toEqual({ '--brand-red': '#CC0000' });
    expect(plan.unchanged).toBe(1);
    expect(plan.changed).toBe(false);
  });

  it('does not rewrite a one-line entry when only the server name differs', () => {
    const plan = planColorPull([remoteColor({ name: 'My Fancy Red' })], {
      'brand-red': '#cc0000',
    });
    expect(plan.colors).toEqual({ 'brand-red': '#cc0000' });
    expect(plan.changed).toBe(false);
  });

  it('updates an entry that asserts a name the server renamed', () => {
    const plan = planColorPull([remoteColor({ name: 'New Name' })], {
      'brand-red': { value: '#cc0000', name: 'Old Name' },
    });
    expect(plan.colors).toEqual({
      'brand-red': { value: '#cc0000', name: 'New Name' },
    });
    expect(plan.updated).toEqual(['New Name (--brand-red)']);
  });

  it('updates a local entry whose value differs on the server', () => {
    const plan = planColorPull([remoteColor({ value: remoteBlue().value })], {
      'brand-red': '#cc0000',
    });
    expect(plan.colors).toEqual({ 'brand-red': '#0000cc' });
    expect(plan.updated).toEqual(['Brand Red (--brand-red)']);
    expect(plan.changed).toBe(true);
  });

  it('reorders the file to the server palette order', () => {
    const plan = planColorPull(
      [remoteBlue({ weight: 0 }), remoteColor({ weight: 1 })],
      { 'brand-red': '#cc0000', 'brand-blue': '#0000cc' },
    );
    expect(Object.keys(plan.colors)).toEqual(['brand-blue', 'brand-red']);
    expect(plan.changed).toBe(true);
    expect(plan.unchanged).toBe(2);
  });

  it('keeps local-only entries at the end and reports them', () => {
    const plan = planColorPull([remoteColor()], { draft: '#123456' });
    expect(plan.colors).toEqual({
      'brand-red': '#cc0000',
      draft: '#123456',
    });
    expect(plan.localOnly).toEqual(['Draft (--draft)']);
  });

  it('serializes non-exact components as a token object', () => {
    const plan = planColorPull(
      [
        remoteColor({
          value: {
            colorSpace: 'srgb',
            components: [0.1, 0.2, 0.3],
            alpha: null,
            hex: '#1a334d',
          },
        }),
      ],
      undefined,
    );
    expect(plan.colors['brand-red']).toEqual({
      colorSpace: 'srgb',
      components: [0.1, 0.2, 0.3],
      hex: '#1a334d',
    });
  });

  it('keeps the first duplicate variable entry and reports the rest', () => {
    const plan = planColorPull([remoteColor()], {
      'brand-red': '#CC0000',
      '--brand-red': '#0000cc',
    });
    expect(plan.colors).toEqual({ 'brand-red': '#CC0000' });
    expect(plan.duplicates).toEqual(['--brand-red']);
    expect(plan.changed).toBe(true);
  });

  it('preserves the full spelling of a variable whose sliced key would collapse', () => {
    // `----brand` is a valid custom property; its sliced key `--brand`
    // would normalize back to a different variable.
    const plan = planColorPull(
      [remoteColor({ cssVariable: '----brand', name: 'Brand' })],
      undefined,
    );
    expect(Object.keys(plan.colors)).toEqual(['----brand']);
  });

  it('keeps and reports entries with invalid keys instead of dropping them', () => {
    const plan = planColorPull([], { '1bad': '#cc0000' });
    expect(plan.colors).toEqual({ '1bad': '#cc0000' });
    expect(plan.localOnly).toEqual(['"1bad" (invalid color key, kept)']);
    expect(plan.changed).toBe(false);
  });

  it('repairs a local entry whose value no longer parses', () => {
    const plan = planColorPull([remoteColor()], { 'brand-red': 'nonsense' });
    expect(plan.colors).toEqual({ 'brand-red': '#cc0000' });
    expect(plan.updated).toEqual(['Brand Red (--brand-red)']);
  });

  it('does not mark an empty file changed when the server has no colors', () => {
    expect(planColorPull([], undefined).changed).toBe(false);
    expect(planColorPull([], {}).changed).toBe(false);
  });

  it('leaves existing local entries alone with skipOverwrite', () => {
    const plan = planColorPull(
      [remoteColor(), remoteBlue({ weight: 1 })],
      { 'brand-red': '#123456' },
      { skipOverwrite: true },
    );
    expect(plan.colors).toEqual({
      'brand-red': '#123456',
      'brand-blue': '#0000cc',
    });
    expect(plan.added).toEqual(['Brand Blue (--brand-blue)']);
    expect(plan.unchanged).toBe(1);
    expect(plan.changed).toBe(true);
  });
});

describe('readBrandKitColorsFile and writeBrandKitColorsConfig', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'color-pull-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('distinguishes an absent colors key from an empty map', async () => {
    const configPath = path.join(tmpDir, 'canvas.brand-kit.json');
    expect(await readBrandKitColorsFile(tmpDir)).toBeUndefined();
    await fs.writeFile(configPath, JSON.stringify({ fonts: {} }), 'utf-8');
    expect(await readBrandKitColorsFile(tmpDir)).toBeUndefined();
    await fs.writeFile(configPath, JSON.stringify({ colors: {} }), 'utf-8');
    expect(await readBrandKitColorsFile(tmpDir)).toEqual({});
  });

  it('preserves other top-level keys on write', async () => {
    const configPath = path.join(tmpDir, 'canvas.brand-kit.json');
    await fs.writeFile(
      configPath,
      `${JSON.stringify({ fonts: { families: [{ name: 'Inter' }] } }, null, 2)}\n`,
      'utf-8',
    );
    await writeBrandKitColorsConfig(tmpDir, { 'brand-red': '#cc0000' });
    const raw = await fs.readFile(configPath, 'utf-8');
    expect(JSON.parse(raw)).toEqual({
      fonts: { families: [{ name: 'Inter' }] },
      colors: { 'brand-red': '#cc0000' },
    });
    expect(raw.endsWith('\n')).toBe(true);
  });

  it('creates the file with a $schema reference when it does not exist', async () => {
    await writeBrandKitColorsConfig(tmpDir, {});
    const raw = await fs.readFile(
      path.join(tmpDir, 'canvas.brand-kit.json'),
      'utf-8',
    );
    expect(JSON.parse(raw)).toEqual({
      $schema: BRAND_KIT_SCHEMA_URL,
      colors: {},
    });
    // The reference comes first so the file reads like its siblings.
    expect(raw.startsWith('{\n  "$schema"')).toBe(true);
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
      remoteColor({ name: 'My Fancy Red', weight: 0 }),
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
    // Edit: change a value, add a color, reorder.
    const fileColors = {
      'brand-blue': '#0000cc',
      overlay: firstPull.colors.overlay,
      'brand-red': { value: '#dd0000', name: 'My Fancy Red' },
    };

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
