import { afterEach, describe, expect, it, vi } from 'vitest';

import { cacheCanvasTags } from './cache';
import { createDraftRouteHandlers } from './route-handlers';

const nextCache = vi.hoisted(() => ({
  cacheTag: vi.fn(),
  cacheLife: vi.fn(),
  revalidateTag: vi.fn(),
}));
const nextServer = vi.hoisted(() => ({
  after: vi.fn<(task: () => Promise<void>) => void>(),
}));
vi.mock('next/cache', () => nextCache);
vi.mock('next/server', () => nextServer);
vi.mock('next/headers', () => ({}));
vi.mock('next/navigation', () => ({}));

afterEach(() => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

describe('cacheCanvasTags', () => {
  it('tags the cache entry with the Drupal cache tags', () => {
    cacheCanvasTags({ cacheTags: ['node:1', 'node_list'], cacheMaxAge: -1 });
    expect(nextCache.cacheTag.mock.calls).toEqual([['node:1', 'node_list']]);
    expect(nextCache.cacheLife).not.toHaveBeenCalled();
  });

  it('passes at most 128 tags per call, which Next.js accumulates', () => {
    const cacheTags = Array.from({ length: 300 }, (_, i) => `node:${i}`);
    cacheCanvasTags({ cacheTags, cacheMaxAge: -1 });
    expect(nextCache.cacheTag.mock.calls).toEqual([
      cacheTags.slice(0, 128),
      cacheTags.slice(128, 256),
      cacheTags.slice(256),
    ]);
  });

  it.each([60, 0])('caches for at most Drupal max-age %i', (cacheMaxAge) => {
    cacheCanvasTags({ cacheTags: ['node:1'], cacheMaxAge });
    expect(nextCache.cacheLife).toHaveBeenCalledWith({
      revalidate: cacheMaxAge,
      expire: cacheMaxAge,
    });
  });
});

describe('createDraftRouteHandlers().revalidate', () => {
  it('answers, then reads the tags from the configured site and expires them', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Response.json({ tags: ['node:1', 'node_list'] })),
    );
    // No CANVAS_SITE_URL: the configuration passed in applies.
    const { revalidate } = createDraftRouteHandlers({
      baseUrl: 'https://drupal.example',
    });
    const response = await revalidate.POST(
      new Request('https://app.example/api/canvas/revalidate', {
        method: 'POST',
        body: JSON.stringify({ id: 'abc' }),
      }),
    );
    expect(response.status).toBe(202);
    expect(fetch).not.toHaveBeenCalled();

    await nextServer.after.mock.calls[0][0]();
    expect(fetch).toHaveBeenCalledWith(
      'https://drupal.example/canvas-headless/cache-invalidation/abc',
      expect.anything(),
    );
    expect(nextCache.revalidateTag.mock.calls).toEqual([
      ['node:1', { expire: 0 }],
      ['node_list', { expire: 0 }],
    ]);
  });
});
