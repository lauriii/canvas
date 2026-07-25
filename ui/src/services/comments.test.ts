import { describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { setConfiguration } from '@/features/configuration/configurationSlice';
import {
  buildListUrl,
  buildRepliesUrl,
  buildThreadUrl,
  commentRequests,
  COMMENTS_ENDPOINT,
  commentsApi,
} from '@/services/comments';

describe('comments URL builders', () => {
  it('buildListUrl encodes the surface and the includeResolved flag', () => {
    expect(buildListUrl({ surfaceType: 'canvas_page', surfaceId: '1' })).toBe(
      `${COMMENTS_ENDPOINT}?surfaceType=canvas_page&surfaceId=1&includeResolved=0`,
    );
    expect(
      buildListUrl({
        surfaceType: 'canvas_page',
        surfaceId: '1',
        includeResolved: true,
      }),
    ).toBe(
      `${COMMENTS_ENDPOINT}?surfaceType=canvas_page&surfaceId=1&includeResolved=1`,
    );
  });

  it('buildThreadUrl and buildRepliesUrl escape the thread ID', () => {
    expect(buildThreadUrl('12')).toBe(`${COMMENTS_ENDPOINT}/12`);
    expect(buildRepliesUrl('12')).toBe(`${COMMENTS_ENDPOINT}/12/replies`);
    expect(buildThreadUrl('a/b')).toBe(`${COMMENTS_ENDPOINT}/a%2Fb`);
  });
});

describe('comments endpoint requests', () => {
  it('getComments reads the thread list of one surface', () => {
    expect(
      commentRequests.getComments({
        surfaceType: 'canvas_page',
        surfaceId: '2',
        includeResolved: true,
      }),
    ).toBe(
      `${COMMENTS_ENDPOINT}?surfaceType=canvas_page&surfaceId=2&includeResolved=1`,
    );
  });

  it('createThread POSTs the full payload to the collection URL', () => {
    expect(
      commentRequests.createThread({
        surfaceType: 'canvas_page',
        surfaceId: '1',
        componentUuid: null,
        body: 'Looks good.',
      }),
    ).toEqual({
      url: COMMENTS_ENDPOINT,
      method: 'POST',
      body: {
        surfaceType: 'canvas_page',
        surfaceId: '1',
        componentUuid: null,
        body: 'Looks good.',
      },
    });
  });

  it('replyToThread POSTs only the body to the replies URL', () => {
    expect(
      commentRequests.replyToThread({ threadId: '7', body: 'Agreed.' }),
    ).toEqual({
      url: `${COMMENTS_ENDPOINT}/7/replies`,
      method: 'POST',
      body: { body: 'Agreed.' },
    });
  });

  it('setThreadResolved PATCHes only the resolved flag to the thread URL', () => {
    expect(
      commentRequests.setThreadResolved({ threadId: '7', resolved: true }),
    ).toEqual({
      url: `${COMMENTS_ENDPOINT}/7`,
      method: 'PATCH',
      body: { resolved: true },
    });
    // `listArgs` only drives the optimistic update; it is never sent.
    expect(
      commentRequests.setThreadResolved({
        threadId: '7',
        resolved: false,
        listArgs: { surfaceType: 'canvas_page', surfaceId: '1' },
      }),
    ).toEqual({
      url: `${COMMENTS_ENDPOINT}/7`,
      method: 'PATCH',
      body: { resolved: false },
    });
  });
});

describe('commentsApi', () => {
  it('is registered under its own reducer path', () => {
    expect(commentsApi.reducerPath).toBe('commentsApi');
  });

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
        return new Response(JSON.stringify({ thread: {} }), {
          status: 201,
          headers: { 'Content-Type': 'application/json' },
        });
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
    expect(requests[0].url).toContain(COMMENTS_ENDPOINT);
    expect(requests[0].body).toEqual({
      surfaceType: 'canvas_page',
      surfaceId: '1',
      componentUuid: null,
      body: 'Hello',
    });
  });
});
