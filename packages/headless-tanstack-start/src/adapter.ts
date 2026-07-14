import {
  buildClearedDraftCookie,
  buildDraftCookie,
} from '@drupal-canvas/headless/server';
import { getCookie, setCookie } from '@tanstack/react-start/server';

import type {
  DraftCookie,
  DraftServerAdapter,
} from '@drupal-canvas/headless/server';

/**
 * TanStack Start has no framework draft mode, so the flag is the SDK's own
 * cookie, set with the same cross-site (CHIPS) attributes as the session
 * data cookie. With every page rendered on demand there is no prerender
 * cache to bypass: the flag only records that a draft session was
 * activated and not yet exited.
 */
export const TANSTACK_DRAFT_FLAG_COOKIE_NAME = 'canvas_headless_draft_mode';

/**
 * Maps a DraftCookie onto TanStack Start's setCookie(). The attribute
 * names match its CookieSerializeOptions one to one, `partitioned`
 * included.
 */
function applyCookie(cookie: DraftCookie): void {
  const { name, value, ...options } = cookie;
  setCookie(name, value, options);
}

/**
 * The TanStack Start implementation of the draft server adapter. The
 * cookie helpers from @tanstack/react-start/server are request-scoped
 * globals (like Next.js's next/headers), so one adapter — and one draft
 * server around it — serves every request; see ./server.
 *
 * The flag is cleared with an expired cookie carrying the original
 * attributes rather than deleteCookie(): a CHIPS cookie only matches a
 * deletion that states its partition (see buildClearedDraftCookie()).
 */
export const tanstackDraftAdapter: DraftServerAdapter = {
  getCookie: async (name) => getCookie(name) ?? null,
  setCookie: async (cookie) => {
    applyCookie(cookie);
  },
  isDraftFlagEnabled: async () =>
    getCookie(TANSTACK_DRAFT_FLAG_COOKIE_NAME) === '1',
  enableDraftFlag: async () => {
    applyCookie(buildDraftCookie(TANSTACK_DRAFT_FLAG_COOKIE_NAME, '1'));
  },
  disableDraftFlag: async () => {
    applyCookie(buildClearedDraftCookie(TANSTACK_DRAFT_FLAG_COOKIE_NAME));
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
