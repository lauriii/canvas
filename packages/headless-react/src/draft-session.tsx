'use client';

import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { createDraftSession } from '@drupal-canvas/headless/client';

import type { ReactNode } from 'react';
import type {
  DraftSession as DraftSessionMachine,
  DraftSessionRenewState,
} from '@drupal-canvas/headless/client';

const noopSubscribe = () => () => {};

/**
 * Whether this document is embedded in an iframe. The server cannot know
 * (null there), so the first client render decides — this is the one value
 * the whole banner-vs-host-messaging split hangs on.
 */
function useEmbedded(): boolean | null {
  return useSyncExternalStore(
    noopSubscribe,
    () => window.self !== window.top,
    () => null,
  );
}

/**
 * What the render prop receives: everything a session banner needs.
 */
export interface DraftSessionSnapshot {
  embedded: boolean;
  expired: boolean;
  renewState: DraftSessionRenewState;
  /** The current app path (for the renew link's ?path= parameter). */
  path: string;
  renewUrl: string | null;
}

export interface DraftSessionProps {
  /** Epoch ms when the session token dies; null when the cookie is gone. */
  tokenExpiresAt: number | null;
  /** Server-computed expiry state, so first paint matches the server. */
  initialExpired: boolean;
  /**
   * Drupal's renew route (absolute, browser-facing — a signed assertion
   * claim, not configuration). Null when the session cookie is gone.
   * Passed through to the render prop; the machine itself never uses it.
   */
  renewUrl: string | null;
  /** Origins allowed to embed this app (postMessage peers). */
  embedderOrigins: string[];
  /** The app endpoint that redeems a fresh assertion. */
  renewEndpoint?: string;
  /**
   * The current app path, reported to the host and carried by the renew
   * link. Framework wrappers bind their router's pathname; without it the
   * document's location at machine creation is used, which is correct only
   * until a client-side navigation.
   */
  path?: string;
  /**
   * Refreshes the consumer's server-derived data after a successful
   * renewal (Next.js: router.refresh()); the refreshed data carries the
   * new tokenExpiresAt as new props, which re-arm the machine. Omit it and
   * the component re-arms in place from the renew response's own
   * tokenExpiresAt instead — the renewed token already lives in the
   * session cookie, so no server round trip is required.
   */
  refreshData?: () => void;
  /**
   * Owns all presentation. Called only client-side, once embedding is
   * known; nothing renders without it — a headless app that only needs the
   * renewal protocol omits it.
   */
  children?: (snapshot: DraftSessionSnapshot) => ReactNode;
}

/**
 * The React lifecycle around the draft session state machine (see
 * @drupal-canvas/headless/client): creates a machine per session epoch,
 * relays its renewal protocol, and hands session state to the render prop.
 * Framework packages wrap it with their router wiring
 * (@drupal-canvas/headless-next, @drupal-canvas/headless-tanstack-start);
 * this component itself has no framework dependency beyond React.
 *
 * A renewed session arrives one of two ways. With refreshData, as new
 * props: the renew response set a new cookie, the refresh re-rendered the
 * server data, and the changed tokenExpiresAt re-runs the machine effect —
 * destroy, recreate, re-arm. Without it, in place: the 'renewed' event
 * carries the new expiry, which becomes the internal epoch until the next
 * server-provided props arrive.
 */
export function DraftSession({
  tokenExpiresAt,
  initialExpired,
  renewUrl,
  embedderOrigins,
  renewEndpoint,
  path,
  refreshData,
  children,
}: DraftSessionProps): ReactNode {
  const embedded = useEmbedded();
  const [state, setState] = useState({
    expired: initialExpired,
    renewState: 'idle' as DraftSessionRenewState,
  });

  // The in-place epoch, set by a renewal when no refreshData is wired.
  // Server-provided props always win: any change to them resets it.
  const [renewedEpoch, setRenewedEpoch] = useState<number | null>(null);
  useEffect(() => {
    setRenewedEpoch(null);
  }, [tokenExpiresAt, initialExpired]);
  const effectiveExpiresAt = renewedEpoch ?? tokenExpiresAt;
  const effectiveInitialExpired =
    renewedEpoch === null ? initialExpired : false;

  const sessionRef = useRef<DraftSessionMachine | null>(null);
  // Path travels through refs, not effect dependencies: a navigation must
  // update the running machine via setPath(), never destroy and re-create
  // it (that would drop timers and re-run the renewal schedule).
  const pathRef = useRef(path ?? '/');
  const hasPathPropRef = useRef(path !== undefined);
  hasPathPropRef.current = path !== undefined;

  // Kept out of the machine effect's dependencies: a wrapper passing an
  // inline closure (router.refresh) must not re-create the machine every
  // render.
  const refreshDataRef = useRef(refreshData);
  refreshDataRef.current = refreshData;

  // Declared before the machine effect so pathRef is current when a
  // machine is (re)created below; on later navigations the machine is told
  // directly.
  useEffect(() => {
    if (path !== undefined) {
      pathRef.current = path;
      sessionRef.current?.setPath(path);
    }
  }, [path]);

  // Stable identity for the effect dependencies (the array prop is fresh
  // each render).
  const originsKey = embedderOrigins.join(' ');

  useEffect(() => {
    if (embedded === null) {
      return;
    }
    if (!hasPathPropRef.current) {
      pathRef.current = window.location.pathname;
    }
    const session = createDraftSession({
      tokenExpiresAt: effectiveExpiresAt,
      initialExpired: effectiveInitialExpired,
      embedded,
      path: pathRef.current,
      embedderOrigins: originsKey.split(' ').filter(Boolean),
      renewEndpoint,
      onEvent: (event) => {
        if (event.type === 'renewed') {
          const refresh = refreshDataRef.current;
          if (refresh) {
            refresh();
          } else if (event.tokenExpiresAt !== null) {
            setRenewedEpoch(event.tokenExpiresAt);
          } else {
            // Renewed (the cookie holds the new token) but the response
            // stated no expiry and there is nothing to refresh with —
            // resync with the server-rendered state the coarse way.
            window.location.reload();
          }
          return;
        }
        setState(session.getState());
      },
    });
    sessionRef.current = session;
    setState(session.getState());
    return () => {
      session.destroy();
      sessionRef.current = null;
    };
  }, [
    embedded,
    effectiveExpiresAt,
    effectiveInitialExpired,
    originsKey,
    renewEndpoint,
  ]);

  if (embedded === null || !children) {
    return null;
  }

  return children({
    embedded,
    expired: state.expired,
    renewState: state.renewState,
    path: path ?? pathRef.current,
    renewUrl,
  });
}
