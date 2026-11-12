import { Provider } from 'react-redux';
import { describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { usePublishPendingChanges } from '@/components/review/usePublishPendingChanges';
import { setInitialPageData } from '@/features/pageData/pageDataSlice';

import type React from 'react';
import type { UnpublishedChange } from '@/types/Review';

vi.mock('@/services/pendingChangesApi', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePublishAllPendingChangesMutation: () => [
    () => ({ unwrap: async () => ({}) }),
    {},
  ],
}));

const change = (langcode: string): UnpublishedChange => ({
  pointer: `canvas_page:1:${langcode}`,
  label: 'Page 1',
  updated: 1_777_000_000,
  entity_type: 'canvas_page',
  data_hash: 'hash-1',
  entity_id: 1,
  langcode,
  owner: { name: 'Editor', avatar: null, id: 2, uri: '/user/2' },
});

describe('usePublishPendingChanges', () => {
  it('keeps the forked translation the editor data belongs to', async () => {
    const store = makeStore();
    const owner = { entityType: 'canvas_page', entityId: '1', langcode: 'fr' };
    store.dispatch(setInitialPageData({ values: { changed: 1 }, owner }));
    const { result } = renderHook(
      () =>
        usePublishPendingChanges({
          currentEntityId: '1',
          currentEntityType: 'canvas_page',
          entityFormFields: { changed: 1 },
        }),
      {
        wrapper: ({ children }: { children: React.ReactNode }) => (
          <Provider store={store}>{children}</Provider>
        ),
      },
    );

    // Another translation's draft is not the edited page's.
    await act(() => result.current[0]([change('en')]));
    expect(store.getState().pageData.present).toEqual({ changed: 1 });

    await act(() => result.current[0]([change('fr')]));
    expect(store.getState().pageDataOwner).toEqual(owner);
    expect(store.getState().pageData.present.changed).not.toBe(1);
  });
});
