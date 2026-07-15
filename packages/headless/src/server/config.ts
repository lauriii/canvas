export interface DraftConfig {
  /**
   * Base URL of the Drupal site, without a trailing slash. Only the app's
   * *server* uses it; anything the editor's browser must reach on Drupal
   * (the standalone renew link) arrives as a signed assertion claim
   * instead, so multi-origin dev topologies need no second URL here.
   */
  baseUrl: string;
}

/**
 * Resolves the draft configuration from the environment, letting explicit
 * overrides win. CANVAS_SITE_URL is required unless overridden. The OAuth
 * client id is not configuration at all: the Canvas Headless module
 * provisions its consumer under a fixed id (see CANVAS_HEADLESS_CLIENT_ID
 * in ../constants).
 */
export function resolveDraftConfig(
  overrides: Partial<DraftConfig> = {},
): DraftConfig {
  const baseUrl = overrides.baseUrl ?? process.env.CANVAS_SITE_URL;

  if (!baseUrl) {
    throw new Error('CANVAS_SITE_URL must be set. See .env.example.');
  }

  return {
    baseUrl: baseUrl.replace(/\/+$/, ''),
  };
}
