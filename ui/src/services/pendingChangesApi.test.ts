import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { configureStore } from '@reduxjs/toolkit';

import {
  applyConflictStateFromResponse,
  CONFLICT_CODE,
  pendingChangesApi,
  syncPendingChangesFromAutoSaves,
} from '@/services/pendingChangesApi';
import { getDrupalSettings } from '@/utils/drupal-globals';

import type { Middleware, UnknownAction } from '@reduxjs/toolkit';
import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';
import type { AutoSavesHash } from '@/types/AutoSaves';

const pendingChange: PendingChange = {
  owner: {
    name: 'Editor',
    avatar: null,
    uri: '/user/2',
    id: 2,
  },
  entity_type: 'canvas_page',
  entity_id: '2',
  data_hash: 'hash-2',
  langcode: 'en',
  label: 'Page 2',
  updated: 1_777_000_000,
};

describe('applyConflictStateFromResponse', () => {
  it('returns pending changes from the normal flat 200 response shape', () => {
    expect(
      applyConflictStateFromResponse({
        'canvas_page:2:en': pendingChange,
      }),
    ).toEqual({
      'canvas_page:2:en': {
        ...pendingChange,
        hasConflict: false,
        conflict_id: undefined,
      },
    });
  });

  it('marks code 4 errors as resolvable conflicts and preserves conflict_id', () => {
    expect(
      applyConflictStateFromResponse({
        data: {
          'canvas_page:2:en': pendingChange,
        },
        errors: [
          {
            code: CONFLICT_CODE.DETECTED,
            detail: 'Conflict detected.',
            source: {
              pointer: 'canvas_page:2:en',
            },
            meta: {
              conflict_id: '17',
            },
          },
        ],
      }),
    ).toEqual({
      'canvas_page:2:en': {
        ...pendingChange,
        hasConflict: true,
        conflict_id: '17',
      },
    });
  });

  it('matches conflicts by api_auto_save_key when provided', () => {
    expect(
      applyConflictStateFromResponse({
        data: {
          'canvas_page:2:en': pendingChange,
        },
        errors: [
          {
            code: CONFLICT_CODE.DETECTED,
            detail: 'Conflict detected.',
            source: {
              pointer: '/data/attributes/foo',
            },
            meta: {
              api_auto_save_key: 'canvas_page:2:en',
              conflict_id: '18',
            },
          },
        ],
      })['canvas_page:2:en'],
    ).toMatchObject({
      hasConflict: true,
      conflict_id: '18',
    });
  });

  it('ignores non-resolvable conflict codes', () => {
    expect(
      applyConflictStateFromResponse({
        data: {
          'canvas_page:2:en': pendingChange,
        },
        errors: [
          {
            code: CONFLICT_CODE.UNEXPECTED,
            detail: 'Unexpected item.',
            source: {
              pointer: 'canvas_page:2:en',
            },
          },
        ],
      })['canvas_page:2:en'],
    ).toMatchObject({
      hasConflict: false,
      conflict_id: undefined,
    });
  });
});

