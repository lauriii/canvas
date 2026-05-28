import { describe, expect, it, vi } from 'vitest';

import { stripNullableKeysForConfigComponentTree } from './component-tree-payload';
import { pushRegions } from './prepare-regions-push';

import type { Region } from '../types/Region';
import type { PreparedRegion } from './prepare-regions-push';

describe('stripNullableKeysForConfigComponentTree', () => {
  it('omits null parent_uuid, slot, and label for root-level components', () => {
    const stripped = stripNullableKeysForConfigComponentTree([
      {
        uuid: 'root-uuid',
        component_id: 'js.hero',
        component_version: 'v1',
        inputs: { heading: 'Welcome' },
        parent_uuid: null,
        slot: null,
        label: null,
      },
    ]);

    expect(stripped).toEqual([
      {
        uuid: 'root-uuid',
        component_id: 'js.hero',
        component_version: 'v1',
        inputs: { heading: 'Welcome' },
      },
    ]);
  });

  it('preserves parent_uuid and slot for nested components, drops null label', () => {
    const stripped = stripNullableKeysForConfigComponentTree([
      {
        uuid: 'child',
        component_id: 'js.button',
        component_version: 'v1',
        inputs: {},
        parent_uuid: 'parent',
        slot: 'actions',
        label: null,
      },
    ]);

    expect(stripped).toEqual([
      {
        uuid: 'child',
        component_id: 'js.button',
        component_version: 'v1',
        inputs: {},
        parent_uuid: 'parent',
        slot: 'actions',
      },
    ]);
  });

  it('keeps non-empty label', () => {
    const stripped = stripNullableKeysForConfigComponentTree([
      {
        uuid: 'node',
        component_id: 'js.heading',
        component_version: 'v1',
        inputs: {},
        parent_uuid: null,
        slot: null,
        label: 'Main heading',
      },
    ]);

    expect(stripped[0].label).toBe('Main heading');
  });
});

function makePrepared(region: string): PreparedRegion {
  return {
    region,
    status: true,
    components: [],
    filePath: `/tmp/regions/${region}.json`,
  };
}

describe('pushRegions', () => {
  it('POSTs a new region without theme; server fills it server-side', async () => {
    const createRegion = vi.fn(
      async (): Promise<Region> => ({
        id: 'stark.header',
        theme: 'stark',
        region: 'header',
        status: true,
        component_tree: [],
      }),
    );
    const updateRegion = vi.fn();

    const results = await pushRegions(
      [{ index: 0, result: makePrepared('header') }],
      new Map(),
      { createRegion, updateRegion, deleteRegion: vi.fn() },
    );

    expect(createRegion).toHaveBeenCalledExactlyOnceWith({
      region: 'header',
      status: true,
      component_tree: [],
    });
    expect(updateRegion).not.toHaveBeenCalled();
    expect(results[0]).toMatchObject({
      success: true,
      result: { region: 'header', operation: 'Created' },
    });
  });

  it('PATCHes an existing region using the full id from the name map', async () => {
    const createRegion = vi.fn();
    const updateRegion = vi.fn(
      async (): Promise<Region> => ({
        id: 'olivero.header',
        theme: 'olivero',
        region: 'header',
        status: false,
        component_tree: [],
      }),
    );

    const results = await pushRegions(
      [{ index: 0, result: makePrepared('header') }],
      new Map([['header', 'olivero.header']]),
      { createRegion, updateRegion, deleteRegion: vi.fn() },
    );

    expect(updateRegion).toHaveBeenCalledWith('olivero.header', {
      status: true,
      component_tree: [],
    });
    expect(createRegion).not.toHaveBeenCalled();
    expect(results[0]).toMatchObject({
      success: true,
      result: { region: 'header', operation: 'Updated' },
    });
  });

  it('DELETEs remote regions absent locally using the name map to resolve the backend id', async () => {
    const createRegion = vi.fn();
    const updateRegion = vi.fn();
    const deleteRegion = vi.fn(async (): Promise<void> => {});

    const results = await pushRegions(
      [],
      new Map([
        ['footer', 'olivero.footer'],
        ['sidebar', 'olivero.sidebar'],
      ]),
      { createRegion, updateRegion, deleteRegion },
      ['footer', 'sidebar'],
    );

    expect(createRegion).not.toHaveBeenCalled();
    expect(updateRegion).not.toHaveBeenCalled();
    expect(deleteRegion).toHaveBeenCalledTimes(2);
    expect(deleteRegion).toHaveBeenCalledWith('olivero.footer');
    expect(deleteRegion).toHaveBeenCalledWith('olivero.sidebar');
    expect(results).toHaveLength(2);
    expect(results[0]).toMatchObject({
      success: true,
      result: { region: 'footer', operation: 'Deleted' },
    });
    expect(results[1]).toMatchObject({
      success: true,
      result: { region: 'sidebar', operation: 'Deleted' },
    });
  });
});
