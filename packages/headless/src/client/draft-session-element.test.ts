// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  HEADLESS_ASSERTION_MESSAGE,
  HEADLESS_NAVIGATION_MESSAGE,
  HEADLESS_NAVIGATION_READY_MESSAGE,
  HEADLESS_REFRESH_MESSAGE,
  HEADLESS_STATUS_REQUEST_MESSAGE,
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
const HOST_SESSION_ID = 'host-session';

defineDraftSessionElement();

function establishHostSession(navigation = false): void {
  window.dispatchEvent(
    new MessageEvent('message', {
      data: {
        type: HEADLESS_STATUS_REQUEST_MESSAGE,
        hostSessionId: HOST_SESSION_ID,
        navigation,
      },
      origin: ORIGIN,
      source: window.parent,
    }),
  );
}

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
  window.history.replaceState(null, '', '/');
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('DraftSessionElement', () => {
  it('shows the active view of a live standalone session', () => {
    const postMessage = vi.spyOn(window.parent, 'postMessage');
    const { element, activeView, expiredView } = mount();

    expect(element.hasAttribute('expired')).toBe(false);
    expect(element.hasAttribute('embedded')).toBe(false);
    expect(element.getAttribute('renew-state')).toBe('idle');
    expect(activeView.hidden).toBe(false);
    expect(expiredView.hidden).toBe(true);
    expect(postMessage).not.toHaveBeenCalledWith(
      { type: HEADLESS_NAVIGATION_READY_MESSAGE },
      '*',
    );
  });

  it.each([false, true])(
    'delegates navigation until disconnected, including expired sessions (initialExpired: %s)',
    (initialExpired) => {
      const originalTop = Object.getOwnPropertyDescriptor(window, 'top');
      Object.defineProperty(window, 'top', { value: {}, configurable: true });
      vi.stubGlobal(
        'navigator',
        Object.assign(Object.create(window.navigator), {
          userActivation: { isActive: true },
        }),
      );
      const postMessage = vi
        .spyOn(window.parent, 'postMessage')
        .mockImplementation(() => {});
      const link = document.createElement('a');
      link.href = 'https://frontend.example/articles';
      document.body.appendChild(link);
      // Prevent jsdom navigation when the bridge is disconnected.
      link.addEventListener('click', (event) => event.preventDefault());

      try {
        const { element } = mount({ initialExpired });
        expect(postMessage).toHaveBeenCalledWith(
          { type: HEADLESS_NAVIGATION_READY_MESSAGE },
          '*',
        );
        establishHostSession(true);

        const expectDelegation = () => {
          postMessage.mockClear();
          link.click();
          expect(postMessage).toHaveBeenCalledExactlyOnceWith(
            {
              type: HEADLESS_NAVIGATION_MESSAGE,
              hostSessionId: HOST_SESSION_ID,
              openInNewTab: false,
              url: link.href,
            },
            ORIGIN,
          );
        };
        expectDelegation();
        vi.advanceTimersByTime(300_000);
        expect(element.hasAttribute('expired')).toBe(true);
        expectDelegation();

        element.remove();
        postMessage.mockClear();
        establishHostSession(true);
        link.click();
        expect(postMessage).not.toHaveBeenCalled();
      } finally {
        if (originalTop) {
          Object.defineProperty(window, 'top', originalTop);
        }
      }
    },
  );

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

  it('tracks path changes and retains request-local preview selection on renewal', () => {
    const initialPath = '/initial?_canvas_excludeAutoSave=true';
    window.history.replaceState(null, '', initialPath);
    const { element, snapshots, renewLink } = mount({
      initialExpired: true,
      renewUrl: 'https://drupal.example/canvas-headless/renew',
    });
    expect(snapshots.at(-1)).toMatchObject({ path: initialPath });
    expect(new URL(renewLink.href).searchParams.get('path')).toBe(initialPath);

    element.setAttribute('path', '/node/7?_canvas_excludeAutoSave=true');

    expect(snapshots.at(-1)).toMatchObject({
      path: '/node/7?_canvas_excludeAutoSave=true',
    });
    expect(renewLink.getAttribute('href')).toBe(
      'https://drupal.example/canvas-headless/renew?path=%2Fnode%2F7%3F_canvas_excludeAutoSave%3Dtrue',
    );

    element.removeAttribute('path');
    expect(snapshots.at(-1)).toMatchObject({ path: initialPath });
    expect(new URL(renewLink.href).searchParams.get('path')).toBe(initialPath);
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
      establishHostSession();
      let refreshEvent: Event | null = null;
      element.addEventListener(DRAFT_SESSION_REFRESH_EVENT, (event) => {
        refreshEvent = event;
        event.preventDefault();
      });

      window.dispatchEvent(
        new MessageEvent('message', {
          data: {
            type: HEADLESS_REFRESH_MESSAGE,
            hostSessionId: HOST_SESSION_ID,
          },
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
      establishHostSession();

      window.dispatchEvent(
        new MessageEvent('message', {
          data: {
            type: HEADLESS_ASSERTION_MESSAGE,
            assertion: 'jwt-string',
            hostSessionId: HOST_SESSION_ID,
          },
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
