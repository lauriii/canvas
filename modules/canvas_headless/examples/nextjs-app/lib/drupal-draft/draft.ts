import { cookies, draftMode } from "next/headers";
import { redirect } from "next/navigation";
import type { NextRequest } from "next/server";
import { decodeAssertionClaims } from "./assertion";
import { getDrupalDraftConfig } from "./config";
import {
  DRAFT_DATA_COOKIE_NAME,
  DRAFT_MODE_COOKIE_NAME,
  JWT_BEARER_GRANT_TYPE,
} from "./constants";
import {
  getDraftData,
  isDraftSessionExpired,
  type DraftData,
} from "./draft-data";
import { codeChallenge, generateCodeVerifier } from "./pkce";

/**
 * The result of redeeming an assertion at Drupal's token endpoint: the
 * established draft session, or the error Response to answer with.
 */
type RedemptionResult =
  | { ok: true; draftData: DraftData }
  | { ok: false; response: Response };

/**
 * A site-relative path: exactly one leading slash. Rejects protocol-relative
 * forms (`//host`) and backslash tricks, mirroring the check Drupal's
 * renewal endpoints apply before minting. Assertions are Drupal-signed, so
 * a malformed path should never arrive — this is the app-side backstop for
 * the same invariant, since the path ends up in a redirect().
 */
function isSiteRelativePath(path: string): boolean {
  return (
    path.startsWith("/") && !path.startsWith("//") && !path.includes("\\")
  );
}

/**
 * Exchanges a preview assertion for a draft session (RFC 7523 jwt-bearer
 * grant at Drupal's standard token endpoint).
 *
 * The session's entry path and resource version policy are read from the
 * assertion's own claims, which is safe exactly because the token endpoint
 * accepted this exact string: a tampered assertion never gets a token, so
 * its claims are never used.
 *
 * Every exchange registers a fresh PKCE challenge with Drupal and stores
 * the matching verifier in the session; a renewal exchange must present the
 * previous verifier or Drupal refuses it (see lib/drupal-draft/pkce.ts).
 */
async function redeemAssertion(
  assertion: string,
  previousVerifier?: string,
): Promise<RedemptionResult> {
  const config = getDrupalDraftConfig();
  const nextVerifier = generateCodeVerifier();

  let tokenResponse: Response;
  try {
    tokenResponse = await fetch(`${config.baseUrl}/oauth/token`, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        Accept: "application/json",
      },
      body: new URLSearchParams({
        grant_type: JWT_BEARER_GRANT_TYPE,
        assertion,
        client_id: config.clientId,
        code_challenge: codeChallenge(nextVerifier),
        code_challenge_method: "S256",
        ...(previousVerifier ? { code_verifier: previousVerifier } : {}),
      }).toString(),
      cache: "no-store",
    });
  } catch {
    return {
      ok: false,
      response: new Response(
        "Could not reach Drupal to redeem the preview assertion.",
        { status: 502 },
      ),
    };
  }

  if (!tokenResponse.ok) {
    const body = (await tokenResponse.json().catch(() => null)) as {
      error?: string;
      error_description?: string;
      hint?: string;
    } | null;
    const message = [body?.error_description, body?.hint]
      .filter(Boolean)
      .join(" ");
    return {
      ok: false,
      response: new Response(message || "Invalid preview assertion.", {
        status: tokenResponse.status,
      }),
    };
  }

  const tokenBody = (await tokenResponse.json()) as {
    token_type: string;
    expires_in: number;
    access_token: string;
  };

  // Drupal accepted this exact assertion string, so its claims are trusted.
  const claims = decodeAssertionClaims(assertion);
  const path = typeof claims?.path === "string" ? claims.path : null;
  const resourceVersion =
    typeof claims?.resourceVersion === "string" ? claims.resourceVersion : null;
  const sub = typeof claims?.sub === "string" && claims.sub ? claims.sub : null;
  const renewUrl =
    typeof claims?.renewUrl === "string" && /^https?:\/\//.test(claims.renewUrl)
      ? claims.renewUrl
      : null;
  if (!path || !isSiteRelativePath(path) || !resourceVersion || !sub || !renewUrl) {
    return {
      ok: false,
      response: new Response(
        "The preview assertion is missing session claims.",
        { status: 422 },
      ),
    };
  }

  return {
    ok: true,
    draftData: {
      path,
      resourceVersion,
      sub,
      renewUrl,
      accessToken: tokenBody.access_token,
      tokenType: tokenBody.token_type,
      tokenExpiresAt: Date.now() + tokenBody.expires_in * 1000,
      codeVerifier: nextVerifier,
    },
  };
}

