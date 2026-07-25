import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import CommentsPanel from '@/features/comments/CommentsPanel';
import { setConfiguration } from '@/features/configuration/configurationSlice';
import { setSelection } from '@/features/ui/uiSlice';

import type { CommentAuthor, CommentThread } from '@/services/comments';

const alice: CommentAuthor = { uid: 2, displayName: 'Alice', avatar: null };
const bob: CommentAuthor = { uid: 3, displayName: 'Bob', avatar: null };

const openThread: CommentThread = {
  id: '1',
  uuid: 'thread-1',
  surfaceType: 'canvas_page',
  surfaceId: '1',
  componentUuid: 'uuid-anchored',
  resolved: false,
  created: 1_777_000_000,
  changed: 1_777_000_100,
  author: alice,
  comments: [
    {
      id: 'c1',
      body: 'The heading is too long.',
      created: 1_777_000_000,
      changed: 1_777_000_000,
      author: alice,
    },
    {
      id: 'c2',
      body: 'Shortened it.',
      created: 1_777_000_100,
      changed: 1_777_000_100,
      author: bob,
    },
  ],
};

const resolvedThread: CommentThread = {
  id: '2',
  uuid: 'thread-2',
  surfaceType: 'canvas_page',
  surfaceId: '1',
  componentUuid: null,
  resolved: true,
  created: 1_777_000_200,
  changed: 1_777_000_200,
  author: bob,
  comments: [
    {
      id: 'c3',
      body: 'Fixed the footer.',
      created: 1_777_000_200,
      changed: 1_777_000_200,
      author: bob,
    },
  ],
};

let postedBodies: unknown[] = [];

const stubFetch = (threads: CommentThread[] = [openThread, resolvedThread]) => {
  postedBodies = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const request =
        input instanceof Request ? input : new Request(input, init);
      if (request.url.endsWith('session/token')) {
        return new Response('csrf-token', { status: 200 });
      }
      if (request.method !== 'GET') {
        postedBodies.push(await request.clone().json());
        return new Response(JSON.stringify({ thread: openThread }), {
          status: 201,
          headers: { 'Content-Type': 'application/json' },
        });
      }
      const includeResolved = request.url.includes('includeResolved=1');
      return new Response(
        JSON.stringify({
          threads: includeResolved
            ? threads
            : threads.filter((thread) => !thread.resolved),
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      );
    }),
  );
};

const setUpStore = () => {
  const store = makeStore();
  store.dispatch(
    setConfiguration({
      // Node's `Request` rejects relative URLs, so tests need an absolute base.
      baseUrl: 'http://localhost/',
      // Left unset on purpose: the surface comes from the route.
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
  // The surface comes from the route, not from the store, so the panel has to
  // be rendered under a real editor path. The trailing splat is there because
  // expanding a component thread selects that component, which pushes
  // `/component/{uuid}` onto the router.
  render(
    <AppWrapper
      store={store}
      location="/editor/canvas_page/1"
      path="/editor/:entityType/:entityId/*"
    >
      <CommentsPanel />
    </AppWrapper>,
  );
  return store;
};

describe('CommentsPanel', () => {
  beforeEach(() => {
    stubFetch();
  });

  it('lists open threads with their author, body and reply count', async () => {
    renderPanel();

    expect(screen.getByTestId('canvas-comments-panel')).toBeInTheDocument();
    const thread = await screen.findByTestId('canvas-comment-thread');
    expect(within(thread).getByText('Alice')).toBeInTheDocument();
    expect(
      within(thread).getByText('The heading is too long.'),
    ).toBeInTheDocument();
    expect(
      within(thread).getByTestId('canvas-comment-replies'),
    ).toHaveTextContent('1 reply');
    // The resolved thread is hidden behind the "Resolved" filter.
    expect(screen.queryByText('Fixed the footer.')).not.toBeInTheDocument();
  });

  it('shows an empty state when the surface has no open threads', async () => {
    stubFetch([]);
    renderPanel();

    expect(
      await screen.findByTestId('canvas-comments-empty'),
    ).toHaveTextContent('No open comments yet.');
  });

  it('switching the filter lists resolved threads instead', async () => {
    const user = userEvent.setup();
    const store = renderPanel();
    await screen.findByTestId('canvas-comment-thread');

    await user.click(screen.getByTestId('canvas-comments-filter-resolved'));

    expect(store.getState().comments.filter).toBe('resolved');
    await waitFor(() => {
      expect(screen.getByText('Fixed the footer.')).toBeInTheDocument();
    });
    expect(
      screen.queryByText('The heading is too long.'),
    ).not.toBeInTheDocument();
  });

  it('expanding a thread reveals its replies, a reply box and Resolve', async () => {
    const user = userEvent.setup();
    const store = renderPanel();

    await user.click(await screen.findByTestId('canvas-comment-thread-toggle'));

    expect(store.getState().comments.activeThreadId).toBe('1');
    expect(screen.getByText('Shortened it.')).toBeInTheDocument();
    expect(
      screen.getByTestId('canvas-comment-reply-input'),
    ).toBeInTheDocument();
    expect(screen.getByTestId('canvas-comment-resolve')).toHaveTextContent(
      'Resolve',
    );
  });

  it('expanding a component thread also selects that component', async () => {
    const user = userEvent.setup();
    const store = renderPanel();

    await user.click(await screen.findByTestId('canvas-comment-thread-toggle'));

    expect(store.getState().ui.selection.items).toEqual(['uuid-anchored']);
  });

  it('creates a surface-level thread when no component is selected', async () => {
    const user = userEvent.setup();
    renderPanel();
    await screen.findByTestId('canvas-comment-thread');

    expect(screen.getByTestId('canvas-comment-target')).toHaveTextContent(
      'Will be attached to this page.',
    );
    await user.type(
      screen.getByTestId('canvas-comment-composer'),
      'A page-level note',
    );
    await user.click(screen.getByTestId('canvas-comment-submit'));

    await waitFor(() => {
      expect(postedBodies).toContainEqual({
        surfaceType: 'canvas_page',
        surfaceId: '1',
        componentUuid: null,
        body: 'A page-level note',
      });
    });
  });

  it('creates a component thread when a component is selected', async () => {
    const user = userEvent.setup();
    const store = setUpStore();
    store.dispatch(
      setSelection({ items: ['uuid-anchored'], consecutive: true }),
    );
    renderPanel(store);
    await screen.findByTestId('canvas-comment-thread');

    expect(screen.getByTestId('canvas-comment-target')).toHaveTextContent(
      'Will be attached to the selected component.',
    );
    await user.type(screen.getByTestId('canvas-comment-composer'), 'Fix this');
    await user.click(screen.getByTestId('canvas-comment-submit'));

    await waitFor(() => {
      expect(postedBodies).toContainEqual({
        surfaceType: 'canvas_page',
        surfaceId: '1',
        componentUuid: 'uuid-anchored',
        body: 'Fix this',
      });
    });
  });

  it('replying posts only the reply body', async () => {
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByTestId('canvas-comment-thread-toggle'));
    await user.type(screen.getByTestId('canvas-comment-reply-input'), 'On it');
    await user.click(screen.getByTestId('canvas-comment-reply-submit'));

    await waitFor(() => {
      expect(postedBodies).toContainEqual({ body: 'On it' });
    });
  });

  it('resolving sends only the resolved flag', async () => {
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByTestId('canvas-comment-thread-toggle'));
    await user.click(screen.getByTestId('canvas-comment-resolve'));

    await waitFor(() => {
      expect(postedBodies).toContainEqual({ resolved: true });
    });
  });
});
