import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PageHistoryButton from './PageHistoryButton';

const earlier = {
  id: 9,
  created: 1_776_000_000,
  author: 'Kim',
  message: null,
  title: 'About',
  published: true,
  live: false,
};

const mocks = vi.hoisted(() => ({
  navigate: vi.fn(),
  restoreRevision: vi.fn(),
  draft: null as { updated: number; author: string | null } | null,
  pathname: '/editor/canvas_page/1',
  params: { entityType: 'canvas_page', entityId: '1' } as Record<
    string,
    string
  >,
}));

vi.mock('react-router', () => ({
  useParams: () => mocks.params,
  useLocation: () => ({ pathname: mocks.pathname }),
  useNavigate: () => mocks.navigate,
}));

vi.mock('@/services/content', () => ({
  useGetPageRevisionsQuery: () => ({ data: { draft: mocks.draft } }),
}));

vi.mock('@/features/pageHistory/useRestorePageRevision', () => ({
  useRestorePageRevision: () => ({
    restoreRevision: mocks.restoreRevision,
    isRestoring: false,
  }),
}));

// The list itself is tested on its own; here, only what it asks for.
vi.mock('@/features/pageHistory/PageHistory', () => ({
  default: ({
    onCompare,
    onRestore,
  }: {
    onCompare: (revision: typeof earlier) => void;
    onRestore: (revision: typeof earlier) => void;
  }) => (
    <div>
      <button onClick={() => onCompare(earlier)}>Compare with live</button>
      <button onClick={() => onRestore(earlier)}>Restore</button>
    </div>
  ),
}));

const renderButton = () =>
  render(
    <Theme>
      <PageHistoryButton />
    </Theme>,
  );

describe('PageHistoryButton', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.pathname = '/editor/canvas_page/1';
    mocks.params = { entityType: 'canvas_page', entityId: '1' };
    mocks.draft = null;
  });

  it('opens the history, like notifications', async () => {
    renderButton();
    await userEvent.click(screen.getByRole('button', { name: 'History' }));
    expect(
      screen.getByRole('heading', { name: 'History' }),
    ).toBeInTheDocument();
  });

  it('closes it to compare a version with what is live', async () => {
    renderButton();
    await userEvent.click(screen.getByRole('button', { name: 'History' }));
    await userEvent.click(
      screen.getByRole('button', { name: 'Compare with live' }),
    );
    expect(mocks.navigate).toHaveBeenCalledWith('/history/canvas_page/1/9');
    expect(
      screen.queryByRole('heading', { name: 'History' }),
    ).not.toBeInTheDocument();
  });

  it('restores a version only after confirming', async () => {
    renderButton();
    await userEvent.click(screen.getByRole('button', { name: 'History' }));
    await userEvent.click(screen.getByRole('button', { name: 'Restore' }));
    expect(mocks.restoreRevision).not.toHaveBeenCalled();
    const dialog = screen.getByRole('alertdialog');
    expect(
      within(dialog).getByText(/Nothing goes live until you publish/),
    ).toBeInTheDocument();
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Restore' }),
    );
    expect(mocks.restoreRevision).toHaveBeenCalledWith('1', 9);
  });

  it('warns that restoring replaces unpublished changes', async () => {
    mocks.draft = { updated: 1_777_100_000, author: 'Sam' };
    renderButton();
    await userEvent.click(screen.getByRole('button', { name: 'History' }));
    await userEvent.click(screen.getByRole('button', { name: 'Restore' }));
    const dialog = screen.getByRole('alertdialog');
    expect(
      within(dialog).getByText(
        /replaces the page.s unpublished changes, last saved by Sam/,
      ),
    ).toBeInTheDocument();
    await userEvent.click(
      within(dialog).getByRole('button', { name: 'Replace changes' }),
    );
    expect(mocks.restoreRevision).toHaveBeenCalledWith('1', 9);
  });

  it('is only there while editing a page', () => {
    mocks.params = { entityType: 'node', entityId: '1' };
    mocks.pathname = '/editor/node/1';
    renderButton();
    expect(
      screen.queryByRole('button', { name: 'History' }),
    ).not.toBeInTheDocument();
  });
});
