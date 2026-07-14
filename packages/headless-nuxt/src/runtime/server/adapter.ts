import { getCookie, setCookie } from 'h3';
import {
  buildClearedDraftCookie,
  buildDraftCookie,
} from '@drupal-canvas/headless/server';

import type {
  DraftCookie,
  DraftServerAdapter,
} from '@drupal-canvas/headless/server';
import type { H3Event } from 'h3';

/**
 * Nuxt has no framework draft mode, so the flag is the SDK's own cookie,
 * set with the same cross-site (CHIPS) attributes as the session data
 * cookie. With every draft-relevant response rendered on demand there is
 * no prerender cache to bypass: the flag only records that a draft session
 * was activated and not yet exited.
 */
export const NUXT_DRAFT_FLAG_COOKIE_NAME = 'canvas_headless_draft_mode';

/**
 * Maps a DraftCookie onto h3's setCookie(). The attribute names match
 * h3's CookieSerializeOptions one to one, `partitioned` included.
 */
function applyCookie(event: H3Event, cookie: DraftCookie): void {
  const { name, value, ...options } = cookie;
  setCookie(event, name, value, options);
}

/**
 * The h3 implementation of the draft server adapter, bound to one request
 * event. h3 exposes cookies per event rather than through request-scoped
 * globals, so the adapter — and the draft server around it — is created
 * per request; see ./session.
 *
 * The flag is cleared with an expired cookie carrying the original
 * attributes rather than deleteCookie(): a CHIPS cookie only matches a
 * deletion that states its partition (see buildClearedDraftCookie()).
 */
export function createNuxtDraftAdapter(event: H3Event): DraftServerAdapter {
  return {
    getCookie: async (name) => getCookie(event, name) ?? null,
    setCookie: async (cookie) => {
      applyCookie(event, cookie);
    },
    isDraftFlagEnabled: async () =>
      getCookie(event, NUXT_DRAFT_FLAG_COOKIE_NAME) === '1',
    enableDraftFlag: async () => {
      applyCookie(event, buildDraftCookie(NUXT_DRAFT_FLAG_COOKIE_NAME, '1'));
    },
    disableDraftFlag: async () => {
      applyCookie(event, buildClearedDraftCookie(NUXT_DRAFT_FLAG_COOKIE_NAME));
    },
    // The flag cookie above already carries the cross-site attributes, so
    // no draftFlagCookieName re-set pass is needed (that hook exists for
    // frameworks whose own flag cookie ships with same-site defaults).
    //
    // Relative Location values are valid (RFC 7231) and browsers resolve
    // them against the current origin; the flows only ever redirect to
    // site-relative paths.
    redirect: (path) =>
      new Response(null, { status: 307, headers: { Location: path } }),
  };
}
