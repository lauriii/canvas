import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import CommentPinLayer, {
  findComponentAtPoint,
  pinPosition,
} from '@/features/comments/CommentPinLayer';
import { setCommentMode } from '@/features/comments/commentsSlice';
import { setConfiguration } from '@/features/configuration/configurationSlice';
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

const hasPermissionMock = vi.fn((_permission: string) => true);
vi.mock('@/utils/permissions', () => ({
  hasPermission: (...args: Parameters<typeof hasPermissionMock>) =>
    hasPermissionMock(...args),
}));

const threads: CommentThread[] = [
  {
    id: '1',
    uuid: 'thread-1',
    surfaceType: 'canvas_page',
    surfaceId: '1',
    componentUuid: 'uuid-anchored',
    offsetX: null,
    offsetY: null,
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
        mentions: [],
      },
      {
        id: 'c2',
        body: 'A reply',
        created: 1_777_000_100,
        changed: 1_777_000_100,
        author: { uid: 3, displayName: 'Bob', avatar: null },
        mentions: [],
      },
      {
        id: 'c3',
        body: 'Another reply',
        created: 1_777_000_200,
        changed: 1_777_000_200,
        author: { uid: 3, displayName: 'Bob', avatar: null },
        mentions: [],
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
    offsetX: null,
    offsetY: null,
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
        mentions: [],
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
    offsetX: null,
    offsetY: null,
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
        mentions: [],
      },
    ],
  },
];

let postedBodies: unknown[] = [];

const stubFetch = () => {
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
        return new Response(JSON.stringify({ thread: threads[0] }), {
          status: 201,
          headers: { 'Content-Type': 'application/json' },
        });
      }
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
  return store;
};

describe('CommentPinLayer', () => {
  beforeEach(() => {
    hasPermissionMock.mockReset();
    hasPermissionMock.mockImplementation(() => true);
  });

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
    // The panel is the comments tab of the contextual panel on the right, so
    // opening it is a comments-slice concern, not a primary-panel one.
    expect(store.getState().comments.panelOpen).toBe(true);
  });

  it('picks the smallest component containing the point', () => {
    // Component rectangles nest, so clicking a heading inside a section has to
    // anchor to the heading, not to the section around it.
    const nested: CanvasGeometryMap = {
      component: {
        'uuid-section': {
          type: 'component',
          id: 'uuid-section',
          markerFormat: 'comment',
          rect: {
            top: 0,
            right: 400,
            bottom: 400,
            left: 0,
            width: 400,
            height: 400,
          },
        },
        'uuid-heading': {
          type: 'component',
          id: 'uuid-heading',
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

    expect(findComponentAtPoint(nested, 100, 150)).toBe('uuid-heading');
    // Inside the section but outside the heading.
    expect(findComponentAtPoint(nested, 350, 350)).toBe('uuid-section');
    // Outside everything.
    expect(findComponentAtPoint(nested, 900, 900)).toBeNull();
  });

  it('places a thread where comment mode is clicked', async () => {
    stubFetch();
    const user = userEvent.setup();
    const store = setUpStore();
    store.dispatch(setCommentMode(true));
    render(
      <AppWrapper
        store={store}
        location="/editor/canvas_page/1"
        path="/editor/:entityType/:entityId"
      >
        <CommentPinLayer />
      </AppWrapper>,
    );

    const layer = await screen.findByTestId('canvas-comment-pin-layer');
    expect(layer).toHaveAttribute('data-comment-mode', 'true');
    // jsdom gives every element a zero-sized rect, so a click at the origin
    // lands inside the anchored component's rectangle, which starts at 40/100
    // only in preview pixels. Point at its middle instead.
    vi.spyOn(layer, 'getBoundingClientRect').mockReturnValue({
      top: 0,
      left: 0,
      right: 400,
      bottom: 400,
      width: 400,
      height: 400,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    });
    await user.pointer({
      target: layer,
      coords: { clientX: 100, clientY: 150 },
    });
    await user.click(layer);

    const composer = await screen.findByTestId('canvas-comment-draft-composer');
    expect(composer).toBeInTheDocument();

    await user.type(
      screen.getByTestId('canvas-comment-draft-input'),
      'Placed by clicking',
    );
    await user.click(screen.getByTestId('canvas-comment-draft-submit'));

    await waitFor(() => {
      // The click lands 60px into a 260px-wide, 100px-tall component, so it
      // is stored as a fraction of that box rather than as a canvas point.
      expect(postedBodies).toContainEqual({
        surfaceType: 'canvas_page',
        surfaceId: '1',
        componentUuid: 'uuid-anchored',
        offsetX: 60 / 260,
        offsetY: 0.5,
        body: 'Placed by clicking',
      });
    });
    // Posting leaves comment mode and shows the thread in the panel.
    expect(store.getState().comments.commentModeActive).toBe(false);
    expect(store.getState().comments.panelOpen).toBe(true);
  });

  it('positions a pin at the point the comment was left at', () => {
    const rect = { top: 100, left: 40, width: 260, height: 100 };

    // Halfway across, three quarters down.
    expect(pinPosition(rect, { offsetX: 0.5, offsetY: 0.75 }, 1)).toEqual({
      left: '170px',
      top: '175px',
    });

    // The offset is a fraction, so the same thread lands on the same part of
    // the component after it reflows to a different size.
    expect(
      pinPosition(
        { top: 100, left: 40, width: 520, height: 200 },
        { offsetX: 0.5, offsetY: 0.75 },
        1,
      ),
    ).toEqual({ left: '300px', top: '250px' });

    // Scale multiplies the result, as it does for every overlay.
    expect(pinPosition(rect, { offsetX: 0.5, offsetY: 0.75 }, 0.5)).toEqual({
      left: '85px',
      top: '87.5px',
    });

    // A thread from before offsets existed, or started from the sidebar,
    // still pins to the component's corner.
    expect(pinPosition(rect, { offsetX: null, offsetY: null }, 1)).toEqual({
      left: '40px',
      top: '100px',
    });
  });

  it('draws pins with the panel closed', async () => {
    // The pin is how a page says it carries a conversation, which it cannot do
    // from behind a closed panel.
    stubFetch();
    const store = setUpStore();
    expect(store.getState().comments.panelOpen).toBe(false);
    expect(store.getState().comments.commentModeActive).toBe(false);
    render(
      <AppWrapper
        store={store}
        location="/editor/canvas_page/1"
        path="/editor/:entityType/:entityId"
      >
        <CommentPinLayer />
      </AppWrapper>,
    );

    expect(await screen.findByTestId('canvas-comment-pin')).toBeInTheDocument();
  });

  it('draws nothing without the view permission', () => {
    hasPermissionMock.mockImplementation(
      (permission) => permission !== 'viewComments',
    );
    stubFetch();
    render(
      <AppWrapper
        store={setUpStore()}
        location="/editor/canvas_page/1"
        path="/editor/:entityType/:entityId"
      >
        <CommentPinLayer />
      </AppWrapper>,
    );

    expect(
      screen.queryByTestId('canvas-comment-pin-layer'),
    ).not.toBeInTheDocument();
    // Nor is the request made, so it cannot be refused.
    expect(fetch).not.toHaveBeenCalled();
  });
});
