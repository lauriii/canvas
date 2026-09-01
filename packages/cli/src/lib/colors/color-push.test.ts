import { describe, expect, it, vi } from 'vitest';

import { buildColorPushPlannedResults, pushColors } from './color-push';

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

  it('creates a new color from a one-line hex entry with a derived name', async () => {
    const api = mockApi([]);
    const result = await pushColors({ 'brand-red': '#cc0000' }, api);

    expect(api.createColor).toHaveBeenCalledWith({
      name: 'Brand Red',
      cssVariable: '--brand-red',
      value: {
        colorSpace: 'srgb',
        components: [204 / 255, 0, 0],
        alpha: null,
        hex: '#cc0000',
      },
      weight: 0,
      displayFormat: 'hex',
    });
    expect(result).toMatchObject({ created: 1, updated: 0, unchanged: 0 });
  });

  it('derives the display format from the value form on create', async () => {
    const api = mockApi([]);
    await pushColors({ overlay: 'hsla(220, 60%, 50%, 0.5)' }, api);
    expect(api.createColor).toHaveBeenCalledWith(
      expect.objectContaining({ displayFormat: 'hsl' }),
    );
  });

  it('treats an asserted null display format as the server default on create', async () => {
    const api = mockApi([]);
    await pushColors(
      { 'brand-red': { value: '#cc0000', displayFormat: null } },
      api,
    );
    expect(api.createColor).toHaveBeenCalledWith(
      expect.not.objectContaining({ displayFormat: expect.anything() }),
    );
  });

  it('uses the explicit name and display format from the wrapper form', async () => {
    const api = mockApi([]);
    await pushColors(
      { accent: { value: '#00cc00', name: 'Accent green' } },
      api,
    );
    expect(api.createColor).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'Accent green' }),
    );
  });

  it('skips a color that matches the server semantically', async () => {
    const api = mockApi([remoteColor()]);
    const result = await pushColors({ 'brand-red': '#CC0000' }, api);

    expect(api.createColor).not.toHaveBeenCalled();
    expect(api.updateColor).not.toHaveBeenCalled();
    expect(result).toMatchObject({ created: 0, updated: 0, unchanged: 1 });
  });

  it('never renames an existing color from a derived name', async () => {
    // Server name "Brand Red" differs from what the key would derive if the
    // key were e.g. "brand-red" with a custom UI label; a one-line entry
    // must not push a rename.
    const api = mockApi([remoteColor({ name: 'My Fancy Red' })]);
    const result = await pushColors({ 'brand-red': '#cc0000' }, api);

    expect(api.updateColor).not.toHaveBeenCalled();
    expect(result).toMatchObject({ unchanged: 1 });
  });

  it('renames when the wrapper form asserts a name', async () => {
    const api = mockApi([remoteColor()]);
    await pushColors(
      { 'brand-red': { value: '#cc0000', name: 'Primary Red' } },
      api,
    );
    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', {
      name: 'Primary Red',
    });
  });

  it('updates the display format only when asserted', async () => {
    const api = mockApi([remoteColor({ displayFormat: 'hex' })]);
    // Derived format from the hex string is 'hex' — matches; and even if the
    // server had another format, a one-line entry must not change it.
    await pushColors({ 'brand-red': '#cc0000' }, api);
    expect(api.updateColor).not.toHaveBeenCalled();

    await pushColors(
      { 'brand-red': { value: '#cc0000', displayFormat: 'rgb' } },
      api,
    );
    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', {
      displayFormat: 'rgb',
    });
  });

  it('sends the full value object when the value changes', async () => {
    const api = mockApi([remoteColor()]);
    await pushColors({ 'brand-red': '#0000cc' }, api);

    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', {
      value: {
        colorSpace: 'srgb',
        components: [0, 0, 204 / 255],
        alpha: null,
        hex: '#0000cc',
      },
    });
  });

  it('reports a server-only color without deleting it by default', async () => {
    const api = mockApi([remoteColor()]);
    const result = await pushColors({}, api);

    expect(api.deleteColor).not.toHaveBeenCalled();
    expect(result?.serverOnly).toEqual(['Brand Red (--brand-red)']);
    expect(result?.deleted).toBe(0);
  });

  it('deletes server-only colors when pruning', async () => {
    const api = mockApi([remoteColor()]);
    const result = await pushColors({}, api, { pruneColors: true });

    expect(api.deleteColor).toHaveBeenCalledWith('uuid-red');
    expect(result?.deleted).toBe(1);
    expect(result?.serverOnly).toEqual([]);
  });

  it('prunes server-only colors before creating new ones', async () => {
    const api = mockApi([remoteColor()]);
    await pushColors({ 'brand-blue': '#0000cc' }, api, { pruneColors: true });

    const deleteOrder = vi.mocked(api.deleteColor).mock.invocationCallOrder[0];
    const createOrder = vi.mocked(api.createColor).mock.invocationCallOrder[0];
    expect(deleteOrder).toBeLessThan(createOrder);
  });

  it('surfaces a refused prune deletion per color and keeps going', async () => {
    const api = mockApi([remoteColor(), remoteBlue({ weight: 1 })]);
    vi.mocked(api.deleteColor).mockImplementation((id: string) =>
      id === 'uuid-red'
        ? Promise.reject(
            new Error(
              'This color is in use in a default revision and cannot be deleted.',
            ),
          )
        : Promise.resolve(),
    );

    const result = await pushColors({}, api, { pruneColors: true });

    expect(result?.deleted).toBe(1);
    const failed = result?.outcomes.find((o) => !o.success);
    expect(failed).toMatchObject({
      itemName: 'Brand Red (--brand-red)',
      operation: 'delete',
    });
    expect(failed?.detail).toContain('in use');
  });

  it('is a no-op right after a pull (matching order writes no weights)', async () => {
    const api = mockApi([remoteColor(), remoteBlue()]);
    // What a pull writes: server order, hex strings.
    const result = await pushColors(
      { 'brand-red': '#cc0000', 'brand-blue': '#0000cc' },
      api,
    );

    expect(api.createColor).not.toHaveBeenCalled();
    expect(api.updateColor).not.toHaveBeenCalled();
    expect(result).toMatchObject({ created: 0, updated: 0, unchanged: 2 });
  });

  it('reassigns weights when the map order differs from the server order', async () => {
    const api = mockApi([
      remoteColor({ weight: 0 }),
      remoteBlue({ weight: 1 }),
    ]);
    const result = await pushColors(
      { 'brand-blue': '#0000cc', 'brand-red': '#cc0000' },
      api,
    );

    expect(api.updateColor).toHaveBeenCalledWith('uuid-blue', { weight: 0 });
    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', { weight: 1 });
    expect(result).toMatchObject({ updated: 2 });
  });

  it('appends new colors after existing ones without touching weights', async () => {
    const api = mockApi([remoteColor({ weight: 3 })]);
    await pushColors({ 'brand-red': '#cc0000', 'brand-blue': '#0000cc' }, api);

    expect(api.updateColor).not.toHaveBeenCalled();
    expect(api.createColor).toHaveBeenCalledWith(
      expect.objectContaining({ cssVariable: '--brand-blue', weight: 4 }),
    );
  });

  it('reassigns weights when a new color is inserted before existing ones', async () => {
    const api = mockApi([remoteColor({ weight: 0 })]);
    await pushColors({ 'brand-blue': '#0000cc', 'brand-red': '#cc0000' }, api);

    expect(api.createColor).toHaveBeenCalledWith(
      expect.objectContaining({ cssVariable: '--brand-blue', weight: 0 }),
    );
    expect(api.updateColor).toHaveBeenCalledWith('uuid-red', { weight: 1 });
  });

  it('rejects two colors sharing a name before contacting the site', async () => {
    const api = mockApi([]);
    await expect(
      pushColors(
        {
          'primary-a': { value: '#cc0000', name: 'Primary' },
          'primary-b': { value: '#0000cc', name: 'Primary' },
        },
        api,
      ),
    ).rejects.toThrow('duplicate name "Primary"');
    expect(api.getBrandKit).not.toHaveBeenCalled();
  });

  it('rejects an invalid file before contacting the site', async () => {
    const api = mockApi([]);
    await expect(pushColors({ bad: '#nope' }, api)).rejects.toThrow(
      'Color config validation failed',
    );
    expect(api.getBrandKit).not.toHaveBeenCalled();
  });
});

describe('buildColorPushPlannedResults', () => {
  const colors = {
    'brand-red': '#cc0000',
    'brand-blue': '#0000cc',
  };
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
      buildColorPushPlannedResults({}, [serverOnly], labels, false),
    ).toEqual([]);
    expect(
      buildColorPushPlannedResults({}, [serverOnly], labels, true),
    ).toEqual([
      expect.objectContaining({
        itemName: 'Extra (--extra)',
        details: [{ content: 'delete' }],
      }),
    ]);
  });
});
