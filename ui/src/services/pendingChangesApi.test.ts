import { afterEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { initialState as configurationInitialState } from '@/features/configuration/configurationSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
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
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  // Counts content template listing requests while publishing `published`.
  const countTemplateListFetchesAfterPublishing = async (
    published: PendingChange,
  ) => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = typeof input === 'string' ? input : input.toString();
      if (url.endsWith('session/token')) {
        return new Response('token');
      }
      return new Response('{}', {
        headers: { 'Content-Type': 'application/json' },
      });
    });
    vi.stubGlobal('fetch', fetchMock);
    const countTemplateListFetches = () =>
      fetchMock.mock.calls.filter(([input]) =>
        String(input instanceof Request ? input.url : input).endsWith(
          'canvas/api/v0/config/content_template',
        ),
      ).length;

    // Requests need an absolute URL outside a browser.
    const store = makeStore({
      configuration: { ...configurationInitialState, baseUrl: 'http://test/' },
    });
    const subscription = store.dispatch(
      componentAndLayoutApi.endpoints.getContentTemplates.initiate(),
    );
    await subscription;
    const before = countTemplateListFetches();
    expect(before).toBe(1);
    await store.dispatch(
      pendingChangesApi.endpoints.publishAllPendingChanges.initiate({
        key: published,
      }),
    );
    // Let a refetch triggered by tag invalidation start.
    await new Promise((resolve) => setTimeout(resolve, 0));
    const after = countTemplateListFetches();
    subscription.unsubscribe();
    return after - before;
  };

  it('fetches the content template listing again after publishing a template', async () => {
    // The Content panel lists entities of templates that expose slots. It
    // reads the listing, which changes when a template is published.
    expect(
      await countTemplateListFetchesAfterPublishing({
        ...pendingChange,
        entity_type: 'content_template',
        entity_id: 'node.article.full',
      }),
    ).toBe(1);
  });

  it('keeps the content template listing when publishing other entities', async () => {
    expect(await countTemplateListFetchesAfterPublishing(pendingChange)).toBe(
      0,
    );
  });
});
