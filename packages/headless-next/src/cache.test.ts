import { afterEach, describe, expect, it, vi } from 'vitest';

import { cacheCanvasTags } from './cache';
import { createDraftRouteHandlers } from './route-handlers';

const nextCache = vi.hoisted(() => ({
  cacheTag: vi.fn(),
  revalidateTag: vi.fn(),
}));
vi.mock('next/cache', () => nextCache);
vi.mock('next/headers', () => ({}));
vi.mock('next/navigation', () => ({}));

afterEach(() => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

describe('cacheCanvasTags', () => {
  it('tags the cache entry with the Drupal cache tags', () => {
    cacheCanvasTags({ cacheTags: ['node:1', 'node_list'] });
    expect(nextCache.cacheTag.mock.calls).toEqual([['node:1', 'node_list']]);
  });

  it('passes at most 128 tags per call, which Next.js accumulates', () => {
    const cacheTags = Array.from({ length: 300 }, (_, i) => `node:${i}`);
    cacheCanvasTags({ cacheTags });
    expect(nextCache.cacheTag.mock.calls).toEqual([
      cacheTags.slice(0, 128),
      cacheTags.slice(128, 256),
      cacheTags.slice(256),
    ]);
  });

  it('tags a missing result to expire when content changes', () => {
    cacheCanvasTags(null);
    expect(nextCache.cacheTag.mock.calls).toEqual([
      ['4xx-response', 'http_response'],
    ]);
  });
});

describe('createDraftRouteHandlers().revalidate', () => {
  it('reads the tags from the configured site and expires them', async () => {
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
    expect(response.status).toBe(204);
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