describe('syncPendingChangesFromAutoSaves', () => {
  const PENDING_KEY = 'canvas_page:2:en';
  const NOW = 1_800_000_000;

  /** The pending changes the fake server returns. */
  let serverPending: PendingChanges = {};
  /** When set, the pending changes GET waits on this before responding. */
  let heldGet: Promise<void> | null = null;
  let getCount = 0;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(NOW * 1000);
    Object.assign(getDrupalSettings(), {
      user: { uid: pendingChange.owner.id },
    });
    serverPending = { [PENDING_KEY]: pendingChange };
    heldGet = null;
    getCount = 0;
    vi.stubGlobal('fetch', async () => {
      getCount++;
      // Read the server state when the request arrives, like the server does.
      const body = JSON.stringify(serverPending);
      if (heldGet) {
        await heldGet;
      }
      return new Response(body, {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    Object.assign(getDrupalSettings(), { user: undefined });
  });

  /** Lets pending microtasks and timers run. */
  const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

  const isInvalidation = (action: UnknownAction) =>
    pendingChangesApi.util.invalidateTags.match(action);

  /**
   * Creates a store that records dispatched actions.
   *
   * @param loadList
   *   Whether to load the pending changes list, as the UI does on start.
   */
  const setUp = async (loadList = true) => {
    const actions: UnknownAction[] = [];
    const recorder: Middleware = () => (next) => (action) => {
      actions.push(action as UnknownAction);
      return next(action);
    };
    const store = configureStore({
      reducer: {
        [pendingChangesApi.reducerPath]: pendingChangesApi.reducer,
        configuration: () => ({ baseUrl: 'http://localhost/' }),
      },
      middleware: (getDefault) =>
        getDefault().concat(pendingChangesApi.middleware, recorder),
    });
    if (loadList) {
      // Keep a subscription, like the 10-second poll in the UI does.
      await store.dispatch(
        pendingChangesApi.endpoints.getAllPendingChanges.initiate(),
      );
    }
    actions.length = 0;
    const pending = () =>
      pendingChangesApi.endpoints.getAllPendingChanges.select()(
        store.getState(),
      ).data;
    return { store, actions, pending };
  };

  it('updates a listed auto-save in place instead of refetching', async () => {
    const { store, actions, pending } = await setUp();
    store.dispatch(
      syncPendingChangesFromAutoSaves({
        [PENDING_KEY]: { autoSaveStartingPoint: '1', hash: 'hash-new' },
        // A page region without an auto-save is not listed, as expected.
        'page_region:header': { autoSaveStartingPoint: '1', hash: '' },
      }),
    );
    expect(actions.some(isInvalidation)).toBe(false);
    expect(pending()?.[PENDING_KEY]).toMatchObject({
      data_hash: 'hash-new',
      updated: NOW,
    });
    expect(getCount).toBe(1);
  });

  it('matches the owner when the server sends its id as a string', async () => {
    serverPending = {
      [PENDING_KEY]: {
        ...pendingChange,
        owner: { ...pendingChange.owner, id: '2' as unknown as number },
      },
    };
    const { store, actions } = await setUp();
    store.dispatch(
      syncPendingChangesFromAutoSaves({
        [PENDING_KEY]: { autoSaveStartingPoint: '1', hash: 'hash-new' },
      }),
    );
    expect(actions.some(isInvalidation)).toBe(false);
  });

  it('leaves entries with an unchanged hash as they are', async () => {
    const region = {
      ...pendingChange,
      entity_type: 'page_region',
      entity_id: 'header',
      data_hash: 'hash-region',
    };
    serverPending = { ...serverPending, 'page_region:header': region };
    const { store, actions, pending } = await setUp();
    store.dispatch(
      syncPendingChangesFromAutoSaves({
        [PENDING_KEY]: { autoSaveStartingPoint: '1', hash: 'hash-new' },
        'page_region:header': {
          autoSaveStartingPoint: '1',
          hash: 'hash-region',
        },
      }),
    );
    expect(actions.some(isInvalidation)).toBe(false);
    expect(pending()?.['page_region:header'].updated).toBe(
      pendingChange.updated,
    );
    expect(pending()?.[PENDING_KEY].updated).toBe(NOW);
  });

  it.each([
    [
      'an entity gains its first auto-save',
      { 'canvas_page:3:en': { autoSaveStartingPoint: '1', hash: 'hash-3' } },
    ],
    [
      'an entity loses its auto-save',
      { [PENDING_KEY]: { autoSaveStartingPoint: '1', hash: '' } },
    ],
  ])('invalidates when %s', async (_, autoSaves: AutoSavesHash) => {
    const { store, actions } = await setUp();
    store.dispatch(syncPendingChangesFromAutoSaves(autoSaves));
    expect(actions.some(isInvalidation)).toBe(true);
    await flush();
    expect(getCount).toBe(2);
  });

  it('invalidates when the auto-save belongs to another user', async () => {
    Object.assign(getDrupalSettings(), { user: { uid: 3 } });
    const { store, actions } = await setUp();
    store.dispatch(
      syncPendingChangesFromAutoSaves({
        [PENDING_KEY]: { autoSaveStartingPoint: '1', hash: 'hash-new' },
      }),
    );
    expect(actions.some(isInvalidation)).toBe(true);
  });

  it('invalidates when there is no cached list', async () => {
    const { store, actions, pending } = await setUp(false);
    store.dispatch(
      syncPendingChangesFromAutoSaves({
        [PENDING_KEY]: { autoSaveStartingPoint: '1', hash: 'hash-new' },
      }),
    );
    expect(actions.some(isInvalidation)).toBe(true);
    expect(pending()).toBeUndefined();
  });

  it('does nothing when the response has no auto-saves', async () => {
    const { store, actions, pending } = await setUp();
    store.dispatch(syncPendingChangesFromAutoSaves(undefined));
    expect(actions).toEqual([]);
    expect(pending()?.[PENDING_KEY]).toEqual({
      ...pendingChange,
      hasConflict: false,
    });
  });

  it('fetches again after an in-flight GET instead of keeping its stale hash', async () => {
    const { store, actions, pending } = await setUp();
    // A poll starts before the PATCH is saved on the server.
    let releaseGet: () => void = () => {};
    heldGet = new Promise((resolve) => {
      releaseGet = resolve;
    });
    const poll = store.dispatch(
      pendingChangesApi.endpoints.getAllPendingChanges.initiate(undefined, {
        forceRefetch: true,
      }),
    );
    await flush();
    // The PATCH is saved and resolves while the poll is in flight.
    serverPending = {
      [PENDING_KEY]: { ...pendingChange, data_hash: 'hash-new' },
    };
    store.dispatch(
      syncPendingChangesFromAutoSaves({
        [PENDING_KEY]: { autoSaveStartingPoint: '1', hash: 'hash-new' },
      }),
    );
    expect(actions.some(isInvalidation)).toBe(true);
    heldGet = null;
    releaseGet();
    await poll;
    await flush();
    // The poll returned the old hash; the delayed invalidation refetched.
    expect(getCount).toBe(3);
    expect(pending()?.[PENDING_KEY].data_hash).toBe('hash-new');
  });
});
