// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  HEADLESS_ASSERTION_MESSAGE,
  HEADLESS_REFRESH_MESSAGE,
} from '../constants';
import { EXPIRY_SLACK_MS } from '../draft-data';
import {
  defineDraftSessionElement,
  DRAFT_SESSION_CHANGE_EVENT,
  DRAFT_SESSION_ELEMENT_TAG,
  DRAFT_SESSION_REFRESH_EVENT,
} from './draft-session-element';

import type { DraftSessionElementSnapshot } from './draft-session-element';

const { createHeightReporterMock, destroyHeightReporterMock } = vi.hoisted(
  () => ({
    createHeightReporterMock: vi.fn(),
    destroyHeightReporterMock: vi.fn(),
  }),
);

vi.mock('./height-report', () => ({
  createHeightReporter: createHeightReporterMock,
}));

const ORIGIN = 'https://drupal.example';

defineDraftSessionElement();

interface MountOptions {
  tokenExpiresAt?: number | null;
  initialExpired?: boolean;
  renewUrl?: string | null;
}

function mount(options: MountOptions = {}) {
  const {
    tokenExpiresAt = Date.now() + 300_000,
    initialExpired = false,
    renewUrl = 'https://drupal.example/canvas-headless/renew',
  } = options;

  const element = document.createElement(DRAFT_SESSION_ELEMENT_TAG);
  if (tokenExpiresAt !== null) {
    element.setAttribute('token-expires-at', String(tokenExpiresAt));
  }
  if (initialExpired) {
    element.setAttribute('initial-expired', '');
  }
  if (renewUrl !== null) {
    element.setAttribute('renew-url', renewUrl);
  }
  element.setAttribute('editor-origin', ORIGIN);

  element.innerHTML = `
    <div data-draft-session-view="active">active banner</div>
    <div data-draft-session-view="expired">
      expired banner
      <a data-draft-session-renew-link>Renew session</a>
    </div>
  `;

  const snapshots: DraftSessionElementSnapshot[] = [];
  element.addEventListener(DRAFT_SESSION_CHANGE_EVENT, (event) => {
    snapshots.push((event as CustomEvent<DraftSessionElementSnapshot>).detail);
  });

  document.body.appendChild(element);

  return {
    element,
    snapshots,
    activeView: element.querySelector<HTMLElement>(
      '[data-draft-session-view="active"]',
    )!,
    expiredView: element.querySelector<HTMLElement>(
      '[data-draft-session-view="expired"]',
    )!,
    renewLink: element.querySelector<HTMLAnchorElement>(
      '[data-draft-session-renew-link]',
    )!,
  };
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-07-08T12:00:00Z'));
  destroyHeightReporterMock.mockReset();
  createHeightReporterMock.mockReset().mockReturnValue({
    destroy: destroyHeightReporterMock,
  });
});

afterEach(() => {
  document.body.innerHTML = '';
  vi.useRealTimers();
});

