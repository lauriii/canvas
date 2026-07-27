/**
 * @file
 * Host-side implementation of the Canvas headless draft-preview protocol.
 *
 * The embedding host page (the Canvas editor, or any other application that
 * embeds a Canvas headless frontend app) runs inside the editor's
 * authenticated Drupal session — the one context that can mint preview
 * assertions. The embedded app cannot reach that session itself: its
 * requests are cross-site in the ancestor chain, so Drupal's SameSite=Lax
 * session cookie never accompanies them. This module relays for the app
 * over postMessage:
 *
 *   host → app   {type: 'canvas-headless:status-request', hostSessionId}
 *   app  → host  {type: 'canvas-headless:status', hostSessionId, status, path, tokenExpiresAt}
 *   app  → host  {type: 'canvas-headless:renew-request', hostSessionId, path}
 *   host → app   {type: 'canvas-headless:assertion', hostSessionId, assertion}
 *   host → app   {type: 'canvas-headless:refresh', hostSessionId, refreshId}
 *   app  → host  {type: 'canvas-headless:refresh-ack', hostSessionId, refreshId}
 *   app  → host  {type: 'canvas-headless:height', hostSessionId, height}
 *   app  → host  {type: 'canvas-headless:height-probe', hostSessionId, id, height}
 *   host → app   {type: 'canvas-headless:height-probe-ready', hostSessionId, id, height}
 *   host → app   {type: 'canvas-headless:viewport-height', hostSessionId, height}
 *   host → app   {type: 'canvas-headless:geometry-request', hostSessionId}
 *   app  → host  {type: 'canvas-headless:geometry', hostSessionId, geometry}
 *
 * On a renew request the host fetches a fresh assertion (via the
 * `fetchAssertion` callback, which owns transport specifics such as CSRF)
 * and posts it into the iframe; the app redeems it in place — no document
 * reload. Renewal-lane assertions are minted with a renewal flag: Drupal
 * only redeems them together with PKCE proof held by the app's server, so
 * a script running inside the iframe cannot exchange an intercepted
 * assertion for a token. A recovery lane backs the renewal lane: if the app still reports
 * an expired session, the host mints a whole activation URL and resets the
 * iframe src — a full reload, coarse but dependable. One recovery attempt
 * per expiry; the flag re-arms only when the app reports an active session
 * again, so a session that cannot recover does not reload in a loop.
 * Separately, refresh() tells the app that its Canvas auto-save data changed,
 * allowing the app's framework adapter to refresh without replaying the
 * single-use activation URL. Numbered commands are queued until the app
 * acknowledges receipt. Refreshes requested while the app is loading are
 * delivered once it reports an active session, and an unacknowledged command
 * is re-sent if the app-side session machine is recreated.
 *
 * Every message is origin-checked in both directions: incoming events must
 * come from the configured frontend origin and from the host's own iframe;
 * outgoing messages are addressed to that origin, never '*'. Each loaded
 * iframe document also gets a new host session ID, so messages queued by the
 * previous document cannot affect its replacement.
 *
 * Height reporting is independent of the session lifecycle above. The app
 * reports its final height on load and after layout changes. It may also ask
 * the host to apply temporary iframe heights while it checks whether content
 * is viewport-relative.
 */

import {
  HEADLESS_ASSERTION_MESSAGE,
  HEADLESS_GEOMETRY_MESSAGE,
  HEADLESS_GEOMETRY_REQUEST_MESSAGE,
  HEADLESS_HEIGHT_MESSAGE,
  HEADLESS_HEIGHT_PROBE_MESSAGE,
  HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
  HEADLESS_REFRESH_ACK_MESSAGE,
  HEADLESS_REFRESH_MESSAGE,
  HEADLESS_RENEW_REQUEST_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
  HEADLESS_STATUS_REQUEST_MESSAGE,
  HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
  isCanvasGeometrySnapshot,
} from '@drupal-canvas/headless';

import type { CanvasGeometry } from '@drupal-canvas/headless';

// The protocol message types are declared once, in @drupal-canvas/headless
// (whose client entry implements the app side); re-exported here so host
// implementers need only this package.
export {
  HEADLESS_ASSERTION_MESSAGE,
  HEADLESS_GEOMETRY_MESSAGE,
  HEADLESS_GEOMETRY_REQUEST_MESSAGE,
  HEADLESS_HEIGHT_MESSAGE,
  HEADLESS_HEIGHT_PROBE_MESSAGE,
  HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
  HEADLESS_REFRESH_ACK_MESSAGE,
  HEADLESS_REFRESH_MESSAGE,
  HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
  HEADLESS_RENEW_REQUEST_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
  HEADLESS_STATUS_REQUEST_MESSAGE,
};

