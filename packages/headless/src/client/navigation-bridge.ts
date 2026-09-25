/**
 * @file
 * Delegates link navigation from an embedded frontend to its Drupal host.
 * The bridge starts intercepting links only after the parent advertises the
 * navigation capability. Capture-phase handling gives the host ownership of
 * eligible clicks even when application or router handlers cancel navigation.
 *
 * The bridge binds to the parent origin and host session ID from a valid
 * status request. The frontend's frame-ancestors policy restricts which
 * origins may embed it. Every later request must match that parent and origin.
 */

import {
  HEADLESS_NAVIGATION_MESSAGE,
  HEADLESS_NAVIGATION_READY_MESSAGE,
  HEADLESS_STATUS_REQUEST_MESSAGE,
} from '../constants';

export interface NavigationBridgeOptions {
  /** Whether this document is embedded in an iframe. */
  embedded: boolean;
  /** The document whose link clicks are intercepted. Default: document. */
  root?: Document;
  /** The parent window used for the protocol. Default: window.parent. */
  hostWindow?: Pick<Window, 'postMessage'>;
  /** The window that receives host messages. Default: window. */
  listenerTarget?: Pick<Window, 'addEventListener' | 'removeEventListener'>;
}

export interface NavigationBridge {
  /** Removes the click and message listeners. Safe to call more than once. */
  destroy(): void;
}

// Overlapping DraftSession mounts must not report the same click twice. A
// WeakSet deduplicates without stopping event propagation or retaining events.
const delegatedClicks = new WeakSet<MouseEvent>();

function isHttpOrigin(origin: string): boolean {
  try {
    const url = new URL(origin);
    return (
      (url.protocol === 'http:' || url.protocol === 'https:') &&
      url.origin === origin
    );
  } catch {
    return false;
  }
}

function findAnchor(event: MouseEvent): HTMLAnchorElement | null {
  for (const target of event.composedPath()) {
    if (target instanceof HTMLAnchorElement) {
      return target;
    }
  }
  return event.target instanceof Element
    ? event.target.closest<HTMLAnchorElement>('a[href]')
    : null;
}

/** Starts the app side of iframe link delegation. */
export function createNavigationBridge(
  options: NavigationBridgeOptions,
): NavigationBridge {
  const {
    embedded,
    root = typeof document === 'undefined' ? undefined : document,
    hostWindow = typeof window === 'undefined' ? undefined : window.parent,
    listenerTarget = typeof window === 'undefined' ? undefined : window,
  } = options;

  if (!embedded || !root || !hostWindow || !listenerTarget) {
    return { destroy: () => {} };
  }

  let destroyed = false;
  let hostOrigin: string | null = null;
  let hostSessionId: string | null = null;
  let navigationEnabled = false;
  const clickTarget = root.defaultView ?? root;

  const isNavigationEnabled = (): boolean =>
    !destroyed &&
    navigationEnabled &&
    hostOrigin !== null &&
    hostSessionId !== null &&
    root.defaultView?.navigator.userActivation?.isActive === true;

  const delegate = (destination: string, openInNewTab: boolean): boolean => {
    if (
      !isNavigationEnabled() ||
      hostOrigin === null ||
      hostSessionId === null
    ) {
      return false;
    }

    let url: URL;
    try {
      url = new URL(destination, root.baseURI);
    } catch {
      return false;
    }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
      return false;
    }

    hostWindow.postMessage(
      {
        type: HEADLESS_NAVIGATION_MESSAGE,
        hostSessionId,
        openInNewTab,
        url: url.href,
      },
      hostOrigin,
    );
    return true;
  };

  const onClick = (event: MouseEvent) => {
    if (
      delegatedClicks.has(event) ||
      event.button !== 0 ||
      event.altKey ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey
    ) {
      return;
    }

    const anchor = findAnchor(event);
    if (
      anchor?.hasAttribute('href') &&
      !anchor.hasAttribute('download') &&
      delegate(
        anchor.href,
        (
          anchor.target ||
          root.querySelector<HTMLBaseElement>('base[target]')?.target ||
          ''
        ).toLowerCase() === '_blank',
      )
    ) {
      delegatedClicks.add(event);
      event.preventDefault();
    }
  };

  const onMessage = (event: MessageEvent) => {
    if (
      destroyed ||
      event.source !== hostWindow ||
      !event.data ||
      event.data.type !== HEADLESS_STATUS_REQUEST_MESSAGE ||
      !isHttpOrigin(event.origin) ||
      (hostOrigin !== null && event.origin !== hostOrigin) ||
      typeof event.data.hostSessionId !== 'string' ||
      event.data.hostSessionId === ''
    ) {
      return;
    }

    hostOrigin = event.origin;
    hostSessionId = event.data.hostSessionId;
    navigationEnabled = event.data.navigation === true;
  };

  // Preview navigation belongs to the host, regardless of preventDefault().
  // Cancel the browser default before ordinary router handlers start routing,
  // but leave event propagation intact. Routers that ignore cancellation
  // may still navigate the iframe while the host resolves the destination.
  clickTarget.addEventListener('click', onClick as EventListener, true);
  listenerTarget.addEventListener('message', onMessage as EventListener);
  // The bridge can mount after the iframe's load event, especially when a
  // framework hydrates on the client. It does not know the parent origin yet,
  // so this capability-free bootstrap message uses a wildcard target. The
  // host validates both the sender window and the frontend origin before it
  // repeats the origin-addressed status handshake.
  hostWindow.postMessage({ type: HEADLESS_NAVIGATION_READY_MESSAGE }, '*');

  return {
    destroy: () => {
      if (destroyed) {
        return;
      }
      destroyed = true;
      clickTarget.removeEventListener('click', onClick as EventListener, true);
      listenerTarget.removeEventListener('message', onMessage as EventListener);
    },
  };
}
