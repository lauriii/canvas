// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  HEADLESS_HEIGHT_MESSAGE,
  HEADLESS_HEIGHT_PROBE_MESSAGE,
  HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
  HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
} from '@drupal-canvas/headless';

import {
  createHeadlessPreviewHost,
  HEADLESS_GEOMETRY_MESSAGE,
  HEADLESS_GEOMETRY_REQUEST_MESSAGE,
  HEADLESS_REFRESH_ACK_MESSAGE,
  HEADLESS_REFRESH_MESSAGE,
  HEADLESS_RENEW_REQUEST_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
  HEADLESS_STATUS_REQUEST_MESSAGE,
} from './index';

const FRONTEND_ORIGIN = 'https://app.example';

async function establishActiveSession(
  iframe: HTMLIFrameElement,
  host: ReturnType<typeof createHeadlessPreviewHost>,
  active = true,
): Promise<{
  hostSessionId: string;
  postMessage: ReturnType<typeof vi.spyOn>;
}> {
  await host.activate({ entity_type: 'canvas_page', entity: 'one' });
  const postMessage = vi.spyOn(iframe.contentWindow!, 'postMessage');
  iframe.dispatchEvent(new Event('load'));
  const statusRequest = postMessage.mock.calls.find(
    ([message]) =>
      (message as { type?: string }).type === HEADLESS_STATUS_REQUEST_MESSAGE,
  )?.[0] as { hostSessionId?: unknown } | undefined;
  expect(typeof statusRequest?.hostSessionId).toBe('string');
  const hostSessionId = statusRequest!.hostSessionId as string;

  if (active) {
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_STATUS_MESSAGE,
          status: 'active',
          path: '/page/one',
          tokenExpiresAt: 123,
          hostSessionId,
        },
      }),
    );
  }
  return { hostSessionId, postMessage };
}

async function createHeightHarness({
  active = true,
}: { active?: boolean } = {}) {
  const iframe = document.createElement('iframe');
  iframe.style.height = '500px';
  iframe.style.visibility = 'visible';
  document.body.appendChild(iframe);

  vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
    callback(0);
    return 1;
  });
  vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(() => {});
  const onHeight = vi.fn();
  const host = createHeadlessPreviewHost({
    iframe,
    frontendOrigin: FRONTEND_ORIGIN,
    draftUrl: `${FRONTEND_ORIGIN}/draft`,
    fetchAssertion: vi.fn().mockResolvedValue('signed assertion'),
    onHeight,
  });
  const { hostSessionId, postMessage } = await establishActiveSession(
    iframe,
    host,
    active,
  );
  postMessage.mockClear();

  const send = (data: Record<string, unknown>) => {
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { ...data, hostSessionId },
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
      }),
    );
  };

  return { host, hostSessionId, iframe, onHeight, postMessage, send };
}

afterEach(() => {
  document.body.innerHTML = '';
  vi.restoreAllMocks();
});

