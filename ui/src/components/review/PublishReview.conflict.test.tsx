import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PublishReview from '@/components/review/PublishReview';

import type React from 'react';
import type { UnpublishedChange } from '@/types/Review';

let conflictUxEnabled = true;

vi.mock('@/features/conflict/conflictUtils', () => ({
  isConflictUxEnabled: () => conflictUxEnabled,
}));

vi.mock('@/components/PermissionCheck', () => ({
  default: ({ children }: any) => <>{children}</>,
}));

const baseChange: UnpublishedChange = {
  pointer: 'canvas_page:1:en',
  label: 'Page 1',
  updated: 1_777_000_000,
  entity_type: 'canvas_page',
  data_hash: 'hash-1',
  entity_id: 1,
  langcode: 'en',
  owner: {
    name: 'Editor',
    avatar: null,
    id: 2,
    uri: '/user/2',
  },
};

const renderReview = (
  changes: UnpublishedChange[],
  overrides: Partial<React.ComponentProps<typeof PublishReview>> = {},
) => {
  const store = makeStore();
  const props = {
    changes,
    conflictCount: changes.filter((change) => change.hasConflict).length,
    errors: undefined,
    onOpenChangeCallback: vi.fn(),
    onPublishClick: vi.fn(),
    onDiscardClick: vi.fn(),
    onResolveConflict: vi.fn(),
    onReviewSelectedChanges: vi.fn(),
    isViewChangeAvailable: (change: UnpublishedChange) =>
      change.entity_type === 'canvas_page' && !change.hasConflict,
    isPublishing: false,
    isDiscarding: false,
    isUpdating: false,
    ...overrides,
  };

  const result = render(
    <AppWrapper store={store} location="/" path="*">
      <PublishReview {...props} />
    </AppWrapper>,
  );

  return { ...result, props };
};

describe('PublishReview conflict UI', () => {
  beforeEach(() => {
    conflictUxEnabled = true;
  });

  it('marks conflicted rows and counts them in the banner', async () => {
    const user = userEvent.setup();
    renderReview([
      baseChange,
      {
        ...baseChange,
        pointer: 'canvas_page:2:en',
        label: 'Page 2',
        entity_id: 2,
        hasConflict: true,
      },
    ]);

    await user.click(screen.getByTestId('canvas-publish-review'));

    expect(screen.getByTestId('conflict-banner')).toHaveTextContent(
      '1 conflict to resolve',
    );
    expect(screen.getByTestId('change-conflict-icon')).toBeInTheDocument();
    expect(screen.getByText('2 unpublished changes')).toBeInTheDocument();
  });

  it('removes selected changes that disappear from the pending list', async () => {
    const user = userEvent.setup();
    const secondChange = {
      ...baseChange,
      pointer: 'canvas_page:2:en',
      label: 'Page 2',
      entity_id: 2,
    };
    const { props, rerender } = renderReview([baseChange, secondChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(screen.getByTestId('canvas-publish-review-select-all'));

    expect(screen.getByText('2 of 2 changes selected')).toBeInTheDocument();

    const store = makeStore();
    rerender(
      <AppWrapper store={store} location="/" path="*">
        <PublishReview {...props} changes={[baseChange]} conflictCount={0} />
      </AppWrapper>,
    );

    expect(screen.getByText('1 of 1 changes selected')).toBeInTheDocument();

    await user.click(
      screen.getByRole('button', { name: 'Publish 1 selected' }),
    );

    expect(props.onPublishClick).toHaveBeenCalledWith([baseChange]);
  });

  it('clears selected rows when the review is closed without publishing', async () => {
    const user = userEvent.setup();
    const { props } = renderReview([baseChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(screen.getByLabelText('Select change Page 1'));
    expect(screen.getByText('1 of 1 changes selected')).toBeInTheDocument();

    await user.click(screen.getByLabelText('Close'));
    await waitFor(() => {
      expect(
        screen.queryByTestId('canvas-publish-reviews-content'),
      ).not.toBeInTheDocument();
    });

    expect(props.onPublishClick).not.toHaveBeenCalled();

    await user.click(screen.getByTestId('canvas-publish-review'));
    expect(screen.getByText('0 of 1 changes selected')).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'No items selected' }),
    ).toBeDisabled();
  });

  it('publishes the latest pending change when a selected row updates', async () => {
    const user = userEvent.setup();
    const { props, rerender } = renderReview([baseChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(screen.getByLabelText('Select change Page 1'));
    expect(screen.getByText('1 of 1 changes selected')).toBeInTheDocument();

    const updatedChange = {
      ...baseChange,
      data_hash: 'hash-2',
      updated: 1_777_000_001,
    };
    const store = makeStore();
    rerender(
      <AppWrapper store={store} location="/" path="*">
        <PublishReview {...props} changes={[updatedChange]} />
      </AppWrapper>,
    );

    await user.click(
      screen.getByRole('button', { name: 'Publish 1 selected' }),
    );

    expect(props.onPublishClick).toHaveBeenCalledWith([updatedChange]);
  });

  it('closes the review and resolves the first conflicted row from the banner', async () => {
    const user = userEvent.setup();
    const conflictedChange = {
      ...baseChange,
      pointer: 'canvas_page:2:en',
      label: 'Page 2',
      entity_id: 2,
      hasConflict: true,
    };
    const { props } = renderReview([baseChange, conflictedChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(
      screen.getByRole('button', { name: 'Resolve 1 conflict' }),
    );

    expect(props.onResolveConflict).toHaveBeenCalledWith(conflictedChange);
    expect(props.onOpenChangeCallback).toHaveBeenLastCalledWith(false);
    await waitFor(() => {
      expect(
        screen.queryByTestId('canvas-publish-reviews-content'),
      ).not.toBeInTheDocument();
    });
  });

  it('treats conflicted pending changes as normal rows when conflict UX is disabled', async () => {
    const user = userEvent.setup();
    conflictUxEnabled = false;
    renderReview([{ ...baseChange, hasConflict: true }]);

    await user.click(screen.getByTestId('canvas-publish-review'));

    expect(screen.queryByTestId('conflict-banner')).not.toBeInTheDocument();
    expect(
      screen.queryByTestId('change-conflict-icon'),
    ).not.toBeInTheDocument();
    expect(screen.getByText('1 unpublished change')).toBeInTheDocument();
  });

  it('reviews the currently selected reviewable changes', async () => {
    const user = userEvent.setup();
    const { props } = renderReview([
      baseChange,
      {
        ...baseChange,
        pointer: 'js_component:hero:en',
        label: 'Hero',
        entity_id: 'hero',
        entity_type: 'js_component',
      },
    ]);

    await user.click(screen.getByTestId('canvas-publish-review'));

    const reviewSelected = screen.getByRole('button', {
      name: 'Review selected changes',
    });
    expect(reviewSelected).toBeDisabled();

    await user.click(screen.getByLabelText('Select change Page 1'));
    await user.click(screen.getByLabelText('Select change Hero'));

    expect(reviewSelected).toBeEnabled();

    await user.click(reviewSelected);

    expect(props.onReviewSelectedChanges).toHaveBeenCalledWith([
      baseChange,
      expect.objectContaining({
        pointer: 'js_component:hero:en',
      }),
    ]);
    expect(props.onOpenChangeCallback).toHaveBeenLastCalledWith(false);
  });

  it('hides the side-by-side review action when conflict UX is disabled', async () => {
    const user = userEvent.setup();
    conflictUxEnabled = false;
    renderReview([baseChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));

    expect(
      screen.queryByRole('button', { name: 'Review selected changes' }),
    ).not.toBeInTheDocument();
  });
});
