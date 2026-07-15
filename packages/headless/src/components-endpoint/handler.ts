import { resolveDraftConfig, verifyAssertionByRedemption } from '../server';

import type { DraftConfig } from '../server';
import type { ComponentMetadataPayload } from './component-metadata';

// Re-exported so adapters importing this subpath need nothing else.
export type { ComponentMetadataPayload };

export interface ComponentMetadataHandlerOptions {
  /**
   * Configuration provider; default resolves the environment per request
   * (CANVAS_SITE_URL).
   */
  config?: () => Pick<DraftConfig, 'baseUrl'>;
  /**
   * Whether to serve the build-time manifest (production) instead of
   * scanning the codebase live (development). Frameworks signal the mode
   * differently (NODE_ENV, import.meta.env.PROD, nitro's import.meta.dev),
   * so adapters pass their own; the default reads NODE_ENV.
   */
  isProduction?: boolean;
  /**
   * Provides the production manifest. Every adapter inlines the manifest
   * into the server bundle at build time (a Vite virtual module, Nitro's
   * `virtual`, Next.js env injection) and passes a loader here — the
   * handler itself never touches the filesystem, deliberately: a dynamic
   * file read in a route's module graph makes bundlers' file tracers
   * sweep the whole project into the route's output. A custom setup can
   * pass a loader around readComponentManifest() (exported from the
   * components-endpoint entry) instead. Missing or null in production is
   * a hard 500, never an empty registry.
   */
  loadManifest?: () => Promise<ComponentMetadataPayload | null>;
  /**
   * Runs the live component scan development answers with (typically
   * buildComponentMetadataPayload() from the components-endpoint entry).
   * Injected for the same reason as loadManifest: discovery reads the
   * filesystem dynamically, and adapters whose bundlers trace routes
   * (Next.js) must keep it out of the production route graph — see the
   * @drupal-canvas/headless-next wrapper for the dead-code-elimination
   * pattern.
   */
  scanComponents?: () => Promise<ComponentMetadataPayload>;
}

/**
 * The component metadata endpoint as framework-free fetch handlers
 * (Request → Response): GET answers the codebase's component registry to
 * the Drupal Canvas instance. Framework adapters mount it on their routing
 * systems.
 *
 * Protection is proof-by-redemption: the caller presents a Drupal-minted
 * preview assertion as a Bearer token, and the endpoint verifies it by
 * redeeming it at Drupal's own token endpoint — only the embedding Drupal
 * can mint one, assertions are single-use, and the minted access token is
 * discarded. The Drupal module calls this endpoint server-to-server, so it
 * does not expose a browser CORS contract; the assertion is the request's
 * authorization boundary.
 *
 * In production the payload comes from the manifest written at build time
 * (see ./manifest) — component sources are typically absent at runtime, and
 * the registry should describe the deployed build. In development every
 * request scans live, so a newly added component is visible on the next
 * fetch. A missing production manifest is a hard 500, never an empty
 * registry.
 */
export function createComponentMetadataHandler(
  options: ComponentMetadataHandlerOptions = {},
): {
  GET: (request: Request) => Promise<Response>;
} {
  const getConfig = options.config ?? (() => resolveDraftConfig());
  const isProduction = () =>
    options.isProduction ?? process.env.NODE_ENV === 'production';

  const json = (
    status: number,
    body: unknown,
    headers: Record<string, string> = {},
  ) =>
    Response.json(body, {
      status,
      // no-store on every response: without it an intermediary could cache
      // an authenticated 200 body without its Authorization header and
      // serve it to a caller that never presented an assertion.
      headers: { 'Cache-Control': 'no-store', ...headers },
    });

  return {
    async GET(request: Request): Promise<Response> {
      let config: ReturnType<typeof getConfig>;
      try {
        config = getConfig();
      } catch (error) {
        return json(500, {
          error: 'configuration_error',
          message: error instanceof Error ? error.message : String(error),
        });
      }

      const authorization = request.headers.get('authorization');
      const assertion = authorization?.match(/^Bearer\s+(\S+)$/i)?.[1] ?? null;
      if (!assertion) {
        return json(
          401,
          {
            error: 'missing_assertion',
            message:
              'Provide a Drupal preview assertion as a Bearer token. Assertions are single-use; mint a fresh one per request.',
          },
          { 'WWW-Authenticate': 'Bearer' },
        );
      }

      const verification = await verifyAssertionByRedemption(assertion, config);
      if (!verification.ok) {
        return json(verification.status, {
          error:
            verification.status === 502
              ? 'drupal_unreachable'
              : 'invalid_assertion',
          message: verification.message,
        });
      }

      let payload: ComponentMetadataPayload;
      try {
        if (isProduction()) {
          const manifest = options.loadManifest
            ? await options.loadManifest()
            : null;
          if (manifest === null) {
            return json(500, {
              error: 'manifest_missing',
              message:
                'No component manifest found. Build the app with the Canvas integration enabled so the manifest is generated at build time.',
            });
          }
          payload = manifest;
        } else if (options.scanComponents) {
          payload = await options.scanComponents();
        } else {
          return json(500, {
            error: 'configuration_error',
            message:
              'No component scanner is wired for development. Pass scanComponents to createComponentMetadataHandler().',
          });
        }
      } catch (error) {
        return json(500, {
          error: 'discovery_failed',
          message: error instanceof Error ? error.message : String(error),
        });
      }

      return json(200, payload);
    },
  };
}
