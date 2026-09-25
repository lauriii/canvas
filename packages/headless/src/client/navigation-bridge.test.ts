// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  HEADLESS_NAVIGATION_MESSAGE,
  HEADLESS_NAVIGATION_READY_MESSAGE,
  HEADLESS_STATUS_REQUEST_MESSAGE,
} from '../constants';
import { createNavigationBridge } from './navigation-bridge';

import type {
  NavigationBridge,
  NavigationBridgeOptions,
} from './navigation-bridge';

const HOST_ORIGIN = 'https://drupal.example';
const HOST_SESSION_ID = 'host-session';
const bridges: NavigationBridge[] = [];

beforeEach(() => {
  vi.stubGlobal(
    'navigator',
    Object.assign(Object.create(window.navigator), {
      userActivation: { isActive: true },
    }),
  );
});

function makeHarness(overrides: Partial<NavigationBridgeOptions> = {}) {
  const hostWindow = { postMessage: vi.fn() };
  const bridge = createNavigationBridge({
    embedded: true,
    root: document,
    hostWindow,
    listenerTarget: window,
    ...overrides,
  });
  bridges.push(bridge);
  const initialMessages = [...hostWindow.postMessage.mock.calls];
  hostWindow.postMessage.mockClear();

  const send = (
    data: Record<string, unknown>,
    event: Partial<MessageEventInit> = {},
  ) => {
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: HOST_ORIGIN,
        source: hostWindow as unknown as MessageEventSource,
        data,
        ...event,
      }),
    );
  };
  const handshake = (navigation: unknown = true) => {
    send({
      type: HEADLESS_STATUS_REQUEST_MESSAGE,
      hostSessionId: HOST_SESSION_ID,
      navigation,
    });
  };
  const click = (target: Element, init: MouseEventInit = {}): boolean => {
    const event = new MouseEvent('click', {
      bubbles: true,
      cancelable: true,
      button: 0,
      ...init,
    });
    let bridgePrevented = false;
    window.addEventListener(
      'click',
      (clickEvent) => {
        bridgePrevented = clickEvent.defaultPrevented;
        // Keep jsdom from performing browser-owned navigation when the bridge
        // leaves a click alone.
        clickEvent.preventDefault();
      },
      { once: true },
    );
    target.dispatchEvent(event);
    return bridgePrevented;
  };

  return { bridge, click, handshake, hostWindow, initialMessages, send };
}

function addAnchor(href: string): HTMLAnchorElement {
  const anchor = document.createElement('a');
  anchor.href = href;
  anchor.innerHTML = '<span>Follow link</span>';
  document.body.appendChild(anchor);
  return anchor;
}

afterEach(() => {
  for (const bridge of bridges.splice(0)) {
    bridge.destroy();
  }
  document.head.querySelectorAll('base').forEach((base) => base.remove());
  document.body.replaceChildren();
  window.history.replaceState(null, '', '/');
  vi.unstubAllGlobals();
});

