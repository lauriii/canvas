"use client";

import { usePathname, useRouter } from "next/navigation";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  useSyncExternalStore,
} from "react";
import {
  DRAFT_ASSERTION_MESSAGE,
  DRAFT_EXPIRY_SLACK_MS,
  DRAFT_RENEW_REQUEST_MESSAGE,
  DRAFT_STATUS_MESSAGE,
} from "@/lib/drupal-draft/constants";

/**
 * How long before token expiry the app asks its host for a fresh assertion.
 * Comfortably more than one round trip (host mints, app redeems), small
 * next to the 15-minute token life. Clamped to half the token's remaining
 * life at scheduling time: with a site-configured TTL at or below the
 * margin, a fixed 60 s lead would fire immediately on every activation —
 * renew, refresh, renew again, a token-minting loop. The clamp turns that
 * into renewal at half-life, which is merely frequent.
 */
const RENEW_MARGIN_MS = 60_000;

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
 * The app's side of the draft session lifecycle.
 *
 * Renewal is a division of labor: the app knows *when* the token dies
 * (tokenExpiresAt is right in the session cookie) but cannot mint a new
 * assertion — only the editor's Drupal session can, and the app's requests
 * never carry it. So, embedded, the app asks its host over postMessage
 * 60 s before expiry; the host answers with a fresh assertion, the app
 * redeems it at /api/draft/renew (new token, same cookies), and
 * router.refresh() re-renders with draft data — no document reload, no
 * navigation loss. The editor never sees the seam.
 *
 * Two lanes, cleanly divided: *renewal* is proactive (before expiry, in
 * place, invisible); *recovery* is reactive (after expiry, the host resets
 * the iframe src — coarse but dependable). The app triggers recovery simply
 * by reporting status "expired"; it never asks for renewal after expiry.
 *
 * Banner ownership: standalone, this component renders the yellow/red
 * banner itself (with a "Renew session" link — a top-level navigation
 * through Drupal, the one request shape that still carries Drupal's
 * SameSite=Lax session cookie cross-site). Embedded, the host owns the
 * chrome — the app suppresses the banner and reports status upward instead.
 * One exception: an *expired* session shows the red banner even embedded,
 * as the last-resort fallback for a host that does not speak the protocol —
 * expiry going invisible inside an iframe was the problem that motivated
 * all of this.
 *
 * Messages are origin-checked in both directions against the embedder
 * allowlist (the same origins the CSP frame-ancestors policy admits).
 */