/**
 * Session lifecycle events the host reports to its consumer.
 *
 * The consumer owns presentation: this package emits structured events and
 * never renders text.
 */
export type HeadlessPreviewHostEvent =
  | { type: 'active'; tokenExpiresAt: number }
  | { type: 'activation-failed' }
  | { type: 'renewing' }
  | { type: 'renew-failed' }
  | { type: 'recovering' }
  | { type: 'recovery-failed' }
  | { type: 'geometry'; geometry: CanvasGeometry[] };

export interface HeadlessPreviewHostOptions {
  /** The iframe the frontend app is embedded in. */
  iframe: HTMLIFrameElement;
  /**
   * The app's origin. Incoming messages are validated against it, and
   * outgoing messages are addressed to it.
   */
  frontendOrigin: string;
  /**
   * The app's draft-mode activation endpoint. An `assertion` query
   * parameter is appended to form the iframe URL.
   */
  draftUrl: string;
  /**
   * Mints a preview assertion from the host's Drupal session. The params
   * identify the session entry point — for the Canvas editor either
   * `{entity_type, entity}` (activation) or `{path}` (renewal/recovery).
   * Transport specifics (endpoint URL, CSRF) belong to the implementer.
   */
  fetchAssertion: (params: Record<string, string>) => Promise<string>;
  /** Receives session lifecycle events. */
  onEvent?: (event: HeadlessPreviewHostEvent) => void;
  /** Receives rendered content-height reports from the embedded app. */
  onHeight?: (height: number) => void;
}

export interface HeadlessPreviewHost {
  /**
   * Starts (or restarts) a draft session: mints an assertion for the given
   * params and loads the app's activation URL in the iframe. Emits
   * 'activation-failed' instead of rejecting.
   */
  activate: (params: Record<string, string>) => Promise<void>;
  /** Asks the embedded app to refresh now, or once its session is active. */
  refresh: () => void;
  /** Updates the base height of the preview's selected device viewport. */
  setViewportHeight: (height: number) => void;
  /** Removes the message listener. The iframe itself is left as is. */
  destroy: () => void;
}

/**
 * Creates the host side of the draft-preview protocol for one iframe.
 */
