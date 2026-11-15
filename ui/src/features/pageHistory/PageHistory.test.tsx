import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PageHistory from './PageHistory';

const mocks = vi.hoisted(() => ({
  onCompare: vi.fn(),
  onRestore: vi.fn(),
  canRestore: true,
  isLoading: false,
  draft: null as { updated: number; author: string | null } | null,
}));

const earlier = {
  id: 9,
  created: 1_776_000_000,
  author: null,
  message: 'Spring campaign',
  title: 'About',
  published: true,
  live: false,
};

vi.mock('@/services/content', () => ({
  useGetPageRevisionsQuery: () => ({
    isLoading: mocks.isLoading,
    isError: false,
    data: {
      canRestore: mocks.canRestore,
      draft: mocks.draft,
      revisions: [
        {
          id: 12,
          created: 1_777_000_000,
          author: 'Kim',
          message: null,
          title: 'About us',
          published: true,
          live: true,
        },
        earlier,
      ],
    },
  }),
}));

const renderList = () =>
  render(
    <Theme>
      <PageHistory
        entityId="1"
        onCompare={mocks.onCompare}
        onRestore={mocks.onRestore}
      />
    </Theme>,
  );

describe('PageHistory', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.canRestore = true;
    mocks.isLoading = false;
    mocks.draft = null;
  });

  it('says when there are unpublished changes on top of the live version', () => {
    mocks.draft = { updated: 1_777_100_000, author: 'Sam' };
    renderList();
    const [draft, live] = within(
      screen.getByRole('list', { name: 'Versions' }),
    ).getAllByRole('listitem');
    expect(within(draft).getByText('Unpublished changes')).toBeInTheDocument();
    expect(within(draft).getByText(/^Sam ·/)).toBeInTheDocument();
    expect(within(draft).queryByRole('button')).not.toBeInTheDocument();
    expect(within(live).getByText('Live')).toBeInTheDocument();
  });

  it('shows rows shaped like versions while they load', () => {
    mocks.isLoading = true;
    renderList();
    expect(
      screen.getByRole('list', { name: 'Loading versions' }),
    ).toHaveAttribute('aria-busy', 'true');
  });

  it('lists versions, newest first, with actions only for earlier ones', () => {
    renderList();
    const [live, older] = within(
      screen.getByRole('list', { name: 'Versions' }),
    ).getAllByRole('listitem');

    expect(within(live).getByText('Live')).toBeInTheDocument();
    expect(within(live).queryByRole('button')).not.toBeInTheDocument();

    expect(within(older).getByText('Deleted user · About')).toBeInTheDocument();
    expect(within(older).getByText('Spring campaign')).toBeInTheDocument();
  });

  it('asks to compare or restore an earlier version', async () => {
    renderList();
    await userEvent.click(
      screen.getByRole('button', { name: 'Compare with live' }),
    );
    expect(mocks.onCompare).toHaveBeenCalledWith(earlier);
    await userEvent.click(screen.getByRole('button', { name: 'Restore' }));
    expect(mocks.onRestore).toHaveBeenCalledWith(earlier);
  });

  it('offers restoring only to those who may', () => {
    mocks.canRestore = false;
    renderList();
    expect(
      screen.queryByRole('button', { name: 'Restore' }),
    ).not.toBeInTheDocument();
  });
});
