export interface DraftConfig {
  /**
   * Base URL of the Drupal site, without a trailing slash. Only the app's
   * *server* uses it; anything the editor's browser must reach on Drupal
   * (the standalone renew link) arrives as a signed assertion claim
   * instead, so multi-origin dev topologies need no second URL here.
   */
  baseUrl: string;
  /**
   * Origins allowed to embed the app, parsed from the same env var that
   * feeds the CSP frame-ancestors allowlist. The renewal protocol validates
   * postMessage events against this list in both directions, and the
   * component metadata endpoint admits browser callers from these origins.
   */
  embedderOrigins: string[];
}

/**
 * Resolves the draft configuration from the environment, letting explicit
 * overrides win. Environment variables: DRUPAL_BASE_URL (required unless
 * overridden) and DRAFT_ALLOWED_FRAME_ANCESTORS (the embedder origin
 * allowlist). The OAuth client id is not configuration at all: the Canvas
 * Headless module provisions its consumer under a fixed id (see
 * CANVAS_HEADLESS_CLIENT_ID in ../constants).
 */
export function resolveDraftConfig(
  overrides: Partial<DraftConfig> = {},
): DraftConfig {
  const baseUrl = overrides.baseUrl ?? process.env.DRUPAL_BASE_URL;

  if (!baseUrl) {
    throw new Error('DRUPAL_BASE_URL must be set. See .env.example.');
  }

  return {
    baseUrl: baseUrl.replace(/\/+$/, ''),
    embedderOrigins:
      overrides.embedderOrigins ??
      parseEmbedderOrigins(process.env.DRAFT_ALLOWED_FRAME_ANCESTORS),
  };
}

/**
 * Extracts postMessage-peer origins from a CSP frame-ancestors value: the
 * value is a space-separated mix of origins and CSP keywords ('self',
 * 'none'); only the origins are postMessage peers.
 */
export function parseEmbedderOrigins(
  frameAncestors: string | undefined,
): string[] {
  return (frameAncestors || '')
    .split(/\s+/)
    .filter((token) => /^https?:\/\//.test(token));
}
