import { createDraftRouteHandlers } from "@drupal-canvas/headless-next";

/**
 * Renews the draft session in place from a fresh assertion (JSON body
 * `{assertion}`).
 */
export const POST = createDraftRouteHandlers().draftRenew.POST;
