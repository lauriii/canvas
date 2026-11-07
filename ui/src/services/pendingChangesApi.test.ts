import { describe, expect, it } from 'vitest';
import { configureStore } from '@reduxjs/toolkit';

import {
  applyConflictStateFromResponse,
  CONFLICT_CODE,
  pendingChangesApi,
  syncPendingChangesFromAutoSaves,
} from '@/services/pendingChangesApi';

import type { PendingChange } from '@/services/pendingChangesApi';

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
  const setUp = async () => {
    const store = configureStore({
      reducer: { [pendingChangesApi.reducerPath]: pendingChangesApi.reducer },
      middleware: (getDefault) =>
        getDefault().concat(pendingChangesApi.middleware),
    });
    await store.dispatch(
      pendingChangesApi.util.upsertQueryData(
        'getAllPendingChanges',
        undefined,
        {
          'canvas_page:2:en': pendingChange,
        },
      ),
    );
    const actions: any[] = [];
    const dispatch = (action: any) => {
      actions.push(action);
      return store.dispatch(action);
    };
    const pending = () =>
      pendingChangesApi.endpoints.getAllPendingChanges.select()(
        store.getState(),
      ).data;
    return { store, actions, dispatch, pending };
  };
  const isInvalidation = (action: any) =>
    action.type === pendingChangesApi.util.invalidateTags([]).type;

  it('updates a listed auto-save in place instead of refetching', async () => {
    const { store, actions, dispatch, pending } = await setUp();
    syncPendingChangesFromAutoSaves(dispatch, store.getState, {
      'canvas_page:2:en': { autoSaveStartingPoint: '1', hash: 'hash-new' },
      // A page region without an auto-save is not listed, as expected.
      'page_region:header': { autoSaveStartingPoint: '1', hash: '' },
    });
    expect(actions.some(isInvalidation)).toBe(false);
    expect(pending()?.['canvas_page:2:en'].data_hash).toBe('hash-new');
    expect(pending()?.['canvas_page:2:en'].updated).toBeGreaterThan(
      pendingChange.updated,
    );
  });

  it('refetches when an entity gains its first auto-save', async () => {
    const { store, actions, dispatch } = await setUp();
    syncPendingChangesFromAutoSaves(dispatch, store.getState, {
      'canvas_page:3:en': { autoSaveStartingPoint: '1', hash: 'hash-3' },
    });
    expect(actions.some(isInvalidation)).toBe(true);
  });
});
