import { createDraftRouteHandlers } from "@drupal-canvas/headless-next";

/**
 * Enables draft mode from a signed Drupal preview assertion
 * (`?assertion=<jwt>`). Configuration comes from the environment
 * (DRUPAL_BASE_URL and friends; see .env.example).
 */
export const GET = createDraftRouteHandlers().draft.GET;
