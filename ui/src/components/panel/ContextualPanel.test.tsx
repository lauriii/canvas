import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import ContextualPanel from '@/components/panel/ContextualPanel';
import { setCommentsPanelOpen } from '@/features/comments/commentsSlice';
import { setConfiguration } from '@/features/configuration/configurationSlice';
import { setSelection } from '@/features/ui/uiSlice';

const hasPermissionMock = vi.fn<(permission: string) => boolean>(() => true);

vi.mock('@/hooks/useHidePanelClasses', () => ({
  default: () => [],
}));

vi.mock('@/utils/permissions', () => ({
  hasPermission: (...args: Parameters<typeof hasPermissionMock>) =>
    hasPermissionMock(...args),
}));

vi.mock('@/features/comments/CommentsPanel', () => ({
  default: () => <div data-testid="canvas-comments-panel">Comments panel</div>,
}));

const stubCommentsFetch = (threadCount: number) => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => {
      return new Response(
        JSON.stringify({
          threads: Array.from({ length: threadCount }, (_unused, index) => ({
            id: String(index + 1),
            uuid: `thread-${index + 1}`,
            surfaceType: 'canvas_page',
            surfaceId: '1',
            componentUuid: null,
            resolved: false,
            created: 1_777_000_000,
            changed: 1_777_000_000,
            author: { uid: 2, displayName: 'Alice', avatar: null },
            comments: [],
          })),
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      );
    }),
  );
};

vi.mock('@/components/PageDataForm', () => ({
  default: () => <div>Page data form</div>,
}));

const setUpStore = () => {
  const store = makeStore();
  store.dispatch(
    setConfiguration({
      baseUrl: 'http://localhost/',
      entityType: 'none',
      entity: 'none',
      isNew: false,
      isPublished: false,
      devMode: false,
    }),
  );
  return store;
};

const renderPanel = (store = setUpStore()) => {
  render(
    <AppWrapper
      store={store}
      location="/editor/canvas_page/1"
      path="/editor/:entityType/:entityId/*"
    >
      <ContextualPanel />
    </AppWrapper>,
  );
  return store;
};

describe('ContextualPanel', () => {
  beforeEach(() => {
    hasPermissionMock.mockReset();
    hasPermissionMock.mockImplementation(() => true);
  });

  it('offers a comments tab that opens the comments panel', async () => {
    const user = userEvent.setup();
    const store = renderPanel();

    await user.click(screen.getByTestId('canvas-contextual-panel--comments'));

    expect(store.getState().comments.panelOpen).toBe(true);
    expect(screen.getByTestId('canvas-comments-panel')).toBeInTheDocument();
  });

  it('hides the comments tab without the view permission', () => {
    hasPermissionMock.mockImplementation(
      (permission) => permission !== 'viewComments',
    );
    renderPanel();

    expect(
      screen.queryByTestId('canvas-contextual-panel--comments'),
    ).not.toBeInTheDocument();
  });

  it('keeps comments on screen when a component is selected', async () => {
    // Selecting a component is the first step of commenting on one, and it
    // used to switch the panel straight to Settings, taking the thread the
    // user was reading with it.
    const store = setUpStore();
    store.dispatch(setCommentsPanelOpen(true));
    renderPanel(store);
    expect(screen.getByTestId('canvas-comments-panel')).toBeInTheDocument();

    store.dispatch(setSelection({ items: ['uuid-anchored'] }));

    expect(
      await screen.findByTestId('canvas-comments-panel'),
    ).toBeInTheDocument();
    expect(store.getState().comments.panelOpen).toBe(true);
  });

  it('returns to the selection-driven tab when comments are closed', async () => {
    const user = userEvent.setup();
    const store = setUpStore();
    store.dispatch(setCommentsPanelOpen(true));
    renderPanel(store);

    await user.click(screen.getByTestId('canvas-contextual-panel--page-data'));

    expect(store.getState().comments.panelOpen).toBe(false);
    expect(
      screen.queryByTestId('canvas-comments-panel'),
    ).not.toBeInTheDocument();
  });
  it('counts the open threads on the tab that opens them', async () => {
    // With no button in the left rail any more, this count is the only thing
    // that says a page carries a conversation before you go looking.
    stubCommentsFetch(3);
    renderPanel();

    // Radix's tab trigger renders its label twice, the second copy purely to
    // reserve the width the bold active state needs.
    const counts = await screen.findAllByTestId('canvas-comments-count');
    expect(counts[0]).toHaveTextContent('3');
    expect(counts[0]).toHaveAttribute('aria-label', '3 open');
  });

  it('shows no count when nothing is open', async () => {
    stubCommentsFetch(0);
    renderPanel();

    await screen.findByTestId('canvas-contextual-panel--comments');
    expect(
      screen.queryByTestId('canvas-comments-count'),
    ).not.toBeInTheDocument();
  });
});