describe('headless height probing', () => {
  it('ignores sizing messages from the previous document while inactive', async () => {
    const { host, iframe, onHeight, postMessage, send } =
      await createHeightHarness({ active: false });

    send({ type: HEADLESS_HEIGHT_MESSAGE, height: 1200 });
    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'stale-probe',
      height: 1500,
    });

    expect(onHeight).not.toHaveBeenCalled();
    expect(iframe.style.height).toBe('500px');
    expect(postMessage).not.toHaveBeenCalled();

    host.destroy();
  });

  it('preserves content-template context when renewing', async () => {
    const iframe = document.createElement('iframe');
    document.body.append(iframe);
    const fetchAssertion = vi.fn().mockResolvedValue('signed assertion');
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin: FRONTEND_ORIGIN,
      draftUrl: 'https://app.example/api/draft',
      fetchAssertion,
    });

    await host.activate({
      entity_type: 'node',
      entity: '42',
      view_mode: 'teaser',
    });
    const postMessage = vi.spyOn(iframe.contentWindow!, 'postMessage');
    iframe.dispatchEvent(new Event('load'));
    const statusRequest = postMessage.mock.calls[0][0] as {
      hostSessionId: string;
    };
    fetchAssertion.mockClear();

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_RENEW_REQUEST_MESSAGE,
          path: '/node/42',
          hostSessionId: statusRequest.hostSessionId,
        },
      }),
    );

    await vi.waitFor(() =>
      expect(fetchAssertion).toHaveBeenCalledWith({
        path: '/node/42',
        view_mode: 'teaser',
        renewal: '1',
      }),
    );
    host.destroy();
  });

  it('temporarily applies probe heights and restores the iframe', async () => {
    const { host, hostSessionId, iframe, postMessage, send } =
      await createHeightHarness();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-1',
      height: 1500,
    });

    expect(iframe.style.height).toBe('1500px');
    expect(iframe.style.visibility).toBe('hidden');
    expect(postMessage).toHaveBeenLastCalledWith(
      {
        type: HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
        hostSessionId,
        id: 'probe-1',
        height: 1500,
      },
      FRONTEND_ORIGIN,
    );

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-2',
      height: null,
    });

    expect(iframe.style.height).toBe('500px');
    expect(iframe.style.visibility).toBe('visible');
    expect(postMessage).toHaveBeenLastCalledWith(
      {
        type: HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
        hostSessionId,
        id: 'probe-2',
        height: null,
      },
      FRONTEND_ORIGIN,
    );

    host.destroy();
  });

  it('preserves a height committed by the embedder during a probe', async () => {
    const { host, iframe, send } = await createHeightHarness();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-1',
      height: 1500,
    });

    // Simulate a declarative UI committing a reported final height while the
    // host temporarily owns the iframe's height for probing.
    iframe.style.height = '1200px';

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-2',
      height: 4000,
    });
    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-3',
      height: null,
    });

    expect(iframe.style.height).toBe('1200px');

    host.destroy();
  });

  it('ignores final height reports while a probe is active', async () => {
    const { host, onHeight, send } = await createHeightHarness();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-1',
      height: 1500,
    });
    send({ type: HEADLESS_HEIGHT_MESSAGE, height: 1500 });

    expect(onHeight).not.toHaveBeenCalled();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-2',
      height: null,
    });
    send({ type: HEADLESS_HEIGHT_MESSAGE, height: 750 });

    expect(onHeight).toHaveBeenCalledWith(750);

    host.destroy();
  });

  it('restores the iframe when the host is destroyed during a probe', async () => {
    const { host, iframe, send } = await createHeightHarness();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-1',
      height: 1500,
    });
    host.destroy();

    expect(iframe.style.height).toBe('500px');
    expect(iframe.style.visibility).toBe('visible');
  });

  it('sends the selected viewport height after the new document is active', async () => {
    const { host, hostSessionId, postMessage, send } =
      await createHeightHarness({ active: false });

    host.setViewportHeight(800);
    expect(postMessage).not.toHaveBeenCalled();

    send({
      type: HEADLESS_STATUS_MESSAGE,
      status: 'active',
      path: '/about',
      tokenExpiresAt: Date.now() + 60_000,
    });

    expect(postMessage).toHaveBeenCalledWith(
      {
        type: HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
        hostSessionId,
        height: 800,
      },
      FRONTEND_ORIGIN,
    );

    host.destroy();
  });
});
describe('createHeadlessPreviewHost', () => {
  afterEach(() => {
    document.body.replaceChildren();
    vi.restoreAllMocks();
  });

  it('accepts geometry only from its active iframe', async () => {
    const iframe = document.createElement('iframe');
    document.body.append(iframe);
    const events = vi.fn();
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin: 'https://app.example',
      draftUrl: 'https://app.example/api/draft',
      fetchAssertion: vi.fn().mockResolvedValue('signed assertion'),
      onEvent: events,
    });

    const { hostSessionId, postMessage } = await establishActiveSession(
      iframe,
      host,
    );

    expect(postMessage).toHaveBeenCalledWith(
      { type: HEADLESS_GEOMETRY_REQUEST_MESSAGE, hostSessionId },
      FRONTEND_ORIGIN,
    );

    const geometry = [
      {
        type: 'region' as const,
        id: 'content',
        markerFormat: 'comment' as const,
        rect: {
          top: 0,
          right: 100,
          bottom: 100,
          left: 0,
          width: 100,
          height: 100,
        },
      },
    ];
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://evil.example',
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_GEOMETRY_MESSAGE,
          geometry,
          hostSessionId,
        },
      }),
    );
    expect(events).not.toHaveBeenCalledWith({ type: 'geometry', geometry });

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_GEOMETRY_MESSAGE,
          geometry,
          hostSessionId,
        },
      }),
    );
    expect(events).toHaveBeenCalledWith({
      type: 'geometry',
      geometry,
    });

    host.destroy();
  });

  it('queues refreshes until the app acknowledges the previous command', async () => {
    const iframe = document.createElement('iframe');
    document.body.append(iframe);
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin: FRONTEND_ORIGIN,
      draftUrl: 'https://app.example/api/draft',
      fetchAssertion: vi.fn().mockResolvedValue('signed assertion'),
    });
    const { hostSessionId, postMessage } = await establishActiveSession(
      iframe,
      host,
    );
    postMessage.mockClear();

    host.refresh();
    host.refresh();

    expect(postMessage).toHaveBeenCalledTimes(1);
    expect(postMessage).toHaveBeenCalledWith(
      { type: HEADLESS_REFRESH_MESSAGE, refreshId: 1, hostSessionId },
      FRONTEND_ORIGIN,
    );

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_REFRESH_ACK_MESSAGE,
          refreshId: 1,
          hostSessionId,
        },
      }),
    );

    expect(postMessage).toHaveBeenCalledTimes(2);
    expect(postMessage).toHaveBeenLastCalledWith(
      { type: HEADLESS_REFRESH_MESSAGE, refreshId: 2, hostSessionId },
      FRONTEND_ORIGIN,
    );

    host.destroy();
  });

  it('rejects malformed geometry snapshots', async () => {
    const iframe = document.createElement('iframe');
    document.body.append(iframe);
    const events = vi.fn();
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin: FRONTEND_ORIGIN,
      draftUrl: 'https://app.example/api/draft',
      fetchAssertion: vi.fn().mockResolvedValue('signed assertion'),
      onEvent: events,
    });
    const { hostSessionId } = await establishActiveSession(iframe, host);
    events.mockClear();

    const validGeometry = {
      type: 'component',
      id: 'component-one',
      markerFormat: 'comment',
      rect: {
        top: 10,
        right: 110,
        bottom: 60,
        left: 10,
        width: 100,
        height: 50,
      },
    };
    const malformedSnapshots = [
      {},
      [null],
      [{ ...validGeometry, type: 'unknown' }],
      [{ ...validGeometry, id: '' }],
      [{ ...validGeometry, markerFormat: 'element' }],
      [
        {
          ...validGeometry,
          rect: { ...validGeometry.rect, top: Number.POSITIVE_INFINITY },
        },
      ],
      [{ ...validGeometry, rect: { ...validGeometry.rect, width: -1 } }],
      [{ ...validGeometry, stackDirection: 'diagonal' }],
    ];

    malformedSnapshots.forEach((geometry) => {
      window.dispatchEvent(
        new MessageEvent('message', {
          origin: FRONTEND_ORIGIN,
          source: iframe.contentWindow,
          data: {
            type: HEADLESS_GEOMETRY_MESSAGE,
            geometry,
            hostSessionId,
          },
        }),
      );
    });

    expect(events).toHaveBeenCalledTimes(malformedSnapshots.length);
    expect(events).toHaveBeenCalledWith({ type: 'geometry', geometry: [] });
    host.destroy();
  });

  it('ignores messages from the previous document during activation', async () => {
    const iframe = document.createElement('iframe');
    document.body.append(iframe);
    let resolveAssertion!: (assertion: string) => void;
    const fetchAssertion = vi.fn(
      () =>
        new Promise<string>((resolve) => {
          resolveAssertion = resolve;
        }),
    );
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin: FRONTEND_ORIGIN,
      draftUrl: 'https://app.example/api/draft',
      fetchAssertion,
    });

    const activation = host.activate({
      entity_type: 'canvas_page',
      entity: 'new-page',
    });
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_STATUS_MESSAGE,
          status: 'expired',
          path: '/old-page',
          hostSessionId: 'previous-session',
        },
      }),
    );

    expect(fetchAssertion).toHaveBeenCalledOnce();
    resolveAssertion('new-page-assertion');
    await activation;
    expect(iframe.src).toBe(
      'https://app.example/api/draft?assertion=new-page-assertion',
    );

    host.destroy();
  });

  it('starts a new protocol session after an iframe load', async () => {
    const iframe = document.createElement('iframe');
    document.body.append(iframe);
    const events = vi.fn();
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin: FRONTEND_ORIGIN,
      draftUrl: 'https://app.example/api/draft',
      fetchAssertion: vi.fn().mockResolvedValue('signed assertion'),
      onEvent: events,
    });
    const { hostSessionId: previousHostSessionId, postMessage } =
      await establishActiveSession(iframe, host);
    events.mockClear();
    postMessage.mockClear();

    iframe.dispatchEvent(new Event('load'));

    expect(events).toHaveBeenCalledExactlyOnceWith({
      type: 'geometry',
      geometry: [],
    });
    const statusRequest = postMessage.mock.calls[0][0] as {
      type: string;
      hostSessionId: string;
    };
    expect(statusRequest.type).toBe(HEADLESS_STATUS_REQUEST_MESSAGE);
    expect(statusRequest.hostSessionId).not.toBe(previousHostSessionId);

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_STATUS_MESSAGE,
          status: 'active',
          path: '/page/one',
          tokenExpiresAt: 123,
          hostSessionId: statusRequest.hostSessionId,
        },
      }),
    );
    expect(postMessage).toHaveBeenLastCalledWith(
      {
        type: HEADLESS_GEOMETRY_REQUEST_MESSAGE,
        hostSessionId: statusRequest.hostSessionId,
      },
      FRONTEND_ORIGIN,
    );

    host.destroy();
    events.mockClear();
    iframe.dispatchEvent(new Event('load'));
    expect(events).not.toHaveBeenCalled();
  });

  it('repeats the handshake when the app session machine is recreated', async () => {
    const iframe = document.createElement('iframe');
    document.body.append(iframe);
    const events = vi.fn();
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin: FRONTEND_ORIGIN,
      draftUrl: 'https://app.example/api/draft',
      fetchAssertion: vi.fn().mockResolvedValue('signed assertion'),
      onEvent: events,
    });
    const { hostSessionId, postMessage } = await establishActiveSession(
      iframe,
      host,
    );
    postMessage.mockClear();

    // A framework data refresh can recreate the app-side machine without
    // loading a new iframe document. Its first status has no session ID.
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_STATUS_MESSAGE,
          status: 'active',
          path: '/page/one',
          tokenExpiresAt: 456,
        },
      }),
    );

    expect(postMessage).toHaveBeenCalledExactlyOnceWith(
      { type: HEADLESS_STATUS_REQUEST_MESSAGE, hostSessionId },
      FRONTEND_ORIGIN,
    );

    events.mockClear();
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
        data: {
          type: HEADLESS_STATUS_MESSAGE,
          status: 'active',
          path: '/page/one',
          tokenExpiresAt: 456,
          hostSessionId,
        },
      }),
    );
    expect(events).toHaveBeenCalledWith({
      type: 'active',
      tokenExpiresAt: 456,
    });

    host.destroy();
  });

  it('re-sends an unacknowledged refresh after the app reports active', async () => {
    const iframe = document.createElement('iframe');
    document.body.append(iframe);
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin: FRONTEND_ORIGIN,
      draftUrl: 'https://app.example/api/draft',
      fetchAssertion: vi.fn().mockResolvedValue('signed assertion'),
    });
    const { hostSessionId, postMessage } = await establishActiveSession(
      iframe,
      host,
    );
    const reportActive = () =>
      window.dispatchEvent(
        new MessageEvent('message', {
          origin: FRONTEND_ORIGIN,
          source: iframe.contentWindow,
          data: {
            type: HEADLESS_STATUS_MESSAGE,
            status: 'active',
            path: '/page/one',
            tokenExpiresAt: 123,
            hostSessionId,
          },
        }),
      );

    postMessage.mockClear();
    host.refresh();
    reportActive();

    expect(postMessage).toHaveBeenNthCalledWith(
      1,
      { type: HEADLESS_REFRESH_MESSAGE, refreshId: 1, hostSessionId },
      FRONTEND_ORIGIN,
    );
    expect(postMessage).toHaveBeenNthCalledWith(
      3,
      { type: HEADLESS_REFRESH_MESSAGE, refreshId: 1, hostSessionId },
      FRONTEND_ORIGIN,
    );

    host.destroy();
  });
});
