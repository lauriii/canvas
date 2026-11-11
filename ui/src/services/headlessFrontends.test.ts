import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { configureStore } from '@reduxjs/toolkit';

import { headlessFrontendsApi } from '@/services/headlessFrontends';

import type {
  CacheInvalidationStatus,
  StoredFrontend,
} from '@/services/headlessFrontends';

const OK_STATUS: CacheInvalidationStatus = {
  state: 'ok',
  lastSuccess: 1_777_000_000,
  lastFailure: null,
  lastError: null,
};

/** The frontends the fake server currently stores. */
let serverFrontends: StoredFrontend[] = [];

/** The method, path, and JSON body of every write the fake server received. */
let writes: { method: string; path: string; body: unknown }[] = [];

/** Whether the fake server refuses to return the list. */
let failReads = false;

/** Settles the save the test is holding open. */
let releaseSave: (status: number) => void = () => {};

const jsonResponse = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

beforeEach(() => {
  writes = [];
  failReads = false;
  vi.stubGlobal(
    'fetch',
    async (input: RequestInfo | URL, init?: RequestInit) => {
      const request = new Request(input, init);
      const { method } = request;
      const path = new URL(request.url).pathname;

      if (path.endsWith('session/token')) {
        return new Response('test-csrf-token', { status: 200 });
      }
      if (method === 'GET') {
        return failReads
          ? jsonResponse({}, 500)
          : jsonResponse({ frontends: serverFrontends });
      }

      const body = await request.clone().json();
      writes.push({ method, path, body });
      if (method === 'POST') {
        return jsonResponse({
          ok: true,
          announced: true,
          read: true,
          message:
            'The frontend received the announcement and read the cache tags.',
        });
      }
      // The save is held open so a test can assert the optimistic state while
      // it is in flight, then decide how it settles.
      return new Promise<Response>((resolve) => {
        releaseSave = (status) => {
          if (status >= 400) {
            resolve(jsonResponse({ error: 'Rejected' }, status));
            return;
          }
          serverFrontends = body.frontends.map(
            (frontend: Omit<StoredFrontend, 'cacheInvalidationStatus'>) => ({
              ...frontend,
              cacheInvalidationStatus: null,
            }),
          );
          resolve(jsonResponse({ frontends: serverFrontends }, status));
        };
      });
    },
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
});

/** Lets pending microtasks and timers run. */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const setup = async (frontends: StoredFrontend[]) => {
  serverFrontends = frontends;
  const store = configureStore({
    reducer: {
      [headlessFrontendsApi.reducerPath]: headlessFrontendsApi.reducer,
      configuration: () => ({ baseUrl: 'http://localhost/' }),
    },
    middleware: (getDefaultMiddleware) =>
      getDefaultMiddleware().concat(headlessFrontendsApi.middleware),
  });
  // Keeps a subscription, so the list is refetched when it is invalidated.
  await store.dispatch(headlessFrontendsApi.endpoints.getFrontends.initiate());
  const read = () =>
    headlessFrontendsApi.endpoints.getFrontends.select()(store.getState()).data;
  return { store, read };
};

describe('headlessFrontendsApi', () => {
  it('keeps the status of each frontend while a reordered list is being saved', async () => {
    const { store, read } = await setup([
      {
        url: 'https://one.example.com',
        components: ['card'],
        cacheInvalidationStatus: OK_STATUS,
      },
      {
        url: 'https://two.example.com',
        components: [],
        cacheInvalidationStatus: null,
      },
    ]);

    const frontends = [
      {
        url: 'https://two.example.com',
        components: [],
      },
      {
        url: 'https://one.example.com',
        components: ['card'],
      },
    ];
    void store.dispatch(
      headlessFrontendsApi.endpoints.setFrontends.initiate(frontends),
    );
    await flush();

    expect(writes).toEqual([
      {
        method: 'PATCH',
        path: '/canvas/api/v0/headless/frontends',
        body: { frontends },
      },
    ]);
    // While the save is in flight the list already shows the new order, and
    // a frontend keeps the status it had.
    expect(read()).toEqual([
      { ...frontends[0], cacheInvalidationStatus: null },
      { ...frontends[1], cacheInvalidationStatus: OK_STATUS },
    ]);

    releaseSave(200);
    await flush();
    expect(read()).toEqual(serverFrontends);
  });

  it('rolls the list back when the save fails', async () => {
    const stored = [
      {
        url: 'https://one.example.com',
        components: [],
        cacheInvalidationStatus: OK_STATUS,
      },
    ];
    const { store, read } = await setup(stored);

    void store.dispatch(
      headlessFrontendsApi.endpoints.setFrontends.initiate([
        { url: 'https://two.example.com', components: [] },
      ]),
    );
    await flush();
    expect(read()?.[0].url).toBe('https://two.example.com');

    // Only undoing the optimistic update can restore the list: the refetch
    // after the failed save fails too.
    failReads = true;
    releaseSave(422);
    await flush();
    expect(read()).toEqual(stored);
  });

  it('retries cache invalidation for one frontend and reloads the list', async () => {
    const { store, read } = await setup([
      {
        url: 'https://one.example.com',
        components: [],
        cacheInvalidationStatus: {
          ...OK_STATUS,
          state: 'failing',
          lastError: 'HTTP 500',
        },
      },
    ]);
    // The test changes the status Drupal reports.
    serverFrontends = [
      { ...serverFrontends[0], cacheInvalidationStatus: OK_STATUS },
    ];

    const result = await store
      .dispatch(
        headlessFrontendsApi.endpoints.testCacheInvalidation.initiate({
          url: 'https://one.example.com',
          expireAll: false,
        }),
      )
      .unwrap();
    await flush();

    expect(writes).toEqual([
      {
        method: 'POST',
        path: '/canvas/api/v0/headless/frontends/cache-invalidation/test',
        body: { url: 'https://one.example.com', expireAll: false },
      },
    ]);
    expect(result).toEqual({
      ok: true,
      announced: true,
      read: true,
      message:
        'The frontend received the announcement and read the cache tags.',
    });
    expect(read()?.[0].cacheInvalidationStatus).toEqual(OK_STATUS);
  });
});
