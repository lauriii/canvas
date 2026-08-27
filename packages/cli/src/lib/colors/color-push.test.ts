import { describe, expect, it, vi } from 'vitest';

import { buildColorPushPlannedResults, pushColors } from './color-push';

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

function mockApi(colors: BrandKitColorEntry[]): ApiService {
  return {
    getBrandKit: vi.fn().mockResolvedValue({ id: 'global', colors }),
    createColor: vi
      .fn()
      .mockImplementation((payload) =>
        Promise.resolve({ id: 'new-uuid', weight: 0, ...payload }),
      ),
    updateColor: vi.fn().mockResolvedValue({}),
    deleteColor: vi.fn().mockResolvedValue(undefined),
  } as unknown as ApiService;
}

describe('pushColors', () => {
  it('returns null when colors are not managed', async () => {
    const api = mockApi([]);
    expect(await pushColors(undefined, api)).toBeNull();
    expect(api.getBrandKit).not.toHaveBeenCalled();
  });

  it('creates a new color from a hex string with the full token value', async () => {
    const api = mockApi([]);
    const result = await pushColors(
      [{ name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' }],
      api,
    );

    expect(api.createColor).toHaveBeenCalledWith({
      name: 'Brand Red',
      cssVariable: '--brand-red',
      value: {
        colorSpace: 'srgb',
        components: [0.8, 0, 0],
        alpha: null,
        hex: '#cc0000',
      },
      weight: 0,
    });
    expect(result).toMatchObject({ created: 1, updated: 0, unchanged: 0 });
  });

  it('skips a color that matches the server semantically', async () => {
    const api = mockApi([remoteColor()]);
    const result = await pushColors(
      [{ name: 'Brand Red', cssVariable: '--brand-red', value: '#CC0000' }],
      api,
    );

    expect(api.createColor).not.toHaveBeenCalled();
    expect(api.updateColor).not.toHaveBeenCalled();
    expect(result).toMatchObject({ created: 0, updated: 0, unchanged: 1 });
  });

  it('updates only the changed fields', async () => {
    const api = mockApi([remoteColor()]);
    const result = await pushColors(
      [{ name: 'Primary Red', cssVariable: '--brand-red', value: '#cc0000' }],
      api,
    );

    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', {
      name: 'Primary Red',
    });
    expect(result).toMatchObject({ updated: 1 });
  });

  it('sends the full value object when the value changes', async () => {
    const api = mockApi([remoteColor()]);
    await pushColors(
      [{ name: 'Brand Red', cssVariable: '--brand-red', value: '#0000cc' }],
      api,
    );

    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', {
      value: {
        colorSpace: 'srgb',
        components: [0, 0, 0.8],
        alpha: null,
        hex: '#0000cc',
      },
    });
  });

  it('reports a server-only color without deleting it by default', async () => {
    const api = mockApi([remoteColor()]);
    const result = await pushColors([], api);

    expect(api.deleteColor).not.toHaveBeenCalled();
    expect(result?.serverOnly).toEqual(['Brand Red (--brand-red)']);
    expect(result?.deleted).toBe(0);
  });

  it('deletes server-only colors when pruning', async () => {
    const api = mockApi([remoteColor()]);
    const result = await pushColors([], api, { pruneColors: true });

    expect(api.deleteColor).toHaveBeenCalledWith('uuid-red');
    expect(result?.deleted).toBe(1);
    expect(result?.serverOnly).toEqual([]);
  });

  it('surfaces a refused prune deletion per color and keeps going', async () => {
    const inUse = remoteColor();
    const unused = remoteColor({
      id: 'uuid-blue',
      name: 'Brand Blue',
      cssVariable: '--brand-blue',
      weight: 1,
    });
    const api = mockApi([inUse, unused]);
    vi.mocked(api.deleteColor).mockImplementation((id: string) =>
      id === 'uuid-red'
        ? Promise.reject(
            new Error(
              'This color is in use in a default revision and cannot be deleted.',
            ),
          )
        : Promise.resolve(),
    );

    const result = await pushColors([], api, { pruneColors: true });

    expect(result?.deleted).toBe(1);
    const failed = result?.outcomes.find((o) => !o.success);
    expect(failed).toMatchObject({
      itemName: 'Brand Red (--brand-red)',
      operation: 'delete',
    });
    expect(failed?.detail).toContain('in use');
  });

  it('is a no-op right after a pull (matching order writes no weights)', async () => {
    const remote = [
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
        weight: 0,
      }),
    ];
    const api = mockApi(remote);
    // What a pull writes: server order, hex strings.
    const result = await pushColors(
      [
        { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
        { name: 'Brand Blue', cssVariable: '--brand-blue', value: '#0000cc' },
      ],
      api,
    );

    expect(api.createColor).not.toHaveBeenCalled();
    expect(api.updateColor).not.toHaveBeenCalled();
    expect(result).toMatchObject({ created: 0, updated: 0, unchanged: 2 });
  });

  it('reassigns weights when the file order differs from the server order', async () => {
    const remote = [
      remoteColor({ weight: 0 }),
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
    ];
    const api = mockApi(remote);
    const result = await pushColors(
      [
        { name: 'Brand Blue', cssVariable: '--brand-blue', value: '#0000cc' },
        { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
      ],
      api,
    );

    expect(api.updateColor).toHaveBeenCalledWith('uuid-blue', { weight: 0 });
    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', { weight: 1 });
    expect(result).toMatchObject({ updated: 2 });
  });

  it('appends new colors after existing ones without touching weights', async () => {
    const api = mockApi([remoteColor({ weight: 3 })]);
    await pushColors(
      [
        { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
        { name: 'Brand Blue', cssVariable: '--brand-blue', value: '#0000cc' },
      ],
      api,
    );

    expect(api.updateColor).not.toHaveBeenCalled();
    expect(api.createColor).toHaveBeenCalledWith(
      expect.objectContaining({ cssVariable: '--brand-blue', weight: 4 }),
    );
  });

  it('reassigns weights when a new color is inserted before existing ones', async () => {
    const api = mockApi([remoteColor({ weight: 0 })]);
    await pushColors(
      [
        { name: 'Brand Blue', cssVariable: '--brand-blue', value: '#0000cc' },
        { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
      ],
      api,
    );

    expect(api.createColor).toHaveBeenCalledWith(
      expect.objectContaining({ cssVariable: '--brand-blue', weight: 0 }),
    );
    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', { weight: 1 });
  });

  it('rejects an invalid file before contacting the site', async () => {
    const api = mockApi([]);
    await expect(
      pushColors([{ name: 'Bad', cssVariable: '--bad', value: '#nope' }], api),
    ).rejects.toThrow('Color config validation failed');
    expect(api.getBrandKit).not.toHaveBeenCalled();
  });

  it('includes displayFormat on create only when set', async () => {
    const api = mockApi([]);
    await pushColors(
      [
        {
          name: 'Brand Red',
          cssVariable: '--brand-red',
          value: '#cc0000',
          displayFormat: 'rgb',
        },
      ],
      api,
    );
    expect(api.createColor).toHaveBeenCalledWith(
      expect.objectContaining({ displayFormat: 'rgb' }),
    );
  });
});

describe('buildColorPushPlannedResults', () => {
  const colors: BrandKitColorFileEntry[] = [
    { name: 'Brand Red', cssVariable: '--brand-red', value: '#cc0000' },
    { name: 'Brand Blue', cssVariable: '--brand-blue', value: '#0000cc' },
  ];
  const labels = { create: 'create', update: 'update', delete: 'delete' };

  it('plans create for new and update for existing entries', () => {
    const results = buildColorPushPlannedResults(
      colors,
      [remoteColor()],
      labels,
      false,
    );
    expect(results).toEqual([
      expect.objectContaining({
        itemName: 'Brand Red (--brand-red)',
        itemType: 'Color',
        details: [{ content: 'update' }],
      }),
      expect.objectContaining({
        itemName: 'Brand Blue (--brand-blue)',
        details: [{ content: 'create' }],
      }),
    ]);
  });

  it('plans server-only deletions only when pruning', () => {
    const serverOnly = remoteColor({
      id: 'uuid-x',
      name: 'Extra',
      cssVariable: '--extra',
    });
    expect(
      buildColorPushPlannedResults([], [serverOnly], labels, false),
    ).toEqual([]);
    expect(
      buildColorPushPlannedResults([], [serverOnly], labels, true),
    ).toEqual([
      expect.objectContaining({
        itemName: 'Extra (--extra)',
        details: [{ content: 'delete' }],
      }),
    ]);
  });
});
