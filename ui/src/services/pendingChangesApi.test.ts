import { describe, expect, it } from 'vitest';

import {
  applyConflictStateFromResponse,
  CONFLICT_CODE,
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
