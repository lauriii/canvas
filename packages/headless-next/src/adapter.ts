import { cookies, draftMode } from 'next/headers';
import { redirect } from 'next/navigation';

import type { DraftServerAdapter } from '@drupal-canvas/headless/server';

/**
 * The cookie Next.js Draft Mode uses to bypass static rendering.
 */
export const NEXT_DRAFT_MODE_COOKIE_NAME = '__prerender_bypass';

/**
 * The Next.js implementation of the draft server adapter: cookies() and
 * draftMode() from next/headers, redirect() from next/navigation. The
 * DraftCookie shape matches Next's ResponseCookie, so cookies pass through
 * unchanged.
 */
export const nextDraftAdapter: DraftServerAdapter = {
  draftFlagCookieName: NEXT_DRAFT_MODE_COOKIE_NAME,
  getCookie: async (name) => (await cookies()).get(name)?.value ?? null,
  setCookie: async (cookie) => {
    (await cookies()).set(cookie);
  },
  isDraftFlagEnabled: async () => (await draftMode()).isEnabled,
  enableDraftFlag: async () => {
    (await draftMode()).enable();
  },
  disableDraftFlag: async () => {
    (await draftMode()).disable();
  },
  // redirect() throws (its return type is never), which satisfies the
  // adapter's Response-returning contract; Next converts the control-flow
  // error into the redirect response.
  redirect: (path) => redirect(path),
};
