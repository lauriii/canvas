import {
  buildClearedDraftCookie,
  buildDraftCookie,
} from '@drupal-canvas/headless/server';

import type {
  DraftCookie,
  DraftServerAdapter,
} from '@drupal-canvas/headless/server';

/**
 * Astro has no framework draft mode, so the flag is the SDK's own cookie,
 * set with the same cross-site (CHIPS) attributes as the session data
 * cookie. Unlike Next.js's __prerender_bypass there is no rendering
 * behavior behind it: with every page rendered on demand, the flag only
 * records that a draft session was activated and not yet exited.
 */
export const ASTRO_DRAFT_FLAG_COOKIE_NAME = 'canvas_headless_draft_mode';

/**
 * The slice of AstroCookies the adapter uses, typed structurally rather
 * than as the `AstroCookies` class: a nominal class type (it has #private
 * state) only matches the exact `astro` copy it was imported from, which
 * breaks type-checking whenever the app's dependency tree carries a
 * different Astro install than this package's. Any AstroCookies instance
 * satisfies this shape.
 */
export interface AstroCookieStore {
  get(name: string): { value: string } | undefined;
  set(
    name: string,
    value: string,
    options?: {
      httpOnly?: boolean;
      path?: string;
      sameSite?: 'lax' | 'none' | 'strict' | boolean;
      secure?: boolean;
      partitioned?: boolean;
      expires?: Date;
    },
  ): void;
}

/**
 * The slice of Astro's per-request context the adapter needs — both the
 * `Astro` global (components) and the APIContext (endpoints, middleware)
 * satisfy it.
 */
export interface AstroDraftContext {
  cookies: AstroCookieStore;
  redirect: (path: string) => Response;
}

/**
 * Maps a DraftCookie onto AstroCookies.set(). The attribute names match
 * AstroCookieSetOptions one to one; `partitioned` is supported since Astro
 * 5.17, which is this package's floor.
 */
function setCookie(cookies: AstroCookieStore, cookie: DraftCookie): void {
  const { name, value, ...options } = cookie;
  cookies.set(name, value, options);
}

/**
 * The Astro implementation of the draft server adapter, bound to one
 * request's context. Astro exposes cookies per request rather than through
 * request-scoped globals (Next.js's next/headers), so the adapter — and the
 * draft server around it — is created per request; see ../server.
 *
 * The flag is cleared with an expired cookie carrying the original
 * attributes rather than cookies.delete(): a CHIPS cookie only matches a
 * deletion that states its partition (see buildClearedDraftCookie()).
 */
export function createAstroDraftAdapter(
  context: AstroDraftContext,
): DraftServerAdapter {
  return {
    getCookie: async (name) => context.cookies.get(name)?.value ?? null,
    setCookie: async (cookie) => {
      setCookie(context.cookies, cookie);
    },
    isDraftFlagEnabled: async () =>
      context.cookies.get(ASTRO_DRAFT_FLAG_COOKIE_NAME)?.value === '1',
    enableDraftFlag: async () => {
      setCookie(
        context.cookies,
        buildDraftCookie(ASTRO_DRAFT_FLAG_COOKIE_NAME, '1'),
      );
    },
    disableDraftFlag: async () => {
      setCookie(
        context.cookies,
        buildClearedDraftCookie(ASTRO_DRAFT_FLAG_COOKIE_NAME),
      );
    },
    // The flag cookie above already carries the cross-site attributes, so
    // no draftFlagCookieName re-set pass is needed (that hook exists for
    // frameworks whose own flag cookie ships with same-site defaults).
    redirect: (path) => context.redirect(path),
  };
}
