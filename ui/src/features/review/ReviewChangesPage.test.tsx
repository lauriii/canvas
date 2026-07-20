import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { unsetActivePanel } from '@/features/ui/primaryPanelSlice';

import ReviewChangesPage from './ReviewChangesPage';

import type * as ReactModule from 'react';
import type { PendingChanges } from '@/services/pendingChangesApi';

type PageVersionComparisonMockProps = {
  entityId: string;
  entityType: string;
  draftVersionKey?: string;
  publishedVersionTitle?: string;
  newVersionTitle?: string;
  selectedVersion?: 'published' | 'new';
  onSelectVersion?: (version: 'published' | 'new') => void;
  onNewEditClick?: () => void;
};

const mocks = vi.hoisted(() => ({
  dispatch: vi.fn(),
  discardChange: vi.fn(),
  invalidateContent: vi.fn(),
  invalidateLayout: vi.fn(),
  pendingChanges: undefined as PendingChanges | undefined,
  publishPendingChanges: vi.fn(),
  pageComparisonProps: [] as any[],
  refetch: vi.fn(),
  devConflictDetectionMode: true,
}));

vi.mock('@/app/hooks', () => ({
  useAppDispatch: () => mocks.dispatch,
}));

vi.mock('@/components/review/usePublishPendingChanges', () => ({
  usePublishPendingChanges: () => [
    mocks.publishPendingChanges,
    { isLoading: false },
  ],
}));

vi.mock('@/services/componentAndLayout', () => ({
  componentAndLayoutApi: {
    util: {
      invalidateTags: mocks.invalidateLayout,
    },
  },
}));

vi.mock('@/services/content', () => ({
  contentApi: {
    util: {
      invalidateTags: mocks.invalidateContent,
    },
  },
}));

vi.mock('@/features/versionComparison/PageVersionComparison', async () => {
  const React = await vi.importActual<typeof ReactModule>('react');

  return {
    PageVersionComparison: (props: PageVersionComparisonMockProps) => {
      const [mountedEntity] = React.useState(
        () => `${props.entityType}:${props.entityId}`,
      );
      const [mountedDraftVersionKey] = React.useState(
        () => props.draftVersionKey,
      );
      mocks.pageComparisonProps.push(props);
      return (
        <div data-testid="page-version-comparison">
          {props.publishedVersionTitle} / {props.newVersionTitle} /{' '}
          {mountedEntity} / {mountedDraftVersionKey}
          <button
            type="button"
            aria-pressed={props.selectedVersion === 'published'}
            onClick={() => props.onSelectVersion?.('published')}
          >
            Select old version
          </button>
          <button
            type="button"
            aria-pressed={props.selectedVersion === 'new'}
            onClick={() => props.onSelectVersion?.('new')}
          >
            Select new version
          </button>
          {props.onNewEditClick && (
            <button type="button" onClick={props.onNewEditClick}>
              Edit
            </button>
          )}
        </div>
      );
    },
  };
});

vi.mock('@/features/conflict/conflictUtils', () => ({
  isConflictUxEnabled: () => mocks.devConflictDetectionMode,
}));

vi.mock('@/services/pendingChangesApi', () => ({
  useGetAllPendingChangesQuery: () => ({
    data: mocks.pendingChanges,
    isFetching: false,
    refetch: mocks.refetch,
  }),
  useDiscardPendingChangeMutation: () => [
    (change: unknown) => ({
      unwrap: () => mocks.discardChange(change),
    }),
    { isLoading: false },
  ],
}));

const pageChange = {
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
  label: 'Homepage',
  updated: 1_777_000_000,
};

const secondPageChange = {
  ...pageChange,
  entity_id: '2',
  data_hash: 'hash-2',
  label: 'About',
  updated: 1_777_000_100,
};

const codeComponentChange = {
  ...pageChange,
  entity_type: 'js_component',
  entity_id: 'hero',
  data_hash: 'hash-hero',
  label: 'Hero',
};

const defaultReviewState = {
  selectedPointers: ['canvas_page:1:en', 'js_component:hero:en'],
  reviewPointers: ['canvas_page:1:en'],
};

