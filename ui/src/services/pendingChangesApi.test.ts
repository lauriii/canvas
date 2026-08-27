import { afterEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { initialState as configurationInitialState } from '@/features/configuration/configurationSlice';
import { pageDataFormApi } from '@/services/pageDataForm';
import {
  applyConflictStateFromResponse,
  CONFLICT_CODE,
  pendingChangesApi,
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

describe('publishAllPendingChanges', () => {
  const formUrlFragment = '/canvas/api/v0/form/content-entity/';

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('refetches the page data form after publishing', async () => {
    const requestedUrls: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = input instanceof Request ? input.url : String(input);
        requestedUrls.push(url);
        if (url.includes('session/token')) {
          return new Response('csrf-token');
        }
        const body = url.includes(formUrlFragment)
          ? { html: '<form></form>' }
          : { message: 'Successfully published.' };
        return new Response(JSON.stringify(body), {
          headers: { 'Content-Type': 'application/json' },
        });
      }),
    );
    const countFormRequests = () =>
      requestedUrls.filter((url) => url.includes(formUrlFragment)).length;

    const store = makeStore({
      configuration: {
        ...configurationInitialState,
        baseUrl: 'http://localhost/',
      },
    });
    // The page data form is subscribed to for as long as the editor is open.
    const formSubscription = store.dispatch(
      pageDataFormApi.endpoints.getPageDataForm.initiate({
        entityId: '2',
        entityType: 'canvas_page',
      }),
    );
    await formSubscription;
    expect(countFormRequests()).toBe(1);

    try {
      await store.dispatch(
        pendingChangesApi.endpoints.publishAllPendingChanges.initiate({
          'canvas_page:2:en': pendingChange,
        }),
      );

      // Publishing deletes the auto-save the cached form markup was built from,
      // so that markup no longer matches the saved entity and must be refetched.
      await vi.waitFor(() => expect(countFormRequests()).toBe(2), {
        timeout: 5000,
      });
    } finally {
      formSubscription.unsubscribe();
    }
  });
});