describe('DraftSessionElement', () => {
  // jsdom is not embedded: window.self === window.top, so these tests
  // exercise the standalone lane. The embedded lane's behavior (host
  // messaging, renewal) is the machine's, covered in draft-session.test.ts.

  it('shows the active view of a live standalone session', () => {
    const { element, activeView, expiredView } = mount();

    expect(element.hasAttribute('expired')).toBe(false);
    expect(element.hasAttribute('embedded')).toBe(false);
    expect(element.getAttribute('renew-state')).toBe('idle');
    expect(activeView.hidden).toBe(false);
    expect(expiredView.hidden).toBe(true);
  });

  it('shows the expired view when the session is already expired', () => {
    const { element, activeView, expiredView, renewLink } = mount({
      initialExpired: true,
    });

    expect(element.hasAttribute('expired')).toBe(true);
    expect(activeView.hidden).toBe(true);
    expect(expiredView.hidden).toBe(false);
    expect(renewLink.hidden).toBe(false);
    expect(renewLink.getAttribute('href')).toBe(
      'https://drupal.example/canvas-headless/renew?path=%2F',
    );
  });

  it('hides the renew link when there is no renew URL', () => {
    const { renewLink } = mount({ initialExpired: true, renewUrl: null });
    expect(renewLink.hidden).toBe(true);
  });

  it('reads a stringified initial-expired="false" as not expired', () => {
    const element = document.createElement(DRAFT_SESSION_ELEMENT_TAG);
    element.setAttribute('initial-expired', 'false');
    element.setAttribute('editor-origin', ORIGIN);
    document.body.appendChild(element);
    expect(element.hasAttribute('expired')).toBe(false);
  });

  it('flips to the expired view when the token dies', () => {
    const { element, activeView, expiredView, snapshots } = mount({
      tokenExpiresAt: Date.now() + 300_000,
    });

    vi.advanceTimersByTime(300_000 - EXPIRY_SLACK_MS);

    expect(element.hasAttribute('expired')).toBe(true);
    expect(activeView.hidden).toBe(true);
    expect(expiredView.hidden).toBe(false);
    expect(snapshots.at(-1)).toMatchObject({ expired: true });
  });

  it('announces its state through the change event', () => {
    const { snapshots } = mount();
    expect(snapshots.at(-1)).toMatchObject({
      embedded: false,
      expired: false,
      renewState: 'idle',
      path: '/',
      renewUrl: 'https://drupal.example/canvas-headless/renew',
    });
  });

  it('tracks path changes through the observed attribute', () => {
    const { element, snapshots, renewLink } = mount({ initialExpired: true });
    expect(snapshots.at(-1)).toMatchObject({ path: '/' });

    element.setAttribute('path', '/node/7');

    expect(snapshots.at(-1)).toMatchObject({ path: '/node/7' });
    expect(renewLink.getAttribute('href')).toBe(
      'https://drupal.example/canvas-headless/renew?path=%2Fnode%2F7',
    );
  });

  it('honors an initial path attribute over window.location', () => {
    const element = document.createElement(DRAFT_SESSION_ELEMENT_TAG);
    element.setAttribute('path', '/start/here');
    element.setAttribute('editor-origin', ORIGIN);
    const snapshots: DraftSessionElementSnapshot[] = [];
    element.addEventListener(DRAFT_SESSION_CHANGE_EVENT, (event) => {
      snapshots.push(
        (event as CustomEvent<DraftSessionElementSnapshot>).detail,
      );
    });

    document.body.appendChild(element);

    expect(snapshots.at(-1)).toMatchObject({ path: '/start/here' });
  });

  it('stops tracking when disconnected', () => {
    const { element, snapshots } = mount({
      tokenExpiresAt: Date.now() + 300_000,
    });
    element.remove();
    const count = snapshots.length;

    vi.advanceTimersByTime(600_000);

    expect(snapshots).toHaveLength(count);
    expect(element.hasAttribute('expired')).toBe(false);
  });

  it('lets an adapter handle a refresh instead of reloading', () => {
    const originalTop = Object.getOwnPropertyDescriptor(window, 'top');
    Object.defineProperty(window, 'top', { value: {}, configurable: true });

    try {
      const { element } = mount();
      let refreshEvent: Event | null = null;
      element.addEventListener(DRAFT_SESSION_REFRESH_EVENT, (event) => {
        refreshEvent = event;
        event.preventDefault();
      });

      window.dispatchEvent(
        new MessageEvent('message', {
          data: { type: HEADLESS_REFRESH_MESSAGE },
          origin: ORIGIN,
          source: window.parent,
        }),
      );

      expect(refreshEvent).not.toBeNull();
      expect(refreshEvent).toMatchObject({
        bubbles: true,
        cancelable: true,
        composed: true,
        defaultPrevented: true,
      });
    } finally {
      if (originalTop) {
        Object.defineProperty(window, 'top', originalTop);
      }
    }
  });

  it('re-arms in place when a renewal delivers a new expiry', async () => {
    // Simulate embedding: give the machine a host window by dispatching the
    // assertion message with a mocked machine environment. The element
    // decides embedding from window.self !== window.top, which jsdom cannot
    // fake per test, so exercise the re-arm path directly through the
    // machine's renew fetch: patch fetch and window.parent.
    const originalTop = Object.getOwnPropertyDescriptor(window, 'top');
    Object.defineProperty(window, 'top', { value: {}, configurable: true });
    const renewedExpiry = Date.now() + 900_000;
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ tokenExpiresAt: renewedExpiry }),
    });
    vi.stubGlobal('fetch', fetchMock);

    try {
      const { element, snapshots } = mount({
        tokenExpiresAt: Date.now() + 300_000,
      });
      expect(element.hasAttribute('embedded')).toBe(true);

      window.dispatchEvent(
        new MessageEvent('message', {
          data: { type: HEADLESS_ASSERTION_MESSAGE, assertion: 'jwt-string' },
          origin: ORIGIN,
          source: window.parent,
        }),
      );
      await vi.advanceTimersByTimeAsync(0);

      expect(fetchMock).toHaveBeenCalledWith(
        '/api/draft/renew',
        expect.anything(),
      );
      expect(snapshots.at(-1)).toMatchObject({
        expired: false,
        tokenExpiresAt: renewedExpiry,
      });

      // The re-armed machine tracks the new expiry, not the old one.
      vi.advanceTimersByTime(300_000);
      expect(element.hasAttribute('expired')).toBe(false);
      vi.advanceTimersByTime(600_000);
      expect(element.hasAttribute('expired')).toBe(true);
    } finally {
      vi.unstubAllGlobals();
      if (originalTop) {
        Object.defineProperty(window, 'top', originalTop);
      }
    }
  });

  it('starts a height reporter on connect and tears it down on disconnect', () => {
    const originalTop = Object.getOwnPropertyDescriptor(window, 'top');
    Object.defineProperty(window, 'top', { value: {}, configurable: true });

    try {
      const { element } = mount({
        tokenExpiresAt: Date.now() + 300_000,
      });

      expect(createHeightReporterMock).toHaveBeenCalledWith({
        editorOrigin: ORIGIN,
        embedded: true,
      });

      element.remove();

      expect(destroyHeightReporterMock).toHaveBeenCalled();
    } finally {
      if (originalTop) {
        Object.defineProperty(window, 'top', originalTop);
      }
    }
  });
});
