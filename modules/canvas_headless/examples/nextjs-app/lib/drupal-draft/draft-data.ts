import { cookies, draftMode } from "next/headers";
import { DRAFT_DATA_COOKIE_NAME, DRAFT_EXPIRY_SLACK_MS } from "./constants";

/**
 * The draft session, established by exchanging a signed preview assertion at
 * Drupal's token endpoint. It describes a session, not a previewed entity:
 *
 * - `path`: the session's entry point, from the assertion's claims.
 *   Navigation only — what the app previews is determined by this path and
 *   the app's own routing.
 * - `resourceVersion`: session-wide revision policy the draft client applies
 *   to every fetch, using Drupal core JSON:API's `resourceVersion` query
 *   parameter values:
 *   - `rel:working-copy` — the latest revision of each entity, whether or
 *     not it is published ("show me work-in-progress everywhere"). This is
 *     what the Drupal module sends, and the usual meaning of draft mode.
 *     Precisely: the forward revision if one exists, otherwise the
 *     default revision. A forward (or "pending") revision is a draft saved
 *     on top of a published entity — the published revision stays the
 *     default and stays live, the draft sits ahead of it awaiting
 *     publication. Never-published entities have no forward revision (their
 *     latest draft *is* the default revision), which is why they show up in
 *     collections without this parameter while forward revisions do not.
 *     Resolved live at fetch time: if an editor saves another draft
 *     mid-session, a reload shows it.
 *   - `rel:latest-version` — the latest *default* (typically published)
 *     revision, even when newer drafts exist ("preview as it would publish
 *     today"). A valid session policy, unused by this POC.
 *   - `id:<revision-id>` — one exact revision. Inherently per-entity, so it
 *     does not make sense as a session-wide policy; a "view this historical
 *     revision" feature would carry it per fetch, not here.
 * - `sub`: the Drupal user id of the editor the session is bound to, from
 *   the assertion's `sub` claim. Renewal is *continuation*, not activation:
 *   a renewal whose assertion names a different editor (the browser's
 *   Drupal session changed hands mid-preview) is refused, so a session can
 *   never silently change identity — only an explicit new activation can.
 * - `renewUrl`: the absolute URL of Drupal's standalone renewal route, as
 *   seen by the editor's browser — a signed claim, minted from the request
 *   Drupal received, so no frontend configuration names a browser-facing
 *   Drupal URL (in multi-origin dev topologies the app's server-side base
 *   URL is a different origin). The expired banner's "Renew session" link
 *   navigates here top-level.
 * - `accessToken` / `tokenType` / `tokenExpiresAt`: the session's access
 *   token, bound to the editor who initiated the preview. Draft requests
 *   act with exactly that editor's permissions — there are no client
 *   credentials to fall back on. Before it expires, the session renews in
 *   place by redeeming a fresh assertion minted from the editor's live
 *   Drupal session; a token that expires anyway ends the session until it
 *   is renewed or re-activated from Drupal.
 * - `codeVerifier`: the PKCE verifier proving the next renewal comes from
 *   this server. Renewal assertions transit the embedded page's script
 *   context, so Drupal only redeems them together with this verifier —
 *   which lives here, in the httpOnly cookie, out of any script's reach.
 *   Rotated on every redemption (see lib/drupal-draft/pkce.ts).
 */
export interface DraftData {
  path: string;
  resourceVersion: string;
  sub: string;
  renewUrl: string;
  accessToken: string;
  tokenType: string;
  /** Unix epoch milliseconds after which the access token is invalid. */
  tokenExpiresAt: number;
  codeVerifier: string;
}

/**
 * Returns the draft data for the current request, or null when draft mode is
 * off or the data cookie is missing/corrupt.
 */
export async function getDraftData(): Promise<DraftData | null> {
  const draft = await draftMode();
  if (!draft.isEnabled) {
    return null;
  }
  const cookieStore = await cookies();
  const cookie = cookieStore.get(DRAFT_DATA_COOKIE_NAME);
  if (!cookie?.value) {
    return null;
  }
  try {
    const data = JSON.parse(cookie.value) as DraftData;
    if (
      typeof data.path !== "string" ||
      typeof data.resourceVersion !== "string" ||
      typeof data.sub !== "string" ||
      typeof data.renewUrl !== "string" ||
      typeof data.accessToken !== "string" ||
      typeof data.tokenType !== "string" ||
      typeof data.tokenExpiresAt !== "number" ||
      typeof data.codeVerifier !== "string"
    ) {
      return null;
    }
    return data;
  } catch {
    return null;
  }
}

/**
 * Whether the draft session's access token has expired.
 *
 * An expired session is surfaced, never silently downgraded: pages fall
 * back to what anonymous visitors can see while the draft indicator
 * explains that the preview session ended. The slack avoids acting on a
 * token that will be dead by the time the request reaches Drupal.
 */
export function isDraftSessionExpired(draftData: DraftData): boolean {
  return Date.now() >= draftData.tokenExpiresAt - DRAFT_EXPIRY_SLACK_MS;
}