export function createHeadlessPreviewHost(
  options: HeadlessPreviewHostOptions,
): HeadlessPreviewHost {
  const {
    iframe,
    frontendOrigin,
    draftUrl,
    fetchAssertion,
    onEvent,
    onHeight,
  } = options;
  let recoveryAttempted = false;
  let active = false;
  let refreshPending = false;
  let refreshInFlight: number | null = null;
  let nextRefreshId = 1;
  let viewportHeight: number | null = null;
  let loadGeneration = 0;
  let canHandshakeOnLoad = false;
  let hostSessionId: string | null = null;
  let destroyed = false;
  let probeFrame: number | null = null;
  let probeStyleSnapshot: { height: string; visibility: string } | null = null;
  let probeAppliedStyles: { height: string; visibility: string } | null = null;

  const emit = (event: HeadlessPreviewHostEvent) => {
    if (!destroyed) {
      onEvent?.(event);
    }
  };

  // Mint an assertion for the given entry point and (re)load the app's
  // activation URL. Both the initial activation and the recovery lane load
  // the app the same way; they differ only in their guard and which failure
  // event they emit.
  const loadApp = async (params: Record<string, string>) => {
    const generation = ++loadGeneration;
    active = false;
    canHandshakeOnLoad = false;
    hostSessionId = null;
    emit({ type: 'geometry', geometry: [] });
    const assertion = await fetchAssertion(params);
    // A slower activation or recovery must not overwrite a newer navigation.
    if (destroyed || generation !== loadGeneration) {
      return;
    }
    canHandshakeOnLoad = true;
    iframe.src = `${draftUrl}?assertion=${encodeURIComponent(assertion)}`;
  };

  const postRefresh = (refreshId: number) => {
    if (!hostSessionId) {
      return;
    }
    iframe.contentWindow?.postMessage(
      { type: HEADLESS_REFRESH_MESSAGE, refreshId, hostSessionId },
      frontendOrigin,
    );
  };

  const postViewportHeight = () => {
    if (viewportHeight === null || hostSessionId === null) {
      return;
    }
    iframe.contentWindow?.postMessage(
      {
        type: HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
        hostSessionId,
        height: viewportHeight,
      },
      frontendOrigin,
    );
  };

  const postProbeReady = (id: string, height: number | null) => {
    const probeSessionId = hostSessionId;
    if (probeSessionId === null) {
      return;
    }
    probeFrame = window.requestAnimationFrame(() => {
      probeFrame = null;
      if (probeSessionId !== hostSessionId) {
        return;
      }
      iframe.contentWindow?.postMessage(
        {
          type: HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
          hostSessionId: probeSessionId,
          id,
          height,
        },
        frontendOrigin,
      );
    });
  };

  const preserveExternalProbeStyleChanges = () => {
    if (!probeStyleSnapshot || !probeAppliedStyles) {
      return;
    }

    // The embedding app may commit a final height while a probe is active.
    // Preserve that newer value instead of later restoring the stale height
    // captured at the start of the probe sequence.
    if (iframe.style.height !== probeAppliedStyles.height) {
      probeStyleSnapshot.height = iframe.style.height;
    }
    if (iframe.style.visibility !== probeAppliedStyles.visibility) {
      probeStyleSnapshot.visibility = iframe.style.visibility;
    }
  };

  const restoreProbeStyles = () => {
    if (!probeStyleSnapshot) {
      return;
    }
    preserveExternalProbeStyleChanges();
    iframe.style.height = probeStyleSnapshot.height;
    iframe.style.visibility = probeStyleSnapshot.visibility;
    probeStyleSnapshot = null;
    probeAppliedStyles = null;
  };

  const flushRefresh = () => {
    if (!active || !refreshPending || refreshInFlight !== null) {
      return;
    }
    refreshPending = false;
    refreshInFlight = nextRefreshId++;
    postRefresh(refreshInFlight);
  };

  const requestGeometry = () => {
    if (!hostSessionId) {
      return;
    }
    iframe.contentWindow?.postMessage(
      { type: HEADLESS_GEOMETRY_REQUEST_MESSAGE, hostSessionId },
      frontendOrigin,
    );
  };

  const requestStatus = () => {
    if (hostSessionId === null) {
      return;
    }
    iframe.contentWindow?.postMessage(
      { type: HEADLESS_STATUS_REQUEST_MESSAGE, hostSessionId },
      frontendOrigin,
    );
  };

  const onIframeLoad = () => {
    // A reload replaces the app document while preserving the iframe and host.
    // Start a new protocol session before accepting messages from that document.
    emit({ type: 'geometry', geometry: [] });
    active = false;
    if (!canHandshakeOnLoad) {
      return;
    }
    hostSessionId = window.crypto.randomUUID();
    requestStatus();
  };

  const activate = async (params: Record<string, string>) => {
    try {
      await loadApp(params);
    } catch {
      emit({ type: 'activation-failed' });
    }
  };

  const renew = async (path: string) => {
    const renewingSessionId = hostSessionId;
    emit({ type: 'renewing' });
    try {
      // The renewal flag marks this assertion as one that will transit the
      // iframe's script context (via postMessage below). Drupal's grant
      // only redeems such assertions with PKCE proof of the running app
      // session, which lives server-side in the app — a script that
      // intercepts the message cannot exchange the assertion for a token.
      const assertion = await fetchAssertion({ path, renewal: '1' });
      // Same post-destroy race as in loadApp: never message an iframe a
      // newer host owns.
      if (
        destroyed ||
        renewingSessionId === null ||
        renewingSessionId !== hostSessionId
      ) {
        return;
      }
      iframe.contentWindow?.postMessage(
        {
          type: HEADLESS_ASSERTION_MESSAGE,
          assertion,
          hostSessionId,
        },
        frontendOrigin,
      );
    } catch {
      // Most likely the Drupal session itself is gone — the one failure
      // renewal must not paper over.
      emit({ type: 'renew-failed' });
    }
  };

  const recover = async (path: string) => {
    if (recoveryAttempted) {
      return;
    }
    recoveryAttempted = true;
    emit({ type: 'recovering' });
    try {
      await loadApp({ path });
    } catch {
      emit({ type: 'recovery-failed' });
    }
  };

  const onMessage = (event: MessageEvent) => {
    if (
      event.origin !== frontendOrigin ||
      event.source !== iframe.contentWindow ||
      !event.data ||
      typeof event.data.type !== 'string'
    ) {
      return;
    }

    if (hostSessionId === null) {
      return;
    }
    if (event.data.hostSessionId !== hostSessionId) {
      // A framework data refresh can replace the app-side session machine
      // without replacing the iframe document. The new machine does not know
      // this document's ID yet, so repeat the origin-checked handshake.
      if (
        event.data.type === HEADLESS_STATUS_MESSAGE &&
        event.data.hostSessionId === undefined
      ) {
        requestStatus();
      }
      return;
    }

    const path =
      typeof event.data.path === 'string' && event.data.path.startsWith('/')
        ? event.data.path
        : '/';

    switch (event.data.type) {
      case HEADLESS_GEOMETRY_MESSAGE:
        if (active) {
          emit({
            type: 'geometry',
            geometry: isCanvasGeometrySnapshot(event.data.geometry)
              ? event.data.geometry
              : [],
          });
        }
        break;

      case HEADLESS_RENEW_REQUEST_MESSAGE:
        void renew(path);
        break;

      case HEADLESS_REFRESH_ACK_MESSAGE:
        if (
          typeof event.data.refreshId === 'number' &&
          event.data.refreshId === refreshInFlight
        ) {
          refreshInFlight = null;
          flushRefresh();
        }
        break;

      case HEADLESS_STATUS_MESSAGE: {
        if (event.data.status === 'active') {
          // A live session re-arms the recovery lane for the next expiry:
          // every recovery cycle passes through 'active', so a session
          // that never comes back cannot reload in a loop.
          recoveryAttempted = false;
          active = true;
          emit({
            type: 'active',
            tokenExpiresAt: Number(event.data.tokenExpiresAt),
          });
          postViewportHeight();
          requestGeometry();
          // A status report can come from a newly created app-side session
          // machine. Re-send an unacknowledged refresh that may have landed
          // while the old machine was being replaced.
          if (refreshInFlight !== null) {
            postRefresh(refreshInFlight);
          } else {
            flushRefresh();
          }
        }
        if (event.data.status === 'expired') {
          active = false;
          void recover(path);
        }
        break;
      }

      case HEADLESS_HEIGHT_MESSAGE: {
        if (!active || probeStyleSnapshot) {
          break;
        }
        const { height } = event.data;
        if (
          typeof height === 'number' &&
          Number.isFinite(height) &&
          height >= 0
        ) {
          onHeight?.(height);
        }
        break;
      }

      case HEADLESS_HEIGHT_PROBE_MESSAGE: {
        if (!active) {
          break;
        }
        const { height, id } = event.data;
        if (
          typeof id !== 'string' ||
          id.length === 0 ||
          (height !== null &&
            (typeof height !== 'number' ||
              !Number.isFinite(height) ||
              height <= 0))
        ) {
          break;
        }

        if (probeFrame !== null) {
          window.cancelAnimationFrame(probeFrame);
          probeFrame = null;
        }

        if (height === null) {
          restoreProbeStyles();
        } else {
          if (probeStyleSnapshot) {
            preserveExternalProbeStyleChanges();
          } else {
            probeStyleSnapshot = {
              height: iframe.style.height,
              visibility: iframe.style.visibility,
            };
          }
          iframe.style.height = `${height}px`;
          iframe.style.visibility = 'hidden';
          probeAppliedStyles = {
            height: iframe.style.height,
            visibility: iframe.style.visibility,
          };
        }

        postProbeReady(id, height);
        break;
      }
    }
  };

  iframe.addEventListener('load', onIframeLoad);
  window.addEventListener('message', onMessage);

  return {
    activate,
    refresh: () => {
      if (destroyed) {
        return;
      }
      refreshPending = true;
      flushRefresh();
    },
    setViewportHeight: (height) => {
      if (destroyed || !Number.isFinite(height) || height <= 0) {
        return;
      }
      viewportHeight = height;
      if (active) {
        postViewportHeight();
      }
    },
    destroy: () => {
      destroyed = true;
      loadGeneration += 1;
      if (probeFrame !== null) {
        window.cancelAnimationFrame(probeFrame);
      }
      restoreProbeStyles();
      iframe.removeEventListener('load', onIframeLoad);
      window.removeEventListener('message', onMessage);
    },
  };
}
