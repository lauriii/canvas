import { createMiddleware } from '@tanstack/react-start';

/**
 * Merges the `frame-ancestors` directive into every response's
 * Content-Security-Policy, restricting who may embed the app — the
 * TanStack Start counterpart of the header withCanvas() configures for
 * Next.js. Merged, not set: a policy the app already sends (default-src,
 * script-src, ...) is preserved; only the frame-ancestors directive is
 * this SDK's to own. Wire it into the app's global request middleware:
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
 * The environment is read per request, not at module load, so the dev
 * server picks up .env changes the same way the rest of the SDK does; see
 * resolveFrameAncestors() for the source list rules.
 */
export const cspMiddleware = createMiddleware().server(async ({ next }) => {
  // Imported lazily: createStart's configuration is an isomorphic module
  // graph, and the server helpers must stay out of the client bundle. The
  // .server() callback only ever runs server-side.
  const [
    { getResponseHeader, setResponseHeader },
    { mergeFrameAncestors, resolveFrameAncestors },
  ] = await Promise.all([
    import('@tanstack/react-start/server'),
    import('@drupal-canvas/headless/server'),
  ]);
  // The handler chain runs first so a policy it sets is merged, not lost.
  const result = await next();
  setResponseHeader(
    'Content-Security-Policy',
    // Joined with ', ': the standard serialization of a policy list in
    // one header field.
    mergeFrameAncestors(
      getResponseHeader('Content-Security-Policy') ?? null,
      resolveFrameAncestors(),
    ).join(', '),
  );
  return result;
});
