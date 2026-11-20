import { describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { setConfiguration } from '@/features/configuration/configurationSlice';
import { commentsApi } from '@/services/comments';

describe('commentsApi', () => {
  it('sends no auto-save fields, so comments stay out of the 409 protocol', async () => {
    // Comments use the plain `baseQuery`, never `baseQueryWithAutoSaves`. This
    // asserts the resulting wire format: no `autoSaves`, no `clientInstanceId`.
    // @see ui/src/services/comments.ts
    const requests: { url: string; body: unknown }[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const request =
          input instanceof Request ? input : new Request(input, init);
        if (request.url.endsWith('session/token')) {
          return new Response('csrf-token', { status: 200 });
        }
        requests.push({
          url: request.url,
          body: await request.clone().json(),
        });
        return Response.json({ thread: {} }, { status: 201 });
      }),
    );

    const store = makeStore();
    store.dispatch(
      setConfiguration({
        // Node's `Request` rejects relative URLs, so the test surface needs an
        // absolute base URL.
        baseUrl: 'http://localhost/',
        // Left unset on purpose: the surface comes from the route.
        entityType: 'none',
        entity: 'none',
        isNew: false,
        isPublished: false,
        devMode: false,
      }),
    );
    await store.dispatch(
      commentsApi.endpoints.createThread.initiate({
        surfaceType: 'canvas_page',
        surfaceId: '1',
        componentUuid: null,
        body: 'Hello',
      }),
    );

    expect(requests).toHaveLength(1);
    expect(requests[0].url).toContain('/canvas/api/v0/comments');
    expect(requests[0].body).toEqual({
      surfaceType: 'canvas_page',
      surfaceId: '1',
      componentUuid: null,
      body: 'Hello',
    });
  });
});
