import { describe, expect, it, vi } from 'vitest';

import { createCacheInvalidationHandler } from './cache-invalidation';

const announce = (body: unknown) =>
  new Request('https://app.example/api/canvas/revalidate', {
    method: 'POST',
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });

const handler = (drupal: () => Promise<Response>) => {
  const invalidate = vi.fn();
  const fetchImpl = vi.fn(drupal);
  return {
    invalidate,
    fetchImpl,
    handle: createCacheInvalidationHandler({
      invalidate,
      config: { baseUrl: 'https://drupal.example/cms' },
      fetchImpl: fetchImpl as unknown as typeof fetch,
    }),
  };
};

describe('createCacheInvalidationHandler', () => {
  it('invalidates the tags Drupal stored under the announced ID', async () => {
    const { handle, invalidate, fetchImpl } = handler(async () =>
      Response.json({ tags: ['node:1', 'node_list'] }),
    );

    const response = await handle(announce({ id: 'abc_-1' }));

    expect(response.status).toBe(204);
    expect(fetchImpl).toHaveBeenCalledWith(
      'https://drupal.example/cms/canvas-headless/cache-invalidation',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ id: 'abc_-1' }),
        cache: 'no-store',
      }),
    );
    expect(invalidate).toHaveBeenCalledWith(['node:1', 'node_list']);
  });

  it.each([
    ['a malformed body', '{', async () => Response.json({ tags: [] }), 400],
    [
      'an ID that is not a path segment',
      { id: '../x' },
      async () => Response.json({ tags: [] }),
      400,
    ],
    [
      'an ID Drupal does not know',
      { id: 'abc' },
      async () => new Response(null, { status: 404 }),
      404,
    ],
    [
      'an unreachable Drupal',
      { id: 'abc' },
      () => Promise.reject(new TypeError('fetch failed')),
      502,
    ],
    [
      'malformed tags',
      { id: 'abc' },
      async () => Response.json({ tags: [1] }),
      502,
    ],
  ])('refuses %s', async (_label, body, drupal, status) => {
    const { handle, invalidate } = handler(drupal);

    const response = await handle(announce(body));

    expect(response.status).toBe(status);
    expect(invalidate).not.toHaveBeenCalled();
  });

  it('answers before reading the tags when deferring', async () => {
    const tasks: Array<() => Promise<void>> = [];
    const invalidate = vi.fn();
    const fetchImpl = vi.fn(async () => Response.json({ tags: ['node:1'] }));
    const handle = createCacheInvalidationHandler({
      invalidate,
      config: { baseUrl: 'https://drupal.example' },
      fetchImpl: fetchImpl as unknown as typeof fetch,
      defer: (task) => tasks.push(task),
    });

    expect((await handle(announce({ id: 'abc' }))).status).toBe(202);
    expect((await handle(announce({ id: '../x' }))).status).toBe(400);
    expect(fetchImpl).not.toHaveBeenCalled();
    expect(tasks).toHaveLength(1);
    await tasks[0]();
    expect(invalidate).toHaveBeenCalledWith(['node:1']);

    // A deferred failure is logged, not thrown.
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    invalidate.mockRejectedValueOnce(new Error('The cache is down.'));
    await handle(announce({ id: 'abc' }));
    await expect(tasks[1]()).resolves.toBeUndefined();
    expect(error).toHaveBeenCalledOnce();
    error.mockRestore();
  });

  it('refuses other methods', async () => {
    const { handle } = handler(async () => Response.json({ tags: [] }));
    const response = await handle(
      new Request('https://app.example/api/canvas/revalidate'),
    );
    expect(response.status).toBe(405);
  });
});
