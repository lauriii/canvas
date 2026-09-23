import { createMiddleware } from '@tanstack/react-start';

/**
 * Merges the `frame-ancestors` directive into every response's
 * Content-Security-Policy, restricting who may embed the app.
 * Merged, not set: a policy the app already sends (default-src,
 * script-src, ...) is preserved. An application-owned frame-ancestors
 * directive remains authoritative. Wire it into the app's global request
 * middleware:
 *
 * ```ts
 * // src/start.ts
 * import { createStart } from '@tanstack/react-start';
 * import { cspMiddleware } from '@drupal-canvas/headless-tanstack-start/middleware';
 *
 * export const startInstance = createStart(() => ({
 *   requestMiddleware: [cspMiddleware],
 * }));
 * ```
 *
 * The shared resolver uses CANVAS_EDITOR_ORIGINS when set, or the site and
 * draft editor origins by default, always including 'self'.
 */
export const cspMiddleware = createMiddleware().server(async ({ next }) => {
  // Imported lazily: createStart's configuration is an isomorphic module
  // graph, and the server helpers must stay out of the client bundle. The
  // .server() callback only ever runs server-side.
  const [
    { getResponseHeader, setResponseHeader },
    { mergeFrameAncestors, resolveFrameAncestors },
    { getDraftData },
  ] = await Promise.all([
    import('@tanstack/react-start/server'),
    import('@drupal-canvas/headless/server'),
    import('./server'),
  ]);
  const draftData = await getDraftData();
  // The handler chain runs first so a policy it sets is merged, not lost.
  const result = await next();
  // A route can set headers through Start's response helpers or directly on
  // its returned Response. Preserve both before adding the Canvas policy.
  const policies = [
    getResponseHeader('Content-Security-Policy'),
    result.response.headers.get('Content-Security-Policy'),
  ].filter((policy): policy is string => !!policy);
  const policy = mergeFrameAncestors(
    [...new Set(policies)],
    resolveFrameAncestors(draftData),
  ).join(', ');
  try {
    result.response.headers.set('Content-Security-Policy', policy);
  } catch {
    // Native redirects and fetch responses can have immutable headers.
    result.response = new Response(result.response.body, result.response);
    result.response.headers.set('Content-Security-Policy', policy);
  }
  setResponseHeader('Content-Security-Policy', policy);
  return result;
});
