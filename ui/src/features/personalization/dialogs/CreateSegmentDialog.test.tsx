import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import CreateSegmentDialog from './CreateSegmentDialog';

import type * as ReactRouterDom from 'react-router-dom';
import type { Segment } from '@/types/Personalization';

const mocks = vi.hoisted(() => ({
  createSegment: vi.fn(async (_arg: unknown) => ({ data: {} })),
  updateSegment: vi.fn(async (_arg: unknown) => ({ data: {} })),
  resetCreate: vi.fn(),
  navigate: vi.fn(),
}));

vi.mock('@/services/personalization', () => ({
  useCreateSegmentMutation: () => [
    mocks.createSegment,
    {
      isLoading: false,
      isError: false,
      error: undefined,
      reset: mocks.resetCreate,
    },
  ],
  useUpdateSegmentMutation: () => [mocks.updateSegment, { isLoading: false }],
}));

vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof ReactRouterDom>()),
  useNavigate: () => mocks.navigate,
}));

const segments: Record<string, Segment> = {
  default: {
    id: 'default',
    label: 'Default',
    status: true,
    weight: 2147483647,
  },
  returning_visitors: {
    id: 'returning_visitors',
    label: 'Returning visitors',
    status: true,
    weight: 0,
  },
};

const renderDialog = () =>
  render(
    <Theme>
      <CreateSegmentDialog open onOpenChange={vi.fn()} segments={segments} />
    </Theme>,
  );

describe('CreateSegmentDialog', () => {
  it('creates a disabled segment with a generated machine name', async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.type(screen.getByLabelText('Name'), 'High value customers');
    expect(
      screen.getByText('Machine name: high_value_customers'),
    ).toBeInTheDocument();
    await user.type(
      screen.getByLabelText('Description (optional)'),
      'Signed-in shoppers',
    );
    await user.click(screen.getByRole('button', { name: 'Create' }));

    await waitFor(() => {
      expect(mocks.createSegment).toHaveBeenCalledWith({
        id: 'high_value_customers',
        label: 'High value customers',
        description: 'Signed-in shoppers',
        status: false,
        weight: 0,
      });
    });
    // Existing segments are pushed down, except the default segment.
    expect(mocks.updateSegment).toHaveBeenCalledTimes(1);
    expect(mocks.updateSegment).toHaveBeenCalledWith({
      id: 'returning_visitors',
      changes: { weight: 1 },
    });
    expect(mocks.navigate).toHaveBeenCalledWith(
      '/segments/high_value_customers',
    );
  });

  it('blocks names that generate an existing machine name', async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.type(screen.getByLabelText('Name'), 'Returning visitors');

    expect(
      screen.getByText(
        'A segment with this name already exists. Choose a different name.',
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create' })).toBeDisabled();
    expect(mocks.createSegment).not.toHaveBeenCalled();
  });

  it('disables the create button while the name is empty', () => {
    renderDialog();

    expect(screen.getByRole('button', { name: 'Create' })).toBeDisabled();
  });
});
