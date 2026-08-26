// The directive below must survive any future compiled build of this
// package: without it, React Server Component bundlers treat this module
// as server code and every consumer build breaks.
'use client';

import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import {
  createCanvasGeometryBridge,
  createDraftSession,
  createHeightReporter,
} from '@drupal-canvas/headless/client';

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
  /** The signed editor origin used as the postMessage peer. */
  editorOrigin: string | null;
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
   * Refreshes the consumer's server-derived data after a successful renewal
   * or a Canvas auto-save change (Next.js: router.refresh()). Refreshed
   * renewal data carries the new tokenExpiresAt as new props, which re-arm
   * the machine. Without it, renewal re-arms in place from the response's
   * tokenExpiresAt, while an auto-save change reloads the current document.
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
 *
 * Alongside the session machine, this component also runs a content-height
 * reporter (see @drupal-canvas/headless/client's createHeightReporter).
 */
export function DraftSession({
  tokenExpiresAt,
  initialExpired,
  renewUrl,
  editorOrigin,
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
      editorOrigin,
      renewEndpoint,
      onEvent: (event) => {
        if (event.type === 'refresh-requested') {
          const refresh = refreshDataRef.current;
          if (refresh) {
            refresh();
          } else {
            window.location.reload();
          }
          return;
        }
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
    editorOrigin,
    renewEndpoint,
  ]);

  // An independent, one-way signal alongside the session machine above: the
  // host sizes the preview iframe to fit this app's rendered content.
  useEffect(() => {
    if (embedded === null) {
      return;
    }
    const reporter = createHeightReporter({ editorOrigin, embedded });
    return () => {
      reporter.destroy();
    };
  }, [embedded, editorOrigin]);

  useEffect(() => {
    if (embedded !== true || !editorOrigin) {
      return;
    }
    const bridge = createCanvasGeometryBridge({ editorOrigin });
    return () => bridge.destroy();
  }, [editorOrigin, embedded]);

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
