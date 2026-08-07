/**
 * @file
 * The framework-agnostic draft server: the activation, renewal, and exit
 * flows, and the data accessors app code needs.
 *
 * The design records behind these flows, in the Drupal Canvas repository:
 * - docs/adr/0014-headless-draft-preview-user-bound-tokens-via-jwt-assertion-grant.md
 *   — user-bound tokens via the RFC 7523 assertion grant.
 * - docs/adr/0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md
 *   — in-place session renewal.
 * - docs/adr/0016-headless-draft-preview-embedded-draft-state-in-partitioned-cookies.md
 *   — the partitioned-cookie transport, including the browser matrix.
 * - modules/canvas_headless/docs/headless-preview-auth.md — a concept-level
 *   walkthrough of the whole auth design.
 */

import { decodeAssertionClaims } from '../assertion';
import { DRAFT_DATA_COOKIE_NAME } from '../constants';
import {
  isDraftSessionExpired,
  parseDraftData,
  serializeDraftData,
} from '../draft-data';
import { resolveDraftConfig } from './config';
import { fetchPage } from './content-api';
import { buildClearedDraftCookie, buildDraftCookie } from './cookies';
import { getDraftClient, getPublicClient } from './json-api-client';
import { codeChallenge, generateCodeVerifier } from './pkce';
import { exchangeAssertion } from './token-exchange';

import type { JsonApiClient } from '@drupal-api-client/json-api-client';
import type { DraftData } from '../draft-data';
import type { PageResult } from '../page';
import type { DraftServerAdapter } from './adapter';
import type { DraftConfig } from './config';

/**
 * The result of redeeming an assertion at Drupal's token endpoint: the
 * established draft session, or the error Response to answer with.
 */
export type RedemptionResult =
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
  return path.startsWith('/') && !path.startsWith('//') && !path.includes('\\');
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
 * previous verifier or Drupal refuses it (see ./pkce.ts).
 */
export async function redeemAssertion(
  assertion: string,
  config: DraftConfig,
  fetchImpl: typeof fetch = fetch,
  previousVerifier?: string,
): Promise<RedemptionResult> {
  const nextVerifier = generateCodeVerifier();
  const exchange = await exchangeAssertion(assertion, config, fetchImpl, {
    codeChallenge: await codeChallenge(nextVerifier),
    codeVerifier: previousVerifier,
  });

  if (!exchange.ok) {
    return {
      ok: false,
      response: new Response(exchange.message, {
        status: exchange.kind === 'network' ? 502 : exchange.status,
      }),
    };
  }

  // Drupal accepted this exact assertion string, so its claims are trusted.
  const claims = decodeAssertionClaims(assertion);
  const path = typeof claims?.path === 'string' ? claims.path : null;
  const resourceVersion =
    typeof claims?.resourceVersion === 'string' ? claims.resourceVersion : null;
  const rawPreviewContext =
    typeof claims?.previewContext === 'object' && claims.previewContext !== null
      ? (claims.previewContext as Record<string, unknown>)
      : null;
  const previewContext =
    rawPreviewContext &&
    (rawPreviewContext.viewMode === undefined ||
      typeof rawPreviewContext.viewMode === 'string')
      ? {
          ...(typeof rawPreviewContext.viewMode === 'string' && {
            viewMode: rawPreviewContext.viewMode,
          }),
        }
      : undefined;
  const sub = typeof claims?.sub === 'string' && claims.sub ? claims.sub : null;
  const renewUrl =
    typeof claims?.renewUrl === 'string' && /^https?:\/\//.test(claims.renewUrl)
      ? claims.renewUrl
      : null;
  if (
    !path ||
    !isSiteRelativePath(path) ||
    !resourceVersion ||
    !sub ||
    !renewUrl
  ) {
    return {
      ok: false,
      response: new Response(
        'The preview assertion is missing session claims.',
        {
          status: 422,
        },
      ),
    };
  }

  return {
    ok: true,
    draftData: {
      path,
      resourceVersion,
      ...(previewContext && { previewContext }),
      sub,
      renewUrl,
      accessToken: exchange.accessToken,
      tokenType: exchange.tokenType,
      tokenExpiresAt: Date.now() + exchange.expiresIn * 1000,
      codeVerifier: nextVerifier,
    },
  };
}

export interface DraftServerOptions {
  /** The framework adapter the flows act through. */
  adapter: DraftServerAdapter;
  /**
   * Static configuration overrides, or a provider called per request.
   * Default: resolveDraftConfig() from the environment — resolved lazily on
   * every call, never at construction, so building an app without the env
   * set does not throw at import time.
   */
  config?: Partial<DraftConfig> | (() => DraftConfig);
  /** Fetch implementation, injectable for tests. */
  fetchImpl?: typeof fetch;
}

/**
 * The framework-agnostic draft server: route-handler bodies plus the data
 * accessors app code needs. One instance serves every request — all state
 * lives in the request's cookies, reached through the adapter.
 */
