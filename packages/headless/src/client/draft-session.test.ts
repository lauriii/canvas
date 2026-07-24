import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  HEADLESS_ASSERTION_MESSAGE,
  HEADLESS_REFRESH_ACK_MESSAGE,
  HEADLESS_REFRESH_MESSAGE,
  HEADLESS_RENEW_REQUEST_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
  HEADLESS_STATUS_REQUEST_MESSAGE,
} from '../constants';
import { EXPIRY_SLACK_MS } from '../draft-data';
import {
  createDraftSession,
  RENEW_MARGIN_MS,
  RENEW_TIMEOUT_MS,
} from './draft-session';

import type { DraftSessionEvent, DraftSessionOptions } from './draft-session';

const ORIGIN = 'https://drupal.example';
const OTHER_ORIGIN = 'https://other.example';
const HOST_SESSION_ID = 'host-session';

function makeHarness(overrides: Partial<DraftSessionOptions> = {}) {
  const postMessage = vi.fn();
  const hostWindow = { postMessage };
  const listeners = new Set<EventListener>();
  const listenerTarget = {
    addEventListener: vi.fn((_type: string, listener: EventListener) => {
      listeners.add(listener);
    }),
    removeEventListener: vi.fn((_type: string, listener: EventListener) => {
      listeners.delete(listener);
    }),
  };
  const fetchImpl = vi.fn();
  const refreshData = vi.fn();
  const events: DraftSessionEvent[] = [];

  const options: DraftSessionOptions = {
    tokenExpiresAt: Date.now() + 300_000,
    initialExpired: false,
    embedded: true,
    path: '/node/1',
    editorOrigin: ORIGIN,
    refreshData,
    onEvent: (event) => events.push(event),
    hostWindow,
    listenerTarget,
    fetchImpl: fetchImpl as unknown as typeof fetch,
    ...overrides,
  };

  const session = createDraftSession(options);

  const dispatchMessage = (event: Record<string, unknown>) => {
    const data = event.data as Record<string, unknown> | undefined;
    for (const listener of [...listeners]) {
      listener({
        source: hostWindow,
        origin: ORIGIN,
        ...event,
        ...(data ? { data: { hostSessionId: HOST_SESSION_ID, ...data } } : {}),
      } as unknown as Event);
    }
  };

  dispatchMessage({ data: { type: HEADLESS_STATUS_REQUEST_MESSAGE } });

  return {
    session,
    postMessage,
    listenerTarget,
    fetchImpl,
    refreshData,
    events,
    dispatchMessage,
  };
}

function statusMessages(postMessage: ReturnType<typeof vi.fn>) {
  return postMessage.mock.calls.filter(
    ([message]) => message.type === HEADLESS_STATUS_MESSAGE,
  );
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-07-08T12:00:00Z'));
});

afterEach(() => {
  vi.useRealTimers();
});

