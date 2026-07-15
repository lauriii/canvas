/**
 * @file
 * The app's side of the draft session lifecycle, as a framework-free state
 * machine. The consumer (a React component, a Svelte store, plain DOM code)
 * owns presentation and data refreshing; this module owns timing, host
 * messaging, and renewal.
 *
 * Renewal is a division of labor: the app knows *when* the token dies
 * (tokenExpiresAt is right in the session cookie) but cannot mint a new
 * assertion — only the editor's Drupal session can, and the app's requests
 * never carry it. So, embedded, the app asks its host over postMessage
 * before expiry; the host answers with a fresh assertion, the app redeems
 * it at the renew endpoint (new token, same cookies), and the consumer's
 * refreshData() re-renders with draft data — no document reload, no
 * navigation loss. The editor never sees the seam.
 *
 * Two lanes, cleanly divided: *renewal* is proactive (before expiry, in
 * place, invisible); *recovery* is reactive (after expiry, the host resets
 * the iframe src — coarse but dependable). The app triggers recovery simply
 * by reporting status "expired"; it never asks for renewal after expiry.
 *
 * A session epoch is immutable: a successful renewal produces a new
 * tokenExpiresAt (via the refreshed server data), and the consumer destroys
 * this machine and creates a fresh one for the new epoch. That replaces
 * prop-driven state resets with a plain lifecycle, which is what keeps
 * non-React consumers trivial.
 *
 * Messages are origin-checked in both directions against the exact editor
 * origin carried by the redeemed assertion's signed renewal URL.
 *
 * The design record behind this protocol, in the Drupal Canvas repository:
 * docs/adr/0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md.
 */

import {
  HEADLESS_ASSERTION_MESSAGE,
  HEADLESS_RENEW_REQUEST_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
} from '../constants';
import { EXPIRY_SLACK_MS } from '../draft-data';

/**
 * How long before token expiry the app asks its host for a fresh assertion.
 * Comfortably more than one round trip (host mints, app redeems), small
 * next to the 15-minute token life. Clamped to half the token's remaining
 * life at scheduling time: with a site-configured TTL at or below the
 * margin, a fixed 60 s lead would fire immediately on every activation —
 * renew, refresh, renew again, a token-minting loop. The clamp turns that
 * into renewal at half-life, which is merely frequent.
 */
export const RENEW_MARGIN_MS = 60_000;

/**
 * How long a requested renewal may go unanswered before it counts as
 * failed; the recovery lane takes over at expiry.
 */
export const RENEW_TIMEOUT_MS = 10_000;

/**
 * 'idle' → 'requested' (waiting for the host) → 'failed' (no/bad answer).
 * A successful renewal never reaches a terminal state here: the refreshed
 * data carries a new tokenExpiresAt, and the consumer replaces the machine.
 */
export type DraftSessionRenewState = 'idle' | 'requested' | 'failed';

export interface DraftSessionState {
  expired: boolean;
  renewState: DraftSessionRenewState;
}

/**
 * Lifecycle events, emitted on state changes. The consumer owns
 * presentation: this module emits structured events and never renders text.
 */
export type DraftSessionEvent =
  | { type: 'expired' }
  | { type: 'renew-requested' }
  | { type: 'renew-failed' }
  | {
      type: 'renewed';
      /**
       * The renewed session's expiry, straight from the renew endpoint's
       * JSON answer. Consumers without a server-refresh primitive (plain
       * DOM, MPA frameworks) re-create the machine from this value alone;
       * null when the endpoint answered without a usable body.
       */
      tokenExpiresAt: number | null;
    };

export interface DraftSessionOptions {
  /** Epoch ms when the session token dies; null when the cookie is gone. */
  tokenExpiresAt: number | null;
  /** Server-computed expiry state at creation time. */
  initialExpired: boolean;
  /**
   * Whether the document is embedded in an iframe
   * (window.self !== window.top). Embedded, the machine reports status to
   * the host and runs the renewal lane; standalone, it only tracks expiry.
   */
  embedded: boolean;
  /** The current app path, reported to the host; update via setPath(). */
  path: string;
  /** The signed editor origin used as the postMessage peer in both directions. */
  editorOrigin: string | null;
  /** The app endpoint that redeems a fresh assertion into the session. */
  renewEndpoint?: string;
  /**
   * Refreshes the consumer's server-derived data after a successful
   * renewal (Next.js: router.refresh()). The refreshed data carries the new
   * tokenExpiresAt, on which the consumer replaces this machine. Optional:
   * a consumer without such a primitive re-creates the machine from the
   * 'renewed' event's tokenExpiresAt instead — the renewed token already
   * lives in the session cookie, so no server re-render is required.
   */
  refreshData?: () => void;
  /** Receives session lifecycle events. */
  onEvent?: (event: DraftSessionEvent) => void;
  /** The window renewal messages are exchanged with. Default: the parent. */
  hostWindow?: Pick<Window, 'postMessage'>;
  /** Where the assertion message listener is installed. Default: window. */
  listenerTarget?: Pick<Window, 'addEventListener' | 'removeEventListener'>;
  /** Fetch implementation, injectable for tests. */
  fetchImpl?: typeof fetch;
}

