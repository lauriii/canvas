import { createApp, toWebHandler } from 'h3';
import { describe, expect, it, vi } from 'vitest';

import { createCacheInvalidationRouteHandler } from './session';

describe('createCacheInvalidationRouteHandler()', () => {
  it('reads the announced tags from Drupal and invalidates them', async () => {
    const invalidate = vi.fn();
    const fetchSpy = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(Response.json({ tags: ['node:1'] }));
    const app = createApp().use(
      createCacheInvalidationRouteHandler({
        invalidate,
        config: { baseUrl: 'https://cms.example' },
      }),
    );

    const response = await toWebHandler(app)(
      new Request('https://app.example/api/canvas/revalidate', {
        method: 'POST',
        body: JSON.stringify({ id: 'abc' }),
      }),
    );

    expect(response.status).toBe(204);
    expect(invalidate).toHaveBeenCalledExactlyOnceWith(['node:1']);
    fetchSpy.mockRestore();
  });
});