describe('createDraftSession', () => {
  it('reports the initial status to the signed editor origin', () => {
    const { postMessage } = makeHarness();
    const initial = statusMessages(postMessage);
    expect(initial).toHaveLength(2);
    expect(initial[1][0]).toMatchObject({
      status: 'active',
      path: '/node/1',
      hostSessionId: HOST_SESSION_ID,
    });
    expect(initial[1][1]).toBe(ORIGIN);
  });

  it('posts nothing without a signed editor origin', () => {
    const { postMessage } = makeHarness({ editorOrigin: null });
    vi.advanceTimersByTime(600_000);
    expect(postMessage).not.toHaveBeenCalled();
  });

  it('posts nothing when standalone', () => {
    const { postMessage } = makeHarness({ embedded: false });
    vi.advanceTimersByTime(600_000);
    expect(postMessage).not.toHaveBeenCalled();
  });

  it('flips to expired in sync with the server-side slack', () => {
    const expiresAt = Date.now() + 300_000;
    const { session, events, postMessage } = makeHarness({
      tokenExpiresAt: expiresAt,
      // Standalone, so the renewal lane stays out of the picture.
      embedded: false,
    });

    vi.advanceTimersByTime(300_000 - EXPIRY_SLACK_MS - 1);
    expect(session.getState().expired).toBe(false);

    vi.advanceTimersByTime(1);
    expect(session.getState().expired).toBe(true);
    expect(events).toEqual([{ type: 'expired' }]);
    expect(postMessage).not.toHaveBeenCalled();
  });

  it('reports the expired status to the host when embedded', () => {
    const { postMessage } = makeHarness({
      tokenExpiresAt: Date.now() + 300_000,
    });
    vi.advanceTimersByTime(300_000);
    const last = statusMessages(postMessage).at(-1);
    expect(last?.[0]).toMatchObject({ status: 'expired' });
  });

  it('requests a renewal RENEW_MARGIN_MS before expiry', () => {
    const { session, events, postMessage } = makeHarness({
      tokenExpiresAt: Date.now() + 300_000,
    });

    vi.advanceTimersByTime(300_000 - RENEW_MARGIN_MS - 1);
    expect(session.getState().renewState).toBe('idle');

    vi.advanceTimersByTime(1);
    expect(session.getState().renewState).toBe('requested');
    expect(events).toContainEqual({ type: 'renew-requested' });
    expect(postMessage).toHaveBeenCalledWith(
      {
        type: HEADLESS_RENEW_REQUEST_MESSAGE,
        path: '/node/1',
        hostSessionId: HOST_SESSION_ID,
      },
      ORIGIN,
    );
  });

  it('clamps the renewal margin to half the remaining life', () => {
    const { session } = makeHarness({
      tokenExpiresAt: Date.now() + 80_000,
    });

    // With a fixed 60 s margin the request would fire at 20 s; the
    // half-life clamp moves it to 40 s.
    vi.advanceTimersByTime(39_999);
    expect(session.getState().renewState).toBe('idle');
    vi.advanceTimersByTime(1);
    expect(session.getState().renewState).toBe('requested');
  });

  it('never requests a renewal when standalone', () => {
    const { session } = makeHarness({
      embedded: false,
      tokenExpiresAt: Date.now() + 300_000,
    });
    vi.advanceTimersByTime(300_000);
    expect(session.getState().renewState).toBe('idle');
  });

  it('gives up on a renewal the host never answers', () => {
    const { session, events } = makeHarness({
      tokenExpiresAt: Date.now() + 300_000,
    });
    vi.advanceTimersByTime(300_000 - RENEW_MARGIN_MS);
    expect(session.getState().renewState).toBe('requested');

    vi.advanceTimersByTime(RENEW_TIMEOUT_MS);
    expect(session.getState().renewState).toBe('failed');
    expect(events).toContainEqual({ type: 'renew-failed' });
  });

  it('redeems an assertion from the host and refreshes data', async () => {
    const { fetchImpl, refreshData, events, dispatchMessage } = makeHarness();
    const renewedExpiry = Date.now() + 900_000;
    fetchImpl.mockResolvedValue({
      ok: true,
      json: async () => ({ tokenExpiresAt: renewedExpiry }),
    });

    dispatchMessage({
      data: { type: HEADLESS_ASSERTION_MESSAGE, assertion: 'jwt-string' },
    });
    await vi.advanceTimersByTimeAsync(0);

    expect(fetchImpl).toHaveBeenCalledWith('/api/draft/renew', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ assertion: 'jwt-string' }),
    });
    expect(events).toContainEqual({
      type: 'renewed',
      tokenExpiresAt: renewedExpiry,
    });
    expect(refreshData).toHaveBeenCalledOnce();
  });

  it('acknowledges and refreshes data when the host reports a new auto-save', () => {
    const { events, fetchImpl, postMessage, refreshData, dispatchMessage } =
      makeHarness();

    dispatchMessage({
      data: { type: HEADLESS_REFRESH_MESSAGE, refreshId: 7 },
    });

    expect(events).toContainEqual({ type: 'refresh-requested' });
    expect(refreshData).toHaveBeenCalledOnce();
    expect(fetchImpl).not.toHaveBeenCalled();
    expect(postMessage).toHaveBeenCalledWith(
      {
        type: HEADLESS_REFRESH_ACK_MESSAGE,
        refreshId: 7,
        hostSessionId: HOST_SESSION_ID,
      },
      ORIGIN,
    );
  });

  it.each([
    ['a non-host source', { source: { postMessage: vi.fn() } }],
    ['a different origin', { origin: OTHER_ORIGIN }],
  ])('ignores refresh messages from %s', (_label, event) => {
    const { events, refreshData, dispatchMessage } = makeHarness();

    dispatchMessage({
      data: { type: HEADLESS_REFRESH_MESSAGE },
      ...event,
    });

    expect(events).not.toContainEqual({ type: 'refresh-requested' });
    expect(refreshData).not.toHaveBeenCalled();
  });

  it('ignores commands from a previous host protocol session', () => {
    const { events, refreshData, dispatchMessage } = makeHarness();

    dispatchMessage({
      data: {
        type: HEADLESS_REFRESH_MESSAGE,
        refreshId: 7,
        hostSessionId: 'previous-session',
      },
    });

    expect(events).not.toContainEqual({ type: 'refresh-requested' });
    expect(refreshData).not.toHaveBeenCalled();
  });

  it('renews without refreshData and without a usable response body', async () => {
    const { fetchImpl, events, dispatchMessage } = makeHarness({
      refreshData: undefined,
    });
    fetchImpl.mockResolvedValue({
      ok: true,
      json: async () => {
        throw new Error('no body');
      },
    });

    dispatchMessage({
      data: { type: HEADLESS_ASSERTION_MESSAGE, assertion: 'jwt-string' },
    });
    await vi.advanceTimersByTimeAsync(0);

    expect(events).toContainEqual({ type: 'renewed', tokenExpiresAt: null });
  });

  it('honors a custom renew endpoint', async () => {
    const { fetchImpl, dispatchMessage } = makeHarness({
      renewEndpoint: '/custom/renew',
    });
    fetchImpl.mockResolvedValue({
      ok: true,
      json: async () => ({ tokenExpiresAt: Date.now() + 900_000 }),
    });
    dispatchMessage({
      data: { type: HEADLESS_ASSERTION_MESSAGE, assertion: 'jwt-string' },
    });
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchImpl).toHaveBeenCalledWith('/custom/renew', expect.anything());
  });

  it('fails the renewal when the redemption is refused', async () => {
    const { session, fetchImpl, refreshData, events, dispatchMessage } =
      makeHarness();
    fetchImpl.mockResolvedValue({ ok: false });

    dispatchMessage({
      data: { type: HEADLESS_ASSERTION_MESSAGE, assertion: 'jwt-string' },
    });
    await vi.advanceTimersByTimeAsync(0);

    expect(session.getState().renewState).toBe('failed');
    expect(events).toContainEqual({ type: 'renew-failed' });
    expect(refreshData).not.toHaveBeenCalled();
  });

  it('fails the renewal when the redemption request itself fails', async () => {
    const { session, fetchImpl, dispatchMessage } = makeHarness();
    fetchImpl.mockRejectedValue(new Error('network down'));

    dispatchMessage({
      data: { type: HEADLESS_ASSERTION_MESSAGE, assertion: 'jwt-string' },
    });
    await vi.advanceTimersByTimeAsync(0);

    expect(session.getState().renewState).toBe('failed');
  });

  it.each([
    ['a non-host source', { source: { postMessage: vi.fn() } }],
    ['a different origin', { origin: OTHER_ORIGIN }],
    ['a different message type', { data: { type: 'other', assertion: 'x' } }],
    [
      'a non-string assertion',
      { data: { type: HEADLESS_ASSERTION_MESSAGE, assertion: 42 } },
    ],
    ['missing data', { data: undefined }],
  ])('ignores assertion messages from %s', async (_label, event) => {
    const { fetchImpl, dispatchMessage } = makeHarness();
    dispatchMessage({
      data: { type: HEADLESS_ASSERTION_MESSAGE, assertion: 'x' },
      ...event,
    });
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('re-reports status with the new path on setPath()', () => {
    const { session, postMessage } = makeHarness();
    session.setPath('/node/2');
    const last = statusMessages(postMessage).at(-1);
    expect(last?.[0]).toMatchObject({ status: 'active', path: '/node/2' });
  });

  it('does nothing after destroy()', () => {
    const { session, events, postMessage, listenerTarget } = makeHarness({
      tokenExpiresAt: Date.now() + 300_000,
    });
    postMessage.mockClear();

    session.destroy();
    vi.advanceTimersByTime(600_000);

    expect(events).toEqual([]);
    expect(postMessage).not.toHaveBeenCalled();
    expect(listenerTarget.removeEventListener).toHaveBeenCalled();
  });

  it('does not schedule anything without a token expiry', () => {
    const { session, events } = makeHarness({
      tokenExpiresAt: null,
      initialExpired: true,
    });
    vi.advanceTimersByTime(600_000);
    expect(events).toEqual([]);
    expect(session.getState()).toEqual({ expired: true, renewState: 'idle' });
  });
});
