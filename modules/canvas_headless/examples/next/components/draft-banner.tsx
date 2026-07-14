"use client";

import { DraftSession } from "@drupal-canvas/headless-next/client";

/**
 * The app's session banner, rendered through the SDK's <DraftSession>
 * render prop. The SDK owns the session lifecycle (expiry timing, the
 * renewal protocol with the embedding host, refreshing after a renewal);
 * this component owns presentation and the banner policy:
 *
 * - Standalone, the yellow "active" banner shows, with an exit form. The
 *   red "expired" banner adds a "Renew session" link — a top-level
 *   navigation through Drupal, the one request shape that still carries
 *   Drupal's SameSite=Lax session cookie cross-site.
 * - Embedded, the host owns the chrome, so nothing renders — with one
 *   exception: an *expired* session shows the red banner even embedded, as
 *   the last-resort fallback for a host that does not speak the protocol.
 *   Expiry going invisible inside an iframe was the problem that motivated
 *   the protocol.
 */
export function DraftBanner(props: {
  tokenExpiresAt: number | null;
  initialExpired: boolean;
  renewUrl: string | null;
  embedderOrigins: string[];
}) {
  return (
    <DraftSession {...props}>
      {({ embedded, expired, path, renewUrl }) => {
        if (expired) {
          return (
            <div className="flex items-center justify-between gap-4 bg-red-200 px-4 py-2 text-sm text-red-950">
              <span>
                <strong>Draft preview session expired.</strong> Showing only
                content visible to anonymous visitors.
              </span>
              <span className="flex gap-4">
                {!embedded && renewUrl && (
                  <a
                    href={`${renewUrl}?path=${encodeURIComponent(path)}`}
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
          // The host owns the session chrome; status is reported upward.
          return null;
        }

        return (
          <div className="flex items-center justify-between gap-4 bg-amber-300 px-4 py-2 text-sm text-amber-950">
            <span>
              <strong>Draft mode is active.</strong> You may be seeing
              unpublished content.
            </span>
            <ExitDraftModeButton />
          </div>
        );
      }}
    </DraftSession>
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
