import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PublishReview from '@/components/review/PublishReview';

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

const renderReview = (changes: UnpublishedChange[]) => {
  const store = makeStore();
  const props = {
    changes,
    conflictCount: changes.filter((change) => change.hasConflict).length,
    errors: undefined,
    onOpenChangeCallback: vi.fn(),
    onPublishClick: vi.fn(),
    onDiscardClick: vi.fn(),
    onResolveConflict: vi.fn(),
    isPublishing: false,
    isDiscarding: false,
    isUpdating: false,
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

  it('skips conflicted rows when selecting all', async () => {
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
    expect(screen.getByLabelText('Select change Page 2')).toBeDisabled();
    expect(screen.getByTestId('change-conflict-icon')).toBeInTheDocument();

    await user.click(screen.getByTestId('canvas-publish-review-select-all'));
    expect(screen.getByText('1 of 2 changes selected')).toBeInTheDocument();
  });

  it('auto-unselects a row that becomes conflicted', async () => {
    const user = userEvent.setup();
    const { rerender } = renderReview([baseChange]);

    await user.click(screen.getByTestId('canvas-publish-review'));
    await user.click(screen.getByLabelText('Select change Page 1'));
    expect(screen.getByText('1 of 1 changes selected')).toBeInTheDocument();

    const store = makeStore();
    rerender(
      <AppWrapper store={store} location="/" path="*">
        <PublishReview
          changes={[{ ...baseChange, hasConflict: true }]}
          conflictCount={1}
          errors={undefined}
          onOpenChangeCallback={vi.fn()}
          onPublishClick={vi.fn()}
          onDiscardClick={vi.fn()}
          onResolveConflict={vi.fn()}
          isPublishing={false}
          isDiscarding={false}
          isUpdating={false}
        />
      </AppWrapper>,
    );

    expect(screen.getByText('0 of 1 changes selected')).toBeInTheDocument();
    expect(screen.getByLabelText('Select change Page 1')).toBeDisabled();
    expect(
      screen.getByTestId('canvas-publish-review-select-all'),
    ).toBeDisabled();
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
    expect(screen.getByLabelText('Select change Page 1')).toBeEnabled();

    await user.click(screen.getByTestId('canvas-publish-review-select-all'));
    expect(screen.getByText('1 of 1 changes selected')).toBeInTheDocument();
  });
});
