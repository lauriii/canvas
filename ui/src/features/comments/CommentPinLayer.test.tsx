import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import CommentPinLayer from '@/features/comments/CommentPinLayer';
import { setCommentMode } from '@/features/comments/commentsSlice';
import { setConfiguration } from '@/features/configuration/configurationSlice';
import { selectActivePanel } from '@/features/ui/primaryPanelSlice';
import { setEditorFrameViewPort } from '@/features/ui/uiSlice';

import type { CanvasGeometryMap } from '@/features/layout/preview/PreviewGeometryContext';
import type { CommentThread } from '@/services/comments';

const geometryMap: CanvasGeometryMap = {
  component: {
    'uuid-anchored': {
      type: 'component',
      id: 'uuid-anchored',
      markerFormat: 'comment',
      rect: {
        top: 100,
        right: 300,
        bottom: 200,
        left: 40,
        width: 260,
        height: 100,
      },
    },
  },
  slot: {},
  region: {},
};

vi.mock('@/features/layout/preview/PreviewGeometryContext', () => ({
  usePreviewGeometry: () => ({ geometryMap }),
}));

const threads: CommentThread[] = [
  {
    id: '1',
    uuid: 'thread-1',
    surfaceType: 'canvas_page',
    surfaceId: '1',
    componentUuid: 'uuid-anchored',
    resolved: false,
    created: 1_777_000_000,
    changed: 1_777_000_000,
    author: { uid: 2, displayName: 'Alice', avatar: null },
    comments: [
      {
        id: 'c1',
        body: 'Opening comment',
        created: 1_777_000_000,
        changed: 1_777_000_000,
        author: { uid: 2, displayName: 'Alice', avatar: null },
      },
      {
        id: 'c2',
        body: 'A reply',
        created: 1_777_000_100,
        changed: 1_777_000_100,
        author: { uid: 3, displayName: 'Bob', avatar: null },
      },
      {
        id: 'c3',
        body: 'Another reply',
        created: 1_777_000_200,
        changed: 1_777_000_200,
        author: { uid: 3, displayName: 'Bob', avatar: null },
      },
    ],
  },
  {
    id: '2',
    uuid: 'thread-2',
    surfaceType: 'canvas_page',
    surfaceId: '1',
    // Anchored to a component that the preview has not measured.
    componentUuid: 'uuid-not-measured',
    resolved: false,
    created: 1_777_000_300,
    changed: 1_777_000_300,
    author: { uid: 2, displayName: 'Alice', avatar: null },
    comments: [
      {
        id: 'c4',
        body: 'Orphan',
        created: 1_777_000_300,
        changed: 1_777_000_300,
        author: { uid: 2, displayName: 'Alice', avatar: null },
      },
    ],
  },
  {
    id: '3',
    uuid: 'thread-3',
    surfaceType: 'canvas_page',
    surfaceId: '1',
    // Surface-level thread.
    componentUuid: null,
    resolved: false,
    created: 1_777_000_400,
    changed: 1_777_000_400,
    author: { uid: 2, displayName: 'Alice', avatar: null },
    comments: [
      {
        id: 'c5',
        body: 'About the page',
        created: 1_777_000_400,
        changed: 1_777_000_400,
        author: { uid: 2, displayName: 'Alice', avatar: null },
      },
    ],
  },
];

const stubFetch = () => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => {
      return new Response(JSON.stringify({ threads }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
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
  // Pins are only fetched and rendered while comments are relevant.
  store.dispatch(setCommentMode(true));
  return store;
};

describe('CommentPinLayer', () => {
  it('renders one pin per measured, component-anchored thread', async () => {
    stubFetch();
    const store = setUpStore();
    render(
      <AppWrapper
        store={store}
        location="/editor/canvas_page/1"
        path="/editor/:entityType/:entityId"
      >
        <CommentPinLayer />
      </AppWrapper>,
    );

    const pins = await screen.findAllByTestId('canvas-comment-pin');
    // Neither the unmeasured thread nor the surface-level thread gets a pin.
    expect(pins).toHaveLength(1);
    expect(pins[0]).toHaveAttribute('data-comment-thread-id', '1');
    expect(pins[0]).toHaveAccessibleName('Comment thread by Alice, 2 replies');
  });

  it('positions the pin using the editor viewport scale', async () => {
    stubFetch();
    const store = setUpStore();
    store.dispatch(setEditorFrameViewPort({ scale: 0.5 }));
    render(
      <AppWrapper
        store={store}
        location="/editor/canvas_page/1"
        path="/editor/:entityType/:entityId"
      >
        <CommentPinLayer />
      </AppWrapper>,
    );

    const pin = await screen.findByTestId('canvas-comment-pin');
    expect(pin).toHaveStyle({ top: '50px', left: '20px' });
  });

  it('clicking a pin activates the thread and opens the comments panel', async () => {
    stubFetch();
    const user = userEvent.setup();
    const store = setUpStore();
    render(
      <AppWrapper
        store={store}
        location="/editor/canvas_page/1"
        path="/editor/:entityType/:entityId"
      >
        <CommentPinLayer />
      </AppWrapper>,
    );

    await user.click(await screen.findByTestId('canvas-comment-pin'));

    expect(store.getState().comments.activeThreadId).toBe('1');
    expect(selectActivePanel(store.getState())).toBe('comments');
  });

  it('renders nothing while comments are not relevant', () => {
    stubFetch();
    const store = makeStore();
    store.dispatch(
      setConfiguration({
        baseUrl: 'http://localhost/',
        // Left unset on purpose: the surface comes from the route.
        entityType: 'none',
        entity: 'none',
        isNew: false,
        isPublished: false,
        devMode: false,
      }),
    );
    render(
      <AppWrapper
        store={store}
        location="/editor/canvas_page/1"
        path="/editor/:entityType/:entityId"
      >
        <CommentPinLayer />
      </AppWrapper>,
    );

    expect(
      screen.queryByTestId('canvas-comment-pin-layer'),
    ).not.toBeInTheDocument();
  });
});