/**
 * Enables Next.js Draft Mode and stores the session in the draft cookies.
 *
 * Next.js sets the draft cookie with SameSite=Lax, which browsers do not
 * send in cross-site iframe requests (the Drupal previewer) — draft mode
 * would silently stay off inside the iframe. Re-set it as a cross-site
 * cookie. httpOnly and path are stated explicitly rather than inherited:
 * the cookie store's public contract only guarantees name/value on read,
 * so the token-carrying cookies must not depend on other attributes
 * happening to be preserved.
 * `partitioned` (CHIPS) opts into the per-top-level-site cookie jar, which
 * is what lets browsers with third-party-cookie restrictions (Firefox,
 * Safari 26.2+, Chrome with blocking enabled) accept these cookies inside
 * the iframe. Requires a secure (HTTPS) origin.
 */
async function storeDraftSession(draftData: DraftData): Promise<void> {
  const draft = await draftMode();
  draft.enable();

  const cookieStore = await cookies();
  const draftCookie = cookieStore.get(DRAFT_MODE_COOKIE_NAME);
  if (draftCookie) {
    cookieStore.set({
      name: draftCookie.name,
      value: draftCookie.value,
      httpOnly: true,
      path: "/",
      sameSite: "none",
      secure: true,
      partitioned: true,
    });
  }

  cookieStore.set({
    name: DRAFT_DATA_COOKIE_NAME,
    value: JSON.stringify(draftData),
    httpOnly: true,
    path: "/",
    sameSite: "none",
    secure: true,
    partitioned: true,
  });
}

/**
 * Enables Next.js Draft Mode from a signed Drupal preview assertion.
 *
 * One round trip does everything: the assertion from the preview URL is
 * exchanged at Drupal's standard token endpoint with the RFC 7523
 * jwt-bearer grant. Drupal verifies the signature, expiry, and single-use
 * jti, and answers with an access token bound to the editor who initiated
 * the preview. No client secret is involved — the consumer is a public
 * client and the assertion itself is the credential.
 */
export async function enableDraftMode(request: NextRequest): Promise<Response> {
  const assertion = request.nextUrl.searchParams.get("assertion");
  if (!assertion) {
    return new Response("Missing preview assertion.", { status: 422 });
  }

  const result = await redeemAssertion(assertion);

  if (!result.ok) {
    // A dead assertion can arrive on top of a live session: assertions are
    // single-use, so restoring a closed tab or navigating back to the
    // /api/draft entry URL re-submits one that was already redeemed. The
    // session itself (cookies) is unaffected — continue into it instead of
    // stranding the user on an error page.
    const existingSession = await getDraftData();
    if (existingSession && !isDraftSessionExpired(existingSession)) {
      redirect(existingSession.path);
    }

    return result.response;
  }

  await storeDraftSession(result.draftData);

  // The path was signed into the assertion Drupal accepted, and is
  // additionally constrained to a site-relative path (no scheme, host, or
  // protocol-relative form) in redeemAssertion().
  redirect(result.draftData.path);
}

/**
 * Renews the draft session in place from a fresh assertion.
 *
 * The embedded app receives the assertion from its host over postMessage
 * (the host holds the editor's Drupal session; the app cannot reach it) and
 * POSTs it here. The exchange and cookie handling are exactly the
 * activation path — same single-use jti, same claim checks on Drupal's side
 * — but the response is JSON instead of a redirect, so the client can
 * refresh its data without a document reload. The renewed session's entry
 * path comes from the new assertion's claims: the host mints it for
 * wherever the editor currently is, so a later session recovery re-enters
 * there, not at the original entry point.
 *
 * The renewal exchange is PKCE-bound to this server. The assertion reaches
 * this endpoint through the embedded page's script context (postMessage),
 * where an injected script could intercept it — but Drupal refuses to
 * redeem a renewal assertion without the code_verifier registered at the
 * previous redemption, and that verifier lives in the httpOnly session
 * cookie only this server reads. An intercepted assertion therefore cannot
 * be exchanged for a raw access token anywhere else; the worst an injected
 * script can do with it is POST it back here, which just renews the session
 * normally.
 *
 * This endpoint carries no CSRF token, and the reason is narrower than it
 * once was. The request *does* consume cookie-held authority: it reads the
 * session's PKCE verifier from the httpOnly cookie, spends it at Drupal,
 * and rotates it — so this is not a pure credential-in-body exchange, and a
 * cross-site POST would carry those cookies. What makes a forged request
 * inert is that the attacker cannot supply the other half: a valid,
 * unexpired, unredeemed, renewal-marked assertion, minted only by Drupal
 * for the editor's live session. Without one the exchange is refused before
 * anything is spent — Drupal validates the assertion, and the verifier's
 * challenge, before consuming either. The verifier never leaves this
 * server, so a forged request can neither learn it nor redirect where it
 * goes; and a renewal-marked assertion is useless anywhere else, including
 * at GET /api/draft, whose activation exchange sends no verifier and is
 * therefore refused for exactly the same reason.
 *
 * Renewal is *continuation*, so it requires a session to continue: without
 * an existing draft session (even an expired one), the request is refused
 * with 400 — starting a session is the preview URL's job (GET /api/draft),
 * and refusing here keeps this endpoint from doubling as a second
 * activation surface.
 *
 * Continuation is also identity-pinned: if the assertion
 * names a different editor than the running session (the browser's Drupal
 * session changed hands mid-preview — editor A logged out, editor B logged
 * in), the renewal is refused with 409 and the session is left untouched.
 * Without this check the session would silently continue as another user,
 * permissions and attribution included. The refusal is deliberate about
 * what happens next: the session expires on schedule and the recovery lane
 * starts a *new* session as the new editor — a visible fresh start, never
 * a silent swap. Activation (enableDraftMode) intentionally has no such
 * check: a preview URL arriving as a top-level navigation is an explicit
 * new session for whoever holds the Drupal session.
 */