describe('createNavigationBridge', () => {
  it('announces when it is ready for the host handshake', () => {
    const { initialMessages } = makeHarness();

    expect(initialMessages).toEqual([
      [{ type: HEADLESS_NAVIGATION_READY_MESSAGE }, '*'],
    ]);
  });

  it('delegates an HTTP link to a capable host using its absolute URL', () => {
    window.history.replaceState(null, '', '/current');
    const { hostWindow, handshake, click } = makeHarness();
    handshake();
    const anchor = addAnchor('/articles?tag=news#comments');

    expect(click(anchor.querySelector('span')!)).toBe(true);
    expect(hostWindow.postMessage).toHaveBeenCalledExactlyOnceWith(
      {
        type: HEADLESS_NAVIGATION_MESSAGE,
        hostSessionId: HOST_SESSION_ID,
        openInNewTab: false,
        url: 'http://localhost:3000/articles?tag=news#comments',
      },
      HOST_ORIGIN,
    );
  });

  it('delegates cross-origin HTTP links to the host', () => {
    const { hostWindow, handshake, click } = makeHarness();
    handshake();
    hostWindow.postMessage.mockClear();

    expect(click(addAnchor('https://external.example/article#details'))).toBe(
      true,
    );
    expect(hostWindow.postMessage).toHaveBeenCalledExactlyOnceWith(
      {
        type: HEADLESS_NAVIGATION_MESSAGE,
        hostSessionId: HOST_SESSION_ID,
        openInNewTab: false,
        url: 'https://external.example/article#details',
      },
      HOST_ORIGIN,
    );
  });

  it('does not intercept when the navigation capability is omitted', () => {
    const { hostWindow, send, click } = makeHarness();
    send({
      type: HEADLESS_STATUS_REQUEST_MESSAGE,
      hostSessionId: HOST_SESSION_ID,
    });

    expect(click(addAnchor('/articles'))).toBe(false);
    expect(hostWindow.postMessage).not.toHaveBeenCalled();
  });

  it.each([
    ['is false', false],
    ['is not the boolean true', 'true'],
  ])(
    'does not intercept when the navigation capability %s',
    (_label, navigation) => {
      const { hostWindow, handshake, click } = makeHarness();
      handshake(navigation);

      expect(click(addAnchor('/articles'))).toBe(false);
      expect(hostWindow.postMessage).not.toHaveBeenCalled();
    },
  );

  it('accepts the capability only from a valid parent handshake', () => {
    const { hostWindow, send, click } = makeHarness();
    send(
      {
        type: HEADLESS_STATUS_REQUEST_MESSAGE,
        hostSessionId: HOST_SESSION_ID,
        navigation: true,
      },
      { origin: 'file://' },
    );
    send(
      {
        type: HEADLESS_STATUS_REQUEST_MESSAGE,
        hostSessionId: HOST_SESSION_ID,
        navigation: true,
      },
      { source: { postMessage: vi.fn() } as unknown as MessageEventSource },
    );

    expect(click(addAnchor('/articles'))).toBe(false);
    expect(hostWindow.postMessage).not.toHaveBeenCalled();
  });

  it.each([
    ['a secondary click', '/next', { button: 1 }, {}],
    ['a modified click', '/next', { metaKey: true }, {}],
    ['a download', '/download', {}, { download: '' }],
    ['a non-HTTP URL', 'mailto:test@example.com', {}, {}],
  ])('leaves %s to the browser', (_label, href, eventInit, attributes) => {
    const { hostWindow, handshake, click } = makeHarness();
    handshake();
    hostWindow.postMessage.mockClear();
    const anchor = addAnchor(href);
    Object.entries(attributes).forEach(([name, value]) => {
      anchor.setAttribute(name, value);
    });

    expect(click(anchor, eventInit)).toBe(false);
    expect(hostWindow.postMessage).not.toHaveBeenCalled();
  });

  it.each([
    ['_blank', true],
    ['_BLANK', true],
    ['_parent', false],
    ['_top', false],
    ['named-frame', false],
  ])('delegates a %s target to the host', (target, openInNewTab) => {
    const { hostWindow, handshake, click } = makeHarness();
    handshake();
    hostWindow.postMessage.mockClear();
    const anchor = addAnchor('/next');
    anchor.target = target;

    expect(click(anchor)).toBe(true);
    expect(hostWindow.postMessage).toHaveBeenCalledExactlyOnceWith(
      expect.objectContaining({
        type: HEADLESS_NAVIGATION_MESSAGE,
        openInNewTab,
        url: 'http://localhost:3000/next',
      }),
      HOST_ORIGIN,
    );
  });

  it('delegates even when the click was already prevented', () => {
    const { hostWindow, handshake } = makeHarness();
    handshake();
    hostWindow.postMessage.mockClear();
    const anchor = addAnchor('/next');
    const event = new MouseEvent('click', {
      bubbles: true,
      cancelable: true,
      button: 0,
    });
    event.preventDefault();
    anchor.dispatchEvent(event);

    expect(hostWindow.postMessage).toHaveBeenCalledOnce();
  });

  it('runs application click handlers while taking over navigation', () => {
    const { hostWindow, handshake, click } = makeHarness();
    handshake();
    const anchor = addAnchor('/next');
    const applicationHandler = vi.fn((event: Event) => event.preventDefault());
    anchor.addEventListener('click', applicationHandler);

    click(anchor);

    expect(applicationHandler).toHaveBeenCalledOnce();
    expect(hostWindow.postMessage).toHaveBeenCalledOnce();
  });

  it('takes over clicks canceled by document handlers', () => {
    const { hostWindow, handshake, click } = makeHarness();
    handshake();
    document.addEventListener('click', (event) => event.preventDefault(), {
      once: true,
    });

    click(addAnchor('/next'));

    expect(hostWindow.postMessage).toHaveBeenCalledOnce();
  });

  it('cancels the default before router handlers while allowing application handlers', () => {
    const { hostWindow, handshake, click } = makeHarness();
    handshake();
    const anchor = addAnchor('/next');
    const startRouter = vi.fn();
    const applicationHandler = vi.fn((event: Event) => {
      if (!event.defaultPrevented) {
        startRouter();
      }
      event.preventDefault();
    });
    anchor.addEventListener('click', applicationHandler);

    expect(click(anchor)).toBe(true);

    expect(applicationHandler).toHaveBeenCalledOnce();
    expect(startRouter).not.toHaveBeenCalled();
    expect(hostWindow.postMessage).toHaveBeenCalledExactlyOnceWith(
      expect.objectContaining({
        type: HEADLESS_NAVIGATION_MESSAGE,
        url: 'http://localhost:3000/next',
      }),
      HOST_ORIGIN,
    );
  });

  it('reports the click before an application stops propagation', () => {
    const { hostWindow, handshake } = makeHarness();
    handshake();
    const anchor = addAnchor('/next');
    anchor.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
    });

    anchor.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    );

    expect(hostWindow.postMessage).toHaveBeenCalledOnce();
  });

  it('leaves navigation alone without user activation', () => {
    const { hostWindow, handshake, click } = makeHarness();
    handshake();
    Object.assign(window.navigator.userActivation, { isActive: false });

    expect(click(addAnchor('/next'))).toBe(false);
    expect(hostWindow.postMessage).not.toHaveBeenCalled();
  });

  it('leaves standalone applications alone', () => {
    const { hostWindow, handshake, click, initialMessages } = makeHarness({
      embedded: false,
    });
    handshake();

    expect(click(addAnchor('/next'))).toBe(false);
    expect(initialMessages).toEqual([]);
    expect(hostWindow.postMessage).not.toHaveBeenCalled();
  });

  it('respects the document base URL and inherited new-tab target', () => {
    const base = document.createElement('base');
    base.href = 'https://frontend.example/app/';
    const baseTarget = document.createElement('base');
    baseTarget.target = '_blank';
    document.head.append(base, baseTarget);
    const { hostWindow, handshake, click } = makeHarness();
    handshake();

    expect(click(addAnchor('next?tag=news#details'))).toBe(true);
    expect(hostWindow.postMessage).toHaveBeenCalledExactlyOnceWith(
      expect.objectContaining({
        url: 'https://frontend.example/app/next?tag=news#details',
        openInNewTab: true,
      }),
      HOST_ORIGIN,
    );
  });

  it('delegates only once when bridges overlap during an application transition', () => {
    const first = makeHarness();
    first.handshake();
    const second = makeHarness();
    second.handshake();

    expect(first.click(addAnchor('/next'))).toBe(true);
    expect(first.hostWindow.postMessage).toHaveBeenCalledOnce();
    expect(second.hostWindow.postMessage).not.toHaveBeenCalled();

    first.bridge.destroy();

    expect(second.click(addAnchor('/another'))).toBe(true);
    expect(second.hostWindow.postMessage).toHaveBeenCalledOnce();
  });

  it('stops intercepting when the host withdraws the capability', () => {
    const { hostWindow, send, handshake, click } = makeHarness();
    handshake();
    send({
      type: HEADLESS_STATUS_REQUEST_MESSAGE,
      hostSessionId: 'replacement-session',
      navigation: false,
    });
    hostWindow.postMessage.mockClear();

    expect(click(addAnchor('/next'))).toBe(false);
    expect(hostWindow.postMessage).not.toHaveBeenCalled();
  });

  it('stops intercepting after destroy', () => {
    const { bridge, hostWindow, handshake, click } = makeHarness();
    handshake();
    bridge.destroy();
    hostWindow.postMessage.mockClear();

    expect(click(addAnchor('/next'))).toBe(false);
    expect(hostWindow.postMessage).not.toHaveBeenCalled();
  });
});
