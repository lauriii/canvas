import type { DraftCookie } from './cookies';

/**
 * The framework surface the draft server flows need — the whole of it.
 *
 * A new framework adapter (SvelteKit, Nuxt, ...) implements this interface
 * plus route mounting; everything else (assertion redemption, cookie
 * contents, claim validation, identity pinning) lives in the
 * framework-agnostic flows. The one client-side primitive, refreshing
 * server-derived data after a renewal, belongs to the draft session state
 * machine's options instead (see `../client`).
 */
export interface DraftServerAdapter {
  /** Reads a request cookie value; null when absent. */
  getCookie(name: string): Promise<string | null>;
  /** Sets a response cookie with the given attributes. */
  setCookie(cookie: DraftCookie): Promise<void>;
  /** Whether the framework's draft/preview flag is on for this request. */
  isDraftFlagEnabled(): Promise<boolean>;
  /** Turns the framework's draft/preview flag on. */
  enableDraftFlag(): Promise<void>;
  /** Turns the framework's draft/preview flag off. */
  disableDraftFlag(): Promise<void>;
  /**
   * Name of the framework's own draft-flag cookie when it sets one that
   * must be re-set with cross-site (CHIPS) attributes to survive inside the
   * embedding iframe (Next.js: '__prerender_bypass'). Omit when the
   * framework has no such cookie.
   */
  draftFlagCookieName?: string;
  /**
   * Framework redirect. May throw (the Next.js and SvelteKit style) or
   * return a redirect Response; flows `return adapter.redirect(path)`
   * either way.
   */
  redirect(path: string): Response;
}
