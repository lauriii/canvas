import { createComponentMetadataHandler } from "@drupal-canvas/headless-next";

// Segment config must be literal in the route file (Next.js reads it
// statically): the discovery pipeline needs Node, and the response is
// auth-gated per request — never prerendered.
export const runtime = "nodejs";
export const dynamic = "force-dynamic";

/**
 * Exposes the app's component registry to the embedding Drupal Canvas
 * site, protected by proof-by-redemption (a fresh Drupal preview assertion
 * per request). In production this serves the manifest withCanvas() wrote
 * at build time; in development it scans the codebase live.
 */
export const { GET } = createComponentMetadataHandler();