export function DraftSessionClient({
  tokenExpiresAt,
  initialExpired,
  renewUrl,
  embedderOrigins,
}: {
  /** Epoch ms when the session token dies; null when the cookie is gone. */
  tokenExpiresAt: number | null;
  /** Server-computed expiry state, so first paint matches the server. */
  initialExpired: boolean;
  /**
   * Drupal's renew route (absolute, browser-facing — a signed assertion
   * claim, not configuration); ?path= is appended client-side. Null when
   * the session cookie is gone.
   */
  renewUrl: string | null;
  /** Origins allowed to embed this app (postMessage peers). */
  embedderOrigins: string[];
}) {
  const router = useRouter();
  const pathname = usePathname();
  const embedded = useEmbedded();
  const [expired, setExpired] = useState(initialExpired);
  // 'idle' → 'requested' (waiting for the host) → 'failed' (no/bad answer).
  // A successful renewal never reaches a terminal state here: the refreshed
  // props change tokenExpiresAt, which resets the machine to 'idle'.
  const [renewState, setRenewState] = useState<"idle" | "requested" | "failed">(
    "idle",
  );

  // A renewed session arrives as new props (the /api/draft/renew response
  // set a new cookie and router.refresh() re-rendered the server tree);
  // re-arm the state machine. Adjusting state during render is React's
  // prescribed pattern for prop-driven resets.
  const [prevExpiresAt, setPrevExpiresAt] = useState(tokenExpiresAt);
  if (tokenExpiresAt !== prevExpiresAt) {
    setPrevExpiresAt(tokenExpiresAt);
    setExpired(initialExpired);
    setRenewState("idle");
  }

  // Stable identity for hook dependencies (the array prop is fresh each
  // render), and the parsed origins derived from it once for both the
  // outbound postMessage and the inbound listener.
  const originsKey = embedderOrigins.join(" ");
  const origins = useMemo(
    () => originsKey.split(" ").filter(Boolean),
    [originsKey],
  );

  const postToHost = useCallback(
    (message: Record<string, unknown>) => {
      // postMessage takes a single targetOrigin; address every allowlisted
      // embedder — the browser delivers only to the one that matches.
      for (const origin of origins) {
        window.parent.postMessage(message, origin);
      }
    },
    [origins],
  );

  // Flip to expired on the clock, in sync with the server's slack.
  useEffect(() => {
    if (tokenExpiresAt === null || expired) {
      return;
    }
    const delay = tokenExpiresAt - DRAFT_EXPIRY_SLACK_MS - Date.now();
    const timer = setTimeout(() => setExpired(true), Math.max(delay, 0));
    return () => clearTimeout(timer);
  }, [tokenExpiresAt, expired]);

  // Report status upward. An "expired" report doubles as the recovery
  // trigger: the host answers it by re-minting and resetting the iframe src.
  useEffect(() => {
    if (!embedded) {
      return;
    }
    postToHost({
      type: DRAFT_STATUS_MESSAGE,
      status: expired ? "expired" : "active",
      path: pathname,
      tokenExpiresAt,
    });
  }, [embedded, expired, pathname, tokenExpiresAt, postToHost]);

  // The renewal lane: ask the host for a fresh assertion before expiry.
  useEffect(() => {
    if (!embedded || expired || tokenExpiresAt === null || renewState !== "idle") {
      return;
    }
    const remaining = tokenExpiresAt - Date.now();
    const margin = Math.min(RENEW_MARGIN_MS, remaining / 2);
    const timer = setTimeout(() => {
      setRenewState("requested");
      postToHost({ type: DRAFT_RENEW_REQUEST_MESSAGE, path: pathname });
    }, Math.max(remaining - margin, 0));
    return () => clearTimeout(timer);
  }, [embedded, expired, tokenExpiresAt, renewState, pathname, postToHost]);

  // Give up on a requested renewal the host never answers; the recovery
  // lane takes over at expiry.
  useEffect(() => {
    if (renewState !== "requested") {
      return;
    }
    const timer = setTimeout(() => setRenewState("failed"), 10_000);
    return () => clearTimeout(timer);
  }, [renewState]);

  // Redeem assertions the host sends down.
  useEffect(() => {
    if (!embedded) {
      return;
    }
    const onMessage = async (event: MessageEvent) => {
      // Only the embedding host may hand us assertions: the source must be
      // the parent window, not merely any window on an allowlisted origin
      // (a popup opener, a nested frame). Mirrors previewer.js checking
      // event.source === iframe.contentWindow in the other direction.
      if (
        event.source !== window.parent ||
        !origins.includes(event.origin) ||
        !event.data ||
        event.data.type !== DRAFT_ASSERTION_MESSAGE ||
        typeof event.data.assertion !== "string"
      ) {
        return;
      }
      const response = await fetch("/api/draft/renew", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ assertion: event.data.assertion }),
      });
      if (response.ok) {
        // The new token now lives in the session cookie; re-render server
        // components so draft fetches use it and new props re-arm the state.
        router.refresh();
      } else {
        setRenewState("failed");
      }
    };
    window.addEventListener("message", onMessage);
    return () => window.removeEventListener("message", onMessage);
  }, [embedded, origins, router]);

  if (embedded === null) {
    return null;
  }

  if (expired) {
    // Shown standalone, and embedded as the last-resort fallback (a
    // protocol-speaking host recovers before anyone reads it).
    return (
      <div className="flex items-center justify-between gap-4 bg-red-200 px-4 py-2 text-sm text-red-950">
        <span>
          <strong>Draft preview session expired.</strong> Showing only content
          visible to anonymous visitors.
        </span>
        <span className="flex gap-4">
          {!embedded && renewUrl && (
            <a
              href={`${renewUrl}?path=${encodeURIComponent(pathname)}`}
              className="font-semibold underline"
            >
              Renew session
            </a>
          )}
          <ExitDraftModeButton />
        </span>
      </div>
    );
  }

  if (embedded) {
    // The host owns the session chrome; status was reported upward instead.
    return null;
  }

  return (
    <div className="flex items-center justify-between gap-4 bg-amber-300 px-4 py-2 text-sm text-amber-950">
      <span>
        <strong>Draft mode is active.</strong> You may be seeing unpublished
        content.
      </span>
      <ExitDraftModeButton />
    </div>
  );
}

/**
 * Exits draft mode through a POST form.
 *
 * Not a link: exiting changes state (it clears the session cookies), and a
 * GET link would be eligible for prefetching, which could end the session
 * without a click. A plain form needs no JavaScript and nothing prefetches
 * it.
 */
function ExitDraftModeButton() {
  return (
    <form method="POST" action="/api/disable-draft">
      <button type="submit" className="cursor-pointer font-semibold underline">
        Exit draft mode
      </button>
    </form>
  );
}
