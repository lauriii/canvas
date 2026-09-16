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
 *     today"). A valid session policy the module does not currently mint.
 *   - `id:<revision-id>` — one exact revision. Inherently per-entity, so it
 *     does not make sense as a session-wide policy; a "view this historical
 *     revision" feature would carry it per fetch, not here.
 * - `previewContext`: optional signed rendering context for editor previews.
 *   The SDK forwards it only while the user-bound draft session is live.
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
 *   the app server. Renewal assertions transit the embedded page's script
 *   context, so Drupal only redeems them together with this verifier —
 *   which lives here, in the httpOnly cookie, out of any script's reach.
 *   Rotated on every redemption (see server/pkce.ts).
 */
export interface DraftData {
  path: string;
  resourceVersion: string;
  /** Signed rendering context for an editor preview. */
  previewContext?: {
    viewMode?: string;
    pageVariant?: string;
  };
  sub: string;
  renewUrl: string;
  accessToken: string;
  tokenType: string;
  /** Unix epoch milliseconds after which the access token is invalid. */
  tokenExpiresAt: number;
  codeVerifier: string;
}

/**
 * Returns the exact editor origin carried by the redeemed assertion's
 * signed renewal URL. Only HTTP(S) URLs without credentials are accepted.
 */
export function getDraftEditorOrigin(
  draftData: Pick<DraftData, 'renewUrl'> | null | undefined,
): string | null {
  if (!draftData) {
    return null;
  }

  try {
    const renewUrl = new URL(draftData.renewUrl);
    if (
      (renewUrl.protocol !== 'http:' && renewUrl.protocol !== 'https:') ||
      renewUrl.username !== '' ||
      renewUrl.password !== ''
    ) {
      return null;
    }
    return renewUrl.origin;
  } catch {
    return null;
  }
}

/**
 * How much earlier than `tokenExpiresAt` a session counts as expired, so
 * nothing acts on a token that will be dead by the time a request reaches
 * Drupal. The client-side state machine applies the same slack, so the
 * client flips to "expired" at the same moment the server would.
 */
export const EXPIRY_SLACK_MS = 5_000;

/**
 * Parses and validates a serialized draft-data cookie value. Returns null
 * for missing, malformed, or incomplete data — an unreadable session is
 * treated as no session.
 */
export function parseDraftData(
  value: string | null | undefined,
): DraftData | null {
  if (!value) {
    return null;
  }
  try {
    const data = JSON.parse(value) as DraftData;
    if (
      typeof data.path !== 'string' ||
      typeof data.resourceVersion !== 'string' ||
      (data.previewContext !== undefined &&
        (typeof data.previewContext !== 'object' ||
          data.previewContext === null ||
          (data.previewContext.viewMode !== undefined &&
            typeof data.previewContext.viewMode !== 'string') ||
          (data.previewContext.pageVariant !== undefined &&
            typeof data.previewContext.pageVariant !== 'string'))) ||
      typeof data.sub !== 'string' ||
      typeof data.renewUrl !== 'string' ||
      typeof data.accessToken !== 'string' ||
      typeof data.tokenType !== 'string' ||
      typeof data.tokenExpiresAt !== 'number' ||
      typeof data.codeVerifier !== 'string'
    ) {
      return null;
    }
    return data;
  } catch {
    return null;
  }
}

/**
 * Serializes a draft session for cookie storage; parseDraftData() reverses
 * it.
 */
export function serializeDraftData(draftData: DraftData): string {
  return JSON.stringify(draftData);
}

/**
 * Whether the draft session's access token has expired.
 *
 * An expired session is surfaced, never silently downgraded: pages fall
 * back to what anonymous visitors can see while the draft indicator
 * explains that the preview session ended.
 */
export function isDraftSessionExpired(
  draftData: DraftData,
  now: number = Date.now(),
): boolean {
  return now >= draftData.tokenExpiresAt - EXPIRY_SLACK_MS;
}
