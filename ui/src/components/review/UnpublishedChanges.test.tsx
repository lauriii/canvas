import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render } from '@testing-library/react';

import UnpublishedChanges from '@/components/review/UnpublishedChanges';

import type { PendingChanges } from '@/services/pendingChangesApi';
import type { UnpublishedChange } from '@/types/Review';

const mocks = vi.hoisted(() => {
  const pendingChange = {
    owner: {
      name: 'Editor',
      avatar: null,
      uri: '/user/2',
      id: 2,
    },
    entity_type: 'canvas_page',
    entity_id: '1',
    data_hash: 'hash-1',
    langcode: 'en',
    label: 'Page 1',
    updated: 1_777_000_000,
  };

  return {
    pendingChange,
    pendingChanges: {
      'canvas_page:1:en': pendingChange,
    },
    dispatch: vi.fn(),
    publishReviewProps: undefined as any,
    publishUnwrap: vi.fn(),
    publishAllChanges: vi.fn(),
    discardChange: vi.fn(),
    refetch: vi.fn(),
    showBoundary: vi.fn(),
    invalidateBrandKitTags: vi.fn(),
    invalidateContentTags: vi.fn(),
    invalidateLayoutTags: vi.fn(),
    updateLayoutQueryData: vi.fn(),
  };
});

vi.mock('react-error-boundary', () => ({
  useErrorBoundary: () => ({
    showBoundary: mocks.showBoundary,
  }),
}));

vi.mock('react-router', () => ({
  useParams: () => ({
    entityType: 'canvas_page',
    entityId: '1',
  }),
}));

vi.mock('@/app/hooks', async () => {
  const { initialState: uiInitialState } = await vi.importActual<{
    initialState: unknown;
  }>('@/features/ui/uiSlice');

  return {
    useAppDispatch: () => mocks.dispatch,
    useAppSelector: (selector: (state: any) => unknown) =>
      selector({
        publishReview: {
          previousPendingChanges: undefined,
          conflicts: undefined,
          errors: undefined,
          autoSavesHash: {},
          clientInstanceId: 'test-client-instance',
        },
        pageData: {
          present: {
            changed: 1_777_000_000,
          },
        },
        ui: uiInitialState,
      }),
  };
});

vi.mock('@/components/review/PublishReview', () => ({
  default: (props: any) => {
    mocks.publishReviewProps = props;
    return <div data-testid="publish-review" />;
  },
}));

vi.mock('@/features/conflict/conflictUtils', () => ({
  isConflictUxEnabled: () => false,
}));

vi.mock('@/services/brandKit', () => ({
  brandKitApi: {
    util: {
      invalidateTags: mocks.invalidateBrandKitTags,
    },
  },
}));

vi.mock('@/services/componentAndLayout', () => ({
  componentAndLayoutApi: {
    util: {
      invalidateTags: mocks.invalidateLayoutTags,
      updateQueryData: mocks.updateLayoutQueryData,
    },
  },
}));

vi.mock('@/services/content', () => ({
  contentApi: {
    util: {
      invalidateTags: mocks.invalidateContentTags,
    },
  },
  useGetContentListQuery: () => ({
    data: { items: [] },
  }),
}));

vi.mock('@/services/pendingChangesApi', () => ({
  useDiscardPendingChangeMutation: () => [
    mocks.discardChange,
    { isLoading: false },
  ],
  useGetAllPendingChangesQuery: () => ({
    data: mocks.pendingChanges,
    error: undefined,
    refetch: mocks.refetch,
    isFetching: false,
  }),
  usePublishAllPendingChangesMutation: () => [
    mocks.publishAllChanges,
    { isLoading: false },
  ],
}));

vi.mock('@/services/preview', () => ({
  useQueuedPostPreviewMutation: () => [vi.fn(), { isLoading: false }],
  useUpdateComponentMutation: () => [vi.fn(), { isLoading: false }],
}));

describe('UnpublishedChanges', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.publishReviewProps = undefined;
    mocks.publishUnwrap.mockRejectedValue({ status: 409 });
    mocks.publishAllChanges.mockReturnValue({
      unwrap: mocks.publishUnwrap,
    });
  });

  it('does not run publish success cleanup when publishing fails', async () => {
    render(<UnpublishedChanges />);

    const selectedChange: UnpublishedChange = {
      ...mocks.pendingChange,
      pointer: 'canvas_page:1:en',
    };

    await act(async () => {
      await mocks.publishReviewProps.onPublishClick([selectedChange]);
    });

    expect(mocks.publishAllChanges).toHaveBeenCalledWith({
      'canvas_page:1:en': {
        ...mocks.pendingChange,
      },
    } satisfies PendingChanges);
    expect(mocks.updateLayoutQueryData).not.toHaveBeenCalled();
    expect(mocks.invalidateContentTags).not.toHaveBeenCalled();
    expect(mocks.invalidateLayoutTags).not.toHaveBeenCalled();
    expect(mocks.dispatch).not.toHaveBeenCalled();
  });
});