export interface DraftSession {
  getState(): DraftSessionState;
  /** Reports a path change to the host (re-sends the status message). */
  setPath(path: string): void;
  /** Clears all timers and the message listener. */
  destroy(): void;
}

const DEFAULT_RENEW_ENDPOINT = '/api/draft/renew';

/**
 * Creates the app side of the renewal protocol for one session epoch.
 */
export function createDraftSession(options: DraftSessionOptions): DraftSession {
  const {
    tokenExpiresAt,
    initialExpired,
    embedded,
    editorOrigin,
    renewEndpoint = DEFAULT_RENEW_ENDPOINT,
    refreshData,
    onEvent,
    hostWindow = typeof window === 'undefined' ? undefined : window.parent,
    listenerTarget = typeof window === 'undefined' ? undefined : window,
    fetchImpl = typeof fetch === 'undefined' ? undefined : fetch,
  } = options;

  let path = options.path;
  let expired = initialExpired;
  let renewState: DraftSessionRenewState = 'idle';
  let destroyed = false;
  const timers = new Set<ReturnType<typeof setTimeout>>();

  const emit = (event: DraftSessionEvent) => {
    if (!destroyed) {
      onEvent?.(event);
    }
  };

  const schedule = (callback: () => void, delay: number) => {
    const timer = setTimeout(
      () => {
        timers.delete(timer);
        if (!destroyed) {
          callback();
        }
      },
      Math.max(delay, 0),
    );
    timers.add(timer);
  };

  const postToHost = (message: Record<string, unknown>) => {
    if (editorOrigin) {
      hostWindow?.postMessage(message, editorOrigin);
    }
  };

  // An "expired" report doubles as the recovery trigger: the host answers
  // it by re-minting and resetting the iframe src.
  const reportStatus = () => {
    if (!embedded) {
      return;
    }
    postToHost({
      type: HEADLESS_STATUS_MESSAGE,
      status: expired ? 'expired' : 'active',
      path,
      tokenExpiresAt,
    });
  };

  // Flip to expired on the clock, in sync with the server's slack.
  if (tokenExpiresAt !== null && !expired) {
    schedule(
      () => {
        expired = true;
        emit({ type: 'expired' });
        reportStatus();
      },
      tokenExpiresAt - EXPIRY_SLACK_MS - Date.now(),
    );
  }

  // The renewal lane: ask the host for a fresh assertion before expiry.
  if (embedded && !expired && tokenExpiresAt !== null) {
    const remaining = tokenExpiresAt - Date.now();
    const margin = Math.min(RENEW_MARGIN_MS, remaining / 2);
    schedule(() => {
      if (expired || renewState !== 'idle') {
        return;
      }
      renewState = 'requested';
      emit({ type: 'renew-requested' });
      postToHost({ type: HEADLESS_RENEW_REQUEST_MESSAGE, path });
      // Give up on a requested renewal the host never answers.
      schedule(() => {
        if (renewState === 'requested') {
          renewState = 'failed';
          emit({ type: 'renew-failed' });
        }
      }, RENEW_TIMEOUT_MS);
    }, remaining - margin);
  }

  // Redeem assertions the host sends down.
  const onMessage = embedded
    ? (event: MessageEvent) => {
        // Only the embedding host may hand us assertions: the source must be
        // the parent window, not merely any window on the editor origin
        // (a popup opener, a nested frame). Mirrors the host checking
        // event.source === iframe.contentWindow in the other direction.
        if (
          event.source !== hostWindow ||
          event.origin !== editorOrigin ||
          !event.data ||
          event.data.type !== HEADLESS_ASSERTION_MESSAGE ||
          typeof event.data.assertion !== 'string'
        ) {
          return;
        }
        void fetchImpl?.(renewEndpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ assertion: event.data.assertion }),
        }).then(
          async (response) => {
            if (destroyed) {
              return;
            }
            if (response.ok) {
              // The renew endpoint answers { tokenExpiresAt }; hand it to
              // the consumer so a machine for the new epoch can be created
              // without a server round trip.
              const body = (await response.json().catch(() => null)) as {
                tokenExpiresAt?: unknown;
              } | null;
              if (destroyed) {
                return;
              }
              // The new token now lives in the session cookie; the consumer
              // replaces this machine for the new epoch, either from the
              // event's tokenExpiresAt or from refreshed server data.
              emit({
                type: 'renewed',
                tokenExpiresAt:
                  typeof body?.tokenExpiresAt === 'number'
                    ? body.tokenExpiresAt
                    : null,
              });
              refreshData?.();
            } else {
              renewState = 'failed';
              emit({ type: 'renew-failed' });
            }
          },
          () => {
            if (!destroyed) {
              renewState = 'failed';
              emit({ type: 'renew-failed' });
            }
          },
        );
      }
    : null;
  if (onMessage) {
    listenerTarget?.addEventListener('message', onMessage as EventListener);
  }

  // The initial status report, so the host learns about this epoch.
  reportStatus();

  return {
    getState: () => ({ expired, renewState }),
    setPath: (nextPath: string) => {
      path = nextPath;
      reportStatus();
    },
    destroy: () => {
      destroyed = true;
      for (const timer of timers) {
        clearTimeout(timer);
      }
      timers.clear();
      if (onMessage) {
        listenerTarget?.removeEventListener(
          'message',
          onMessage as EventListener,
        );
      }
    },
  };
}
