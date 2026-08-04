import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PublishReview from '@/components/review/PublishReview';
import { formatScheduledDate } from '@/components/workspaces/utils';

import type { Workspace } from '@/services/workspacesApi';
import type { UnpublishedChange } from '@/types/Review';

vi.mock('@/features/conflict/conflictUtils', () => ({
  isConflictUxEnabled: () => false,
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

const baseWorkspace: Workspace = {
  id: 'spring_campaign',
  label: 'Spring campaign',
  isDefault: false,
  isActive: true,
  status: 'draft',
  statusLabel: 'Draft',
  statusIsApproved: false,
  statusIsInitial: true,
  requireReview: false,
  availableTransitions: [{ id: 'submit_for_review', label: 'Send for review' }],
  scheduledPublishAt: null,
  scheduledPublishError: null,
  pendingChangesCount: 1,
  access: {
    delete: true,
    publish: true,
  },
};

const inReviewWorkspace: Workspace = {
  ...baseWorkspace,
  requireReview: true,
  status: 'in_review',
  statusLabel: 'In review',
  statusIsInitial: false,
  availableTransitions: [
    { id: 'approve', label: 'Approve' },
    { id: 'send_back', label: 'Send back to draft' },
  ],
};

const renderReview = (workspace: Workspace) => {
  const store = makeStore();
  const props = {
    changes: [baseChange],
    errors: undefined,
    workspace,
    onOpenChangeCallback: vi.fn(),
    onPublishClick: vi.fn(),
    onDiscardClick: vi.fn(),
    onTransitionStatus: vi.fn(),
    onSchedulePublish: vi.fn(),
    onCancelSchedule: vi.fn(),
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

const openReview = async (user: ReturnType<typeof userEvent.setup>) => {
  await user.click(screen.getByTestId('canvas-publish-review'));
};

describe('PublishReview workspace states', () => {
  it('shows the workspace label and a draft badge in the header', async () => {
    const user = userEvent.setup();
    renderReview(baseWorkspace);
    await openReview(user);

    expect(screen.getByText('Spring campaign')).toBeInTheDocument();
    expect(screen.getByText('Draft')).toBeInTheDocument();
    expect(
      screen.queryByTestId('canvas-publish-review-select-all'),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByLabelText('Select change Page 1'),
    ).not.toBeInTheDocument();
  });

  it('publishes the whole workspace from the publish button', async () => {
    const user = userEvent.setup();
    const { props } = renderReview(baseWorkspace);
    await openReview(user);

    await user.click(screen.getByRole('button', { name: 'Publish now' }));
    expect(props.onPublishClick).toHaveBeenCalledWith();
  });

  it('offers send for review for a draft workspace that requires review', async () => {
    const user = userEvent.setup();
    const { props } = renderReview({
      ...baseWorkspace,
      requireReview: true,
    });
    await openReview(user);

    await user.click(screen.getByRole('button', { name: 'Send for review' }));
    expect(props.onTransitionStatus).toHaveBeenCalledWith('submit_for_review');
  });

  it('shows a disabled state button when no transitions are available', async () => {
    const user = userEvent.setup();
    renderReview({
      ...baseWorkspace,
      requireReview: true,
      availableTransitions: [],
    });
    await openReview(user);

    expect(screen.getByRole('button', { name: 'Draft' })).toBeDisabled();
  });

  it('offers the review transitions to reviewers', async () => {
    const user = userEvent.setup();
    const { props } = renderReview(inReviewWorkspace);
    await openReview(user);

    await user.click(
      screen.getByRole('button', { name: 'Send back to draft' }),
    );
    expect(props.onTransitionStatus).toHaveBeenCalledWith('send_back');

    await user.click(screen.getByRole('button', { name: 'Approve' }));
    expect(props.onTransitionStatus).toHaveBeenCalledWith('approve');
  });

  it('shows a disabled in review state to non-reviewers', async () => {
    const user = userEvent.setup();
    renderReview({
      ...inReviewWorkspace,
      availableTransitions: [],
    });
    await openReview(user);

    expect(screen.getByRole('button', { name: 'In review' })).toBeDisabled();
  });

  it('shows the schedule and allows canceling it', async () => {
    const user = userEvent.setup();
    const scheduledPublishAt = 2_000_000_000;
    const { props } = renderReview({
      ...baseWorkspace,
      status: 'approved',
      requireReview: true,
      scheduledPublishAt,
    });
    await openReview(user);

    expect(
      screen.getByText(
        `Scheduled to publish ${formatScheduledDate(scheduledPublishAt)}`,
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Scheduled' })).toBeDisabled();

    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(props.onCancelSchedule).toHaveBeenCalled();
  });

  it('shows the scheduled publish error in a callout', async () => {
    const user = userEvent.setup();
    renderReview({
      ...baseWorkspace,
      scheduledPublishError: 'Publishing failed because of a conflict.',
    });
    await openReview(user);

    expect(
      screen.getByText('Publishing failed because of a conflict.'),
    ).toBeInTheDocument();
  });
});