export interface DraftServer {
  /**
   * Body of the draft-mode activation route (GET, `?assertion=` query).
   * Redeems the assertion, stores the session, and redirects to the signed
   * entry path; failure responses pass Drupal's status through.
   */
  enableDraftMode(request: Request): Promise<Response>;
  /**
   * Body of the renewal route (POST, JSON `{assertion}`). Continuation
   * only: 400 without an existing session, 409 when the assertion names a
   * different editor; on success answers `{tokenExpiresAt}` as JSON.
   */
  renewDraftSession(request: Request): Promise<Response>;
  /**
   * Body of the draft-mode exit route (POST — exiting changes state, and a
   * GET endpoint reached by links would be eligible for prefetching):
   * disables the flag, overwrites both cookies expired, and redirects to
   * the homepage with a 303 so the browser follows with a GET.
   */
  disableDraftMode(): Promise<Response>;
  /**
   * The draft session for the current request, or null when draft mode is
   * off or the data cookie is missing/corrupt.
   */
  getDraftData(): Promise<DraftData | null>;
  /** The resolved configuration. */
  getConfig(): DraftConfig;
  /** A client for public content: unauthenticated, published content only. */
  getPublicClient(): JsonApiClient;
  /**
   * A client for draft content, authenticated with the session's
   * user-bound access token. Throws when the session has expired.
   */
  getDraftClient(draftData: DraftData): JsonApiClient;
  /**
   * The right client for the current request: the draft client (user-bound
   * session token, working copies) while the draft session is live,
   * otherwise the public client. An expired draft session falls back to
   * anonymous fetching — the draft indicator makes that state visible
   * instead of letting anonymous-visible content masquerade as a draft.
   */
  getClient(): Promise<JsonApiClient>;
  /**
   * Fetches a page by its Drupal path (see ./content-api), carrying the
   * live draft session's bearer token when there is one.
   */
  fetchPage(path: string): Promise<PageResult | null>;
}

/**
 * Creates the draft server for one framework adapter.
 */
