import { describe, expect, it, vi } from 'vitest';

import { cacheCanvasTags } from './cache';

import type { AstroCacheContext } from './cache';

const { isDraftModeEnabled } = vi.hoisted(() => ({
  isDraftModeEnabled: vi.fn(() => false),
}));
vi.mock('./server', () => ({ isDraftModeEnabled }));

function context(enabled = true) {
  const cache = { enabled, set: vi.fn(), invalidate: vi.fn() };
  return { cache, context: { cache } as unknown as AstroCacheContext };
}

describe('cacheCanvasTags()', () => {
  it('applies the union of tags and the shortest max-age', () => {
    const { cache, context: astro } = context();
    cacheCanvasTags(
      astro,
      { cacheTags: ['node:1', 'a'], cacheMaxAge: 60 },
      { cacheTags: ['a', 'b'], cacheMaxAge: -1 },
      { cacheTags: [], cacheMaxAge: 30 },
    );
    expect(cache.set).toHaveBeenCalledExactlyOnceWith({
      tags: ['node:1', 'a', 'b'],
      maxAge: 30,
    });
  });

  it('caches for a year without a limit', () => {
    const { cache, context: astro } = context();
    cacheCanvasTags(astro, { cacheTags: ['x'], cacheMaxAge: -1 });
    expect(cache.set).toHaveBeenCalledWith({ tags: ['x'], maxAge: 31_536_000 });
  });

  it('never caches a draft session', () => {
    isDraftModeEnabled.mockReturnValueOnce(true);
    const { cache, context: astro } = context();
    cacheCanvasTags(astro, { cacheTags: ['x'], cacheMaxAge: -1 });
    expect(cache.set).toHaveBeenCalledExactlyOnceWith(false);
  });

  it('does nothing without a cache provider', () => {
    const { cache, context: astro } = context(false);
    cacheCanvasTags(astro, { cacheTags: ['x'], cacheMaxAge: -1 });
    expect(cache.set).not.toHaveBeenCalled();
  });
});
