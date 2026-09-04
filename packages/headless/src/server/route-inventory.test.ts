import { describe, expect, it, vi } from 'vitest';

import { fetchRouteInventory, fetchStaticPaths } from './route-inventory';

import type { RouteInventoryEntry } from './route-inventory';

function entry(path: string, id: string): RouteInventoryEntry {
  return {
    path,
    entityType: 'canvas_page',
    id,
    uuid: `uuid-${id}`,
    langcode: 'en',
    changed: '2026-07-14T19:44:46+03:00',
  };
}

function inventoryPage(paths: RouteInventoryEntry[], next: string | null) {
  return Response.json({ paths, cursor: { next } });
}

describe('fetchRouteInventory', () => {
  it('walks the cursor pagination to completion', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(inventoryPage([entry('/', '1')], 'cursor-2'))
      .mockResolvedValueOnce(
        inventoryPage([entry('/about', '2'), entry('/contact', '3')], null),
      );

    const entries = await fetchRouteInventory({
      baseUrl: 'https://drupal.example',
      fetchImpl: fetchImpl as unknown as typeof fetch,
    });

    expect(entries.map((page) => page.path)).toEqual([
      '/',
      '/about',
      '/contact',
    ]);
    expect(entries[1]).toMatchObject({
      entityType: 'canvas_page',
      id: '2',
      uuid: 'uuid-2',
      langcode: 'en',
      changed: '2026-07-14T19:44:46+03:00',
    });
    expect(fetchImpl).toHaveBeenNthCalledWith(
      1,
      new URL('https://drupal.example/canvas/api/v0/headless/inventory'),
      expect.objectContaining({ headers: { Accept: 'application/json' } }),
    );
    expect(fetchImpl).toHaveBeenNthCalledWith(
      2,
      new URL(
        'https://drupal.example/canvas/api/v0/headless/inventory?cursor=cursor-2',
      ),
      expect.any(Object),
    );
  });

  it('passes the page size and preserves Drupal base paths', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(inventoryPage([entry('/', '1')], null));

    await fetchRouteInventory({
      baseUrl: 'https://drupal.example/cms/',
      limit: 50,
      fetchImpl: fetchImpl as unknown as typeof fetch,
    });

    expect(fetchImpl).toHaveBeenCalledWith(
      new URL(
        'https://drupal.example/cms/canvas/api/v0/headless/inventory?limit=50',
      ),
      expect.any(Object),
    );
  });

  it('throws on a non-200 answer, naming the status and URL', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(new Response('Forbidden', { status: 403 }));

    await expect(
      fetchRouteInventory({
        baseUrl: 'https://drupal.example',
        fetchImpl: fetchImpl as unknown as typeof fetch,
      }),
    ).rejects.toThrow(
      'The route inventory request failed with status 403: https://drupal.example/canvas/api/v0/headless/inventory',
    );
  });
});

describe('fetchStaticPaths', () => {
  it('reduces the inventory to its path strings', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(inventoryPage([entry('/', '1')], 'cursor-2'))
      .mockResolvedValueOnce(inventoryPage([entry('/about', '2')], null));

    await expect(
      fetchStaticPaths({
        baseUrl: 'https://drupal.example',
        fetchImpl: fetchImpl as unknown as typeof fetch,
      }),
    ).resolves.toEqual(['/', '/about']);
  });
});
