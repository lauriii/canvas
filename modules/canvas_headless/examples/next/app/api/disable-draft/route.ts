import { createDraftRouteHandlers } from "@drupal-canvas/headless-next";

/**
 * Disables draft mode, clears the draft session, and returns home. POST,
 * not GET: exiting draft mode changes state, and a GET endpoint reached by
 * links would be eligible for prefetching — a framework or browser prefetch
 * could silently end the session. The banner submits a plain form here
 * instead.
 */
export const POST = createDraftRouteHandlers().disableDraft.POST;