export async function renewDraftSession(request: Request): Promise<Response> {
  const body = (await request.json().catch(() => null)) as {
    assertion?: unknown;
  } | null;
  const assertion = typeof body?.assertion === "string" ? body.assertion : null;
  if (!assertion) {
    return new Response("Missing preview assertion.", { status: 422 });
  }

  // Continuation only: no session, nothing to renew (see the docblock).
  const existingSession = await getDraftData();
  if (!existingSession) {
    return new Response(
      "No draft session to renew. Open a preview from Drupal to start one.",
      { status: 400 },
    );
  }

  // Identity pre-check on the *unverified* claims — safe, because it can
  // only refuse: an assertion forged to pass this check still has to pass
  // Drupal's signature verification to mint anything. Checking before the
  // exchange keeps a mismatched (still valid, single-use) assertion
  // unconsumed and avoids minting a token nobody will use.
  const claimedSub = decodeAssertionClaims(assertion)?.sub;
  if (claimedSub !== existingSession.sub) {
    return new Response(
      "The assertion names a different editor than this draft session. Re-open the preview from Drupal to start a new session.",
      { status: 409 },
    );
  }

  const result = await redeemAssertion(assertion, existingSession.codeVerifier);
  if (!result.ok) {
    return result.response;
  }

  await storeDraftSession(result.draftData);

  return Response.json({ tokenExpiresAt: result.draftData.tokenExpiresAt });
}

/**
 * Disables draft mode, clears the draft data, and returns to the homepage.
 *
 * Invoked by POST (see app/api/disable-draft/route.ts), so the redirect is
 * a 303 See Other: the browser follows it with a GET, instead of a 307
 * replaying the POST against the homepage.
 *
 * Browsers only delete a cookie when the deletion carries the same
 * partition attributes it was set with. draftMode().disable() and
 * cookieStore.delete() emit deletions without `Partitioned`, which leaves
 * the CHIPS cookies from enableDraftMode() alive — draft mode would be
 * impossible to exit. Overwrite both cookies with expired equivalents
 * carrying the original attributes instead.
 */
export async function disableDraftMode(): Promise<Response> {
  const draft = await draftMode();
  draft.disable();
  const cookieStore = await cookies();

  // A deletion is a Set-Cookie with an expiry in the past — and the browser
  // only applies it to a cookie whose identity matches, which for CHIPS
  // cookies includes the partition. draft.disable() above queues such a
  // deletion for the draft-mode cookie, but without `Partitioned`, so it
  // targets an unpartitioned cookie that does not exist and the real one
  // survives (same for cookieStore.delete() and the data cookie). Setting
  // the cookies to an empty value, already expired (epoch), with the exact
  // attributes storeDraftSession() used — Path=/; SameSite=None; Secure;
  // Partitioned (httpOnly is not part of cookie identity but is kept in
  // step with the set side) —
  // produces deletions that match the cookies actually stored. curl-based
  // tests cannot catch a regression here: its cookie jar has no partitioning,
  // so the attribute-less deletions work there. Verify exits in a browser.
  for (const name of [DRAFT_MODE_COOKIE_NAME, DRAFT_DATA_COOKIE_NAME]) {
    cookieStore.set({
      name,
      value: "",
      expires: new Date(0),
      httpOnly: true,
      path: "/",
      sameSite: "none",
      secure: true,
      partitioned: true,
    });
  }
  return new Response(null, { status: 303, headers: { Location: "/" } });
}