const ReviewPageTestApp = ({
  initialPath = '/review/canvas_page/1',
  state = defaultReviewState,
}: {
  initialPath?: string;
  state?: unknown;
}) => (
  <Theme accentColor="blue" hasBackground={false}>
    <MemoryRouter
      initialEntries={[{ pathname: initialPath, state }]}
      future={{ v7_relativeSplatPath: true, v7_startTransition: true }}
    >
      <Routes>
        <Route path="/review" element={<ReviewChangesPage />} />
        <Route
          path="/review/:entityType/:entityId"
          element={<ReviewChangesPage />}
        />
        <Route path="/editor" element={<div>Editor</div>} />
        <Route
          path="/editor/:entityType/:entityId"
          element={<div>Editor entity</div>}
        />
      </Routes>
    </MemoryRouter>
  </Theme>
);

const renderReviewPage = ({
  initialPath = '/review/canvas_page/1',
  state = defaultReviewState,
}: {
  initialPath?: string;
  state?: unknown;
} = {}) =>
  render(<ReviewPageTestApp initialPath={initialPath} state={state} />);

describe('ReviewChangesPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.pendingChanges = {
      'canvas_page:1:en': pageChange,
      'canvas_page:2:en': secondPageChange,
      'js_component:hero:en': codeComponentChange,
    };
    mocks.publishPendingChanges.mockResolvedValue(true);
    mocks.discardChange.mockResolvedValue(undefined);
    mocks.refetch.mockResolvedValue(undefined);
    mocks.pageComparisonProps = [];
    mocks.devConflictDetectionMode = true;
  });

  it('renders the selected Page change with review labels', () => {
    renderReviewPage();

    expect(screen.getByText('Review')).toBeInTheDocument();
    expect(screen.getByText('Homepage')).toBeInTheDocument();
    expect(screen.getByTestId('page-version-comparison')).toHaveTextContent(
      'Old version / New version / canvas_page:1',
    );
    expect(mocks.pageComparisonProps[0]).toEqual(
      expect.objectContaining({
        entityId: '1',
        entityType: 'canvas_page',
        publishedVersionTitle: 'Old version',
        newVersionTitle: 'New version',
        selectedVersion: 'new',
      }),
    );
    expect(
      screen.getByRole('button', { name: 'Select new version' }),
    ).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
  });

  it('closes any open primary panel when the review page loads', () => {
    renderReviewPage();

    expect(mocks.dispatch).toHaveBeenCalledWith(unsetActivePanel());
  });

  it('publishes selected changes when the new version is applied', async () => {
    const user = userEvent.setup();
    renderReviewPage();

    await user.click(
      screen.getByRole('button', { name: 'Publish selected changes' }),
    );

    await waitFor(() => {
      expect(mocks.publishPendingChanges).toHaveBeenCalledWith([
        expect.objectContaining({
          pointer: 'canvas_page:1:en',
          entity_type: 'canvas_page',
        }),
        expect.objectContaining({
          pointer: 'js_component:hero:en',
          entity_type: 'js_component',
        }),
      ]);
    });
    expect(await screen.findByText('Editor')).toBeInTheDocument();
  });

  it('remounts the comparison when moving between reviewed Pages', async () => {
    const user = userEvent.setup();
    renderReviewPage({
      initialPath: '/review/canvas_page/2',
      state: {
        selectedPointers: ['canvas_page:2:en', 'canvas_page:1:en'],
        reviewPointers: ['canvas_page:2:en', 'canvas_page:1:en'],
      },
    });

    expect(screen.getByText('About')).toBeInTheDocument();
    expect(screen.getByTestId('page-version-comparison')).toHaveTextContent(
      'canvas_page:2',
    );

    await user.click(screen.getByRole('button', { name: /Next/ }));

    expect(screen.getByText('Homepage')).toBeInTheDocument();
    expect(screen.getByText('Review 2 of 2')).toBeInTheDocument();
    expect(screen.getByTestId('page-version-comparison')).toHaveTextContent(
      'canvas_page:1',
    );
  });

  it('keeps navigation back to the final reviewed Page from the complete state', async () => {
    const user = userEvent.setup();
    renderReviewPage({
      initialPath: '/review',
      state: {
        selectedPointers: ['canvas_page:1:en', 'canvas_page:2:en'],
        reviewPointers: ['canvas_page:1:en', 'canvas_page:2:en'],
        reviewComplete: true,
      },
    });

    expect(screen.getByTestId('review-complete-state')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Previous' }));

    expect(screen.getByText('About')).toBeInTheDocument();
    expect(screen.getByText('Review 2 of 2')).toBeInTheDocument();
    expect(screen.getByTestId('page-version-comparison')).toHaveTextContent(
      'canvas_page:2',
    );
  });

  it('uses the breadcrumb links for review navigation', async () => {
    const user = userEvent.setup();
    renderReviewPage({
      initialPath: '/review/canvas_page/2',
      state: {
        selectedPointers: ['canvas_page:1:en', 'canvas_page:2:en'],
        reviewPointers: ['canvas_page:1:en', 'canvas_page:2:en'],
      },
    });

    await user.click(screen.getByRole('button', { name: 'Review' }));

    expect(await screen.findByText('Homepage')).toBeInTheDocument();
    expect(screen.getByText('Review 1 of 2')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Canvas' }));

    expect(await screen.findByText('Editor')).toBeInTheDocument();
  });

  it('keeps the opened review snapshot until the page is reopened', () => {
    const { rerender, unmount } = renderReviewPage();

    expect(screen.getByTestId('page-version-comparison')).toHaveTextContent(
      'hash-1:1777000000',
    );

    mocks.pendingChanges = {
      'canvas_page:1:en': {
        ...pageChange,
        data_hash: 'hash-1-new',
        updated: 1_777_000_200,
      },
      'canvas_page:2:en': secondPageChange,
      'js_component:hero:en': codeComponentChange,
    };

    rerender(
      <ReviewPageTestApp
        initialPath="/review/canvas_page/1"
        state={defaultReviewState}
      />,
    );

    expect(screen.getByTestId('page-version-comparison')).toHaveTextContent(
      'hash-1:1777000000',
    );

    unmount();
    renderReviewPage();

    expect(screen.getByTestId('page-version-comparison')).toHaveTextContent(
      'hash-1-new:1777000200',
    );
  });

  it('discards the current Page change when the old version is applied', async () => {
    const user = userEvent.setup();
    renderReviewPage();

    await user.click(
      screen.getByRole('button', { name: 'Select old version' }),
    );

    expect(screen.getByRole('switch', { name: /Selected/ })).toHaveAttribute(
      'aria-checked',
      'false',
    );

    await user.click(screen.getByRole('button', { name: 'Discard changes' }));

    await waitFor(() => {
      expect(mocks.discardChange).toHaveBeenCalledWith({
        ...pageChange,
        pointer: 'canvas_page:1:en',
      });
    });
    expect(mocks.invalidateLayout).toHaveBeenCalledWith([{ type: 'Layout' }]);
    expect(mocks.invalidateContent).toHaveBeenCalledWith([
      { type: 'Content', id: 'LIST' },
    ]);
    expect(mocks.dispatch).toHaveBeenCalledTimes(3);
    expect(mocks.refetch).toHaveBeenCalled();
    expect(screen.getByTestId('review-complete-state')).toBeInTheDocument();
  });

  it('falls back to all non-conflicted Page changes on direct review visits', () => {
    renderReviewPage({
      initialPath: '/review',
      state: null,
    });

    expect(screen.getByText('About')).toBeInTheDocument();
    expect(screen.getByTestId('page-version-comparison')).toHaveTextContent(
      'canvas_page:2',
    );
  });

  it('redirects to the editor when conflict detection mode is disabled', async () => {
    mocks.devConflictDetectionMode = false;

    renderReviewPage();

    expect(await screen.findByText('Editor')).toBeInTheDocument();
    expect(
      screen.queryByTestId('page-version-comparison'),
    ).not.toBeInTheDocument();
  });
});