export function createDraftServer(options: DraftServerOptions): DraftServer {
  const { adapter, config, fetchImpl = fetch } = options;

  const getConfig = (): DraftConfig =>
    typeof config === 'function' ? config() : resolveDraftConfig(config);

  const getDraftData = async (): Promise<DraftData | null> => {
    if (!(await adapter.isDraftFlagEnabled())) {
      return null;
    }
    return parseDraftData(await adapter.getCookie(DRAFT_DATA_COOKIE_NAME));
  };

  /**
   * Enables the framework draft flag and stores the session in the draft
   * cookies. The framework's own flag cookie (when it has one) is re-set
   * with the cross-site attribute set — see DRAFT_COOKIE_ATTRIBUTES for
   * why the defaults would silently break inside the embedding iframe.
   */
  const storeDraftSession = async (draftData: DraftData): Promise<void> => {
    await adapter.enableDraftFlag();

    if (adapter.draftFlagCookieName) {
      const flagValue = await adapter.getCookie(adapter.draftFlagCookieName);
      if (flagValue !== null) {
        await adapter.setCookie(
          buildDraftCookie(adapter.draftFlagCookieName, flagValue),
        );
      }
    }

    await adapter.setCookie(
      buildDraftCookie(DRAFT_DATA_COOKIE_NAME, serializeDraftData(draftData)),
    );
  };

  return {
    getConfig,
    getDraftData,

    async enableDraftMode(request: Request): Promise<Response> {
      const assertion = new URL(request.url).searchParams.get('assertion');
      if (!assertion) {
        return new Response('Missing preview assertion.', { status: 422 });
      }

      const result = await redeemAssertion(assertion, getConfig(), fetchImpl);

      if (!result.ok) {
        // A dead assertion can arrive on top of a live session: assertions
        // are single-use, so restoring a closed tab or navigating back to
        // the activation entry URL re-submits one that was already
        // redeemed. The session itself (cookies) is unaffected — continue
        // into it instead of stranding the user on an error page.
        const existingSession = await getDraftData();
        if (existingSession && !isDraftSessionExpired(existingSession)) {
          return adapter.redirect(existingSession.path);
        }

        return result.response;
      }

      await storeDraftSession(result.draftData);

      // The path was signed into the assertion Drupal accepted, and is
      // additionally constrained to a site-relative path (no scheme, host,
      // or protocol-relative form) in redeemAssertion().
      return adapter.redirect(result.draftData.path);
    },

    /**
     * The exchange and cookie handling are exactly the activation path —
     * same single-use jti, same claim checks on Drupal's side — but the
     * response is JSON instead of a redirect, so the client can refresh its
     * data without a document reload. The renewed session's entry path
     * comes from the new assertion's claims: the host mints it for wherever
     * the editor currently is, so a later session recovery re-enters there,
     * not at the original entry point.
     *
     * The renewal exchange is PKCE-bound to the app server. The assertion
     * reaches this endpoint through the embedded page's script context
     * (postMessage), where an injected script could intercept it — but
     * Drupal refuses to redeem a renewal assertion without the
     * code_verifier registered at the previous redemption, and that
     * verifier lives in the httpOnly session cookie only the app server
     * reads. An intercepted assertion therefore cannot be exchanged for a
     * raw access token anywhere else; the worst an injected script can do
     * with it is POST it back here, which just renews the session normally.
     *
     * This endpoint carries no CSRF token, and the reason is narrower than
     * it once was. The request *does* consume cookie-held authority: it
     * reads the session's PKCE verifier from the httpOnly cookie, spends it
     * at Drupal, and rotates it — so this is not a pure
     * credential-in-body exchange, and a cross-site POST would carry those
     * cookies. What makes a forged request inert is that the attacker
     * cannot supply the other half: a valid, unexpired, unredeemed,
     * renewal-marked assertion, minted only by Drupal for the editor's live
     * session. Without one the exchange is refused before anything is spent
     * — Drupal validates the assertion, and the verifier's challenge,
     * before consuming either. The verifier never leaves the app server, so
     * a forged request can neither learn it nor redirect where it goes; and
     * a renewal-marked assertion is useless anywhere else, including at the
     * activation route, whose activation exchange sends no verifier and is
     * therefore refused for exactly the same reason.
     *
     * Renewal is *continuation*, so it requires a session to continue:
     * without an existing draft session (even an expired one), the request
     * is refused with 400 — starting a session is the preview URL's job,
     * and refusing here keeps this endpoint from doubling as a second
     * activation surface.
     *
     * Continuation is also identity-pinned: if the assertion names a
     * different editor than the running session (the browser's Drupal
     * session changed hands mid-preview — editor A logged out, editor B
     * logged in), the renewal is refused with 409 and the session is left
     * untouched. Without this check the session would silently continue as
     * another user, permissions and attribution included. The refusal is
     * deliberate about what happens next: the session expires on schedule
     * and the recovery lane starts a *new* session as the new editor — a
     * visible fresh start, never a silent swap. Activation intentionally
     * has no such check: a preview URL arriving as a top-level navigation
     * is an explicit new session for whoever holds the Drupal session.
     */
    async renewDraftSession(request: Request): Promise<Response> {
      const body = (await request.json().catch(() => null)) as {
        assertion?: unknown;
      } | null;
      const assertion =
        typeof body?.assertion === 'string' ? body.assertion : null;
      if (!assertion) {
        return new Response('Missing preview assertion.', { status: 422 });
      }

      // Continuation only: no session, nothing to renew (see the docblock).
      const existingSession = await getDraftData();
      if (!existingSession) {
        return new Response(
          'No draft session to renew. Open a preview from Drupal to start one.',
          { status: 400 },
        );
      }

      // Identity pre-check on the *unverified* claims — safe, because it
      // can only refuse: an assertion forged to pass this check still has
      // to pass Drupal's signature verification to mint anything. Checking
      // before the exchange keeps a mismatched (still valid, single-use)
      // assertion unconsumed and avoids minting a token nobody will use.
      const claimedSub = decodeAssertionClaims(assertion)?.sub;
      if (claimedSub !== existingSession.sub) {
        return new Response(
          'The assertion names a different editor than this draft session. Re-open the preview from Drupal to start a new session.',
          { status: 409 },
        );
      }

      const result = await redeemAssertion(
        assertion,
        getConfig(),
        fetchImpl,
        existingSession.codeVerifier,
      );
      if (!result.ok) {
        return result.response;
      }

      await storeDraftSession(result.draftData);

      return Response.json({
        tokenExpiresAt: result.draftData.tokenExpiresAt,
      });
    },

    async disableDraftMode(): Promise<Response> {
      await adapter.disableDraftFlag();

      // Overwrite the cookies with expired equivalents carrying the
      // original attributes; see buildClearedDraftCookie() for why plain
      // framework deletions leave the CHIPS cookies alive.
      const names = [adapter.draftFlagCookieName, DRAFT_DATA_COOKIE_NAME];
      for (const name of names) {
        if (name) {
          await adapter.setCookie(buildClearedDraftCookie(name));
        }
      }
      // Invoked by POST, so the redirect is a 303 See Other: the browser
      // follows it with a GET, instead of a 307 replaying the POST against
      // the homepage.
      return new Response(null, { status: 303, headers: { Location: '/' } });
    },

    getPublicClient: () => getPublicClient(getConfig()),
    getDraftClient: (draftData) => getDraftClient(getConfig(), draftData),

    async getClient(): Promise<JsonApiClient> {
      const draftData = await getDraftData();
      return draftData && !isDraftSessionExpired(draftData)
        ? getDraftClient(getConfig(), draftData)
        : getPublicClient(getConfig());
    },

    async fetchPage(path: string): Promise<PageResult | null> {
      return fetchPage(path, {
        baseUrl: getConfig().baseUrl,
        draftData: await getDraftData(),
        fetchImpl,
      });
    },
  };
}
