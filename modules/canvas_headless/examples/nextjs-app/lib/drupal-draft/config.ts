export interface DrupalDraftConfig {
  /**
   * Base URL of the Drupal site, without a trailing slash. Only the app's
   * *server* uses it; anything the editor's browser must reach on Drupal
   * (the standalone renew link) arrives as a signed assertion claim
   * instead, so multi-origin dev topologies need no second URL here.
   */
  baseUrl: string;
  /**
   * OAuth client id of the consumer the canvas_headless module provisions.
   * A public client — there is no client secret anywhere in this app: the
   * signed preview assertion is the credential (RFC 7523).
   */
  clientId: string;
  /**
   * Origins allowed to embed this app, parsed from the same env var that
   * feeds the CSP frame-ancestors allowlist. The renewal protocol validates
   * postMessage events against this list in both directions.
   */
  embedderOrigins: string[];
}

export function getDrupalDraftConfig(): DrupalDraftConfig {
  const baseUrl = process.env.DRUPAL_BASE_URL;

  if (!baseUrl) {
    throw new Error("DRUPAL_BASE_URL must be set. See .env.example.");
  }

  // The frame-ancestors value is a space-separated mix of origins and CSP
  // keywords ('self', 'none'); only the origins are postMessage peers.
  const embedderOrigins = (process.env.DRAFT_ALLOWED_FRAME_ANCESTORS || "")
    .split(/\s+/)
    .filter((token) => /^https?:\/\//.test(token));

  return {
    baseUrl: baseUrl.replace(/\/+$/, ""),
    clientId: "canvas_headless",
    embedderOrigins,
  };
}
