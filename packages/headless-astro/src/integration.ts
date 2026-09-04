import { fileURLToPath } from 'node:url';
import { loadEnv } from 'vite';
import { CANVAS_COMPONENT_PREVIEW_PATH } from '@drupal-canvas/headless';
import {
  readComponentManifest,
  writeComponentManifest,
} from '@drupal-canvas/headless/components-endpoint';
import { resolveDraftConfig } from '@drupal-canvas/headless/server';
import { canvasComponentRegistry } from '@drupal-canvas/headless/vite';

import type { AstroIntegration } from 'astro';
import type { Plugin as VitePlugin } from 'vite';

/**
 * The virtual module the components route imports the production manifest
 * from (see ../virtual.d.ts). Inlining the manifest into the server bundle
 * removes any filesystem assumption from the deployed shape: shipping only
 * `dist/` works, serverless targets work, and no run-from-the-app-root
 * requirement exists in production. In dev the module is null and the
 * endpoint scans the codebase live.
 */
const MANIFEST_VIRTUAL_ID = 'virtual:@drupal-canvas/headless-astro/manifest';
const RESOLVED_MANIFEST_VIRTUAL_ID = `\0${MANIFEST_VIRTUAL_ID}`;

function manifestPlugin(
  getState: () => {
    isDev: boolean;
    projectRoot: string;
  },
): VitePlugin {
  return {
    name: '@drupal-canvas/headless-astro:manifest',
    enforce: 'pre',
    resolveId(id) {
      return id === MANIFEST_VIRTUAL_ID
        ? RESOLVED_MANIFEST_VIRTUAL_ID
        : undefined;
    },
    async load(id) {
      if (id !== RESOLVED_MANIFEST_VIRTUAL_ID) {
        return undefined;
      }
      const { isDev, projectRoot } = getState();
      if (isDev) {
        return 'export default null;';
      }
      // astro:build:start wrote the manifest before compilation began.
      const manifest = await readComponentManifest({ projectRoot });
      return `export default ${JSON.stringify(manifest)};`;
    },
  };
}

/**
 * The environment variables the SDK reads through process.env (see
 * resolveDraftConfig()). Astro's own .env loading
 * targets import.meta.env, which the framework-agnostic core cannot read,
 * so the integration bridges these keys across.
 */
const ENV_KEYS = ['CANVAS_SITE_URL'] as const;

export interface CanvasIntegrationOptions {
  /**
   * Mount the draft and component metadata routes automatically. Default
   * true; disable to mount the route modules (the `routes/*` subpath
   * exports) at paths of your own.
   */
  injectRoutes?: boolean;
  /**
   * The route the component metadata endpoint is injected at.
   */
  componentsRoutePath?: string;
  /**
   * Build the static profile: a fully prerendered site with no Canvas
   * server surface. Skips every injected route (the draft flows, the
   * component metadata endpoint, and the component preview page) and the
   * CSP middleware, so `output: 'static'` builds without an SSR adapter.
   * The environment bridge, the component implementation registry, and
   * the build-time manifest remain, so `fetchPage()` still renders Canvas
   * content at build time. Draft preview and the editor integration then
   * run against a separate server-rendered deployment of the same app.
   */
  static?: boolean;
}

/**
 * The Drupal Canvas headless integration for Astro:
 *
 * - Injects the draft session routes (/api/draft, /api/draft/renew,
 *   /api/disable-draft) and the component metadata endpoint
 *   (/api/canvas/components). All are server-rendered
 *   (`prerender = false`), so they work from a fully static project too.
 * - Registers the CSP `frame-ancestors` middleware. Responses are
 *   'self'-only by default; draft sessions also admit the exact editor
 *   origin from the signed renewal URL.
 * - Bundles the SDK packages into the SSR build (`vite.ssr.noExternal`;
 *   the adapter package ships TypeScript source).
 * - Bridges the SDK's environment variables from Astro's .env files into
 *   process.env, where the framework-agnostic core reads them.
 * - Generates the component manifest (`.canvas/components.manifest.json`)
 *   at build time, before compilation starts, and inlines it into the
 *   server bundle — in production the metadata endpoint serves this
 *   manifest, so the registry always describes the deployed build and no
 *   file outside `dist/` is needed at runtime. A malformed component.yml
 *   fails the build; a broken registry never ships silently.
 * - Registers the shared Vite component implementation registry, which updates
 *   when local components are added, removed, or renamed during development.
 *
 * ```ts
 * // astro.config.mjs
 * import canvas from '@drupal-canvas/headless-astro/integration';
 * export default defineConfig({
 *   adapter: node({ mode: 'standalone' }),
 *   integrations: [canvas()],
 * });
 * ```
 *
 * With `static: true` the integration builds the static profile instead:
 * no routes, no middleware, just build-time rendering (see
 * CanvasIntegrationOptions). Without it, an `output: 'static'` config
 * without an adapter is refused, because the injected server routes could
 * never run.
 */
export function canvas(
  options: CanvasIntegrationOptions = {},
): AstroIntegration {
  const componentsRoutePath =
    options.componentsRoutePath ?? '/api/canvas/components';

  // Captured at astro:config:done for the build-time manifest.
  let projectRoot: string | undefined;

  return {
    name: '@drupal-canvas/headless-astro',
    hooks: {
      'astro:config:setup': ({
        injectRoute,
        addMiddleware,
        updateConfig,
        config,
        command,
        logger,
      }) => {
        // Vite's loadEnv merges the project's .env files with the actual
        // process environment, real environment variables winning — so
        // assigning the result back preserves the usual precedence.
        const env = loadEnv(
          command === 'dev' ? 'development' : 'production',
          fileURLToPath(config.root),
          '',
        );
        for (const key of ENV_KEYS) {
          if (env[key] !== undefined) {
            process.env[key] = env[key];
          }
        }
        if (command === 'dev') {
          try {
            resolveDraftConfig();
          } catch (error) {
            logger.error(
              error instanceof Error ? error.message : String(error),
            );
            throw error;
          }
        }

        const configRoot = fileURLToPath(config.root);
        updateConfig({
          // Astro's dev server rejects cross-site subresource requests before
          // routing. Let the browser reach the metadata handler in development;
          // that route still owns CORS and binds authenticated responses to the
          // editor origin in the accepted assertion. Production host validation
          // remains unchanged.
          ...(command === 'dev' ? { security: { allowedDomains: [{}] } } : {}),
          vite: {
            // Vite's CORS middleware otherwise answers the authorization
            // preflight before Astro routes it, using a localhost-only origin
            // policy. The metadata endpoint must answer its own claim-bound
            // CORS contract.
            ...(command === 'dev' ? { server: { cors: false } } : {}),
            // Whether this build's pages are prerendered. The draft
            // activation route reads it to refuse a session that pages
            // baked at build time could never show (see ./routes/draft).
            // Dev always renders per request, so dev stays false.
            define: {
              __CANVAS_STATIC_OUTPUT__: JSON.stringify(
                command !== 'dev' && config.output === 'static',
              ),
            },
            plugins: [
              canvasComponentRegistry(),
              manifestPlugin(() => ({
                isDev: command === 'dev',
                projectRoot: projectRoot ?? configRoot,
              })),
            ],
            ssr: {
              noExternal: [
                '@drupal-canvas/headless',
                '@drupal-canvas/headless-astro',
              ],
            },
          },
        });

        // The static profile ends here: no per-request server surface
        // exists, so injecting routes or middleware would only force an
        // adapter requirement the profile exists to avoid.
        if (options.static === true) {
          return;
        }

        addMiddleware({
          entrypoint: '@drupal-canvas/headless-astro/middleware',
          order: 'pre',
        });

        injectRoute({
          pattern: CANVAS_COMPONENT_PREVIEW_PATH,
          entrypoint:
            '@drupal-canvas/headless-astro/ComponentPreviewPage.astro',
        });

        if (options.injectRoutes !== false) {
          injectRoute({
            pattern: '/api/draft',
            entrypoint: '@drupal-canvas/headless-astro/routes/draft',
          });
          injectRoute({
            pattern: '/api/draft/renew',
            entrypoint: '@drupal-canvas/headless-astro/routes/draft-renew',
          });
          injectRoute({
            pattern: '/api/disable-draft',
            entrypoint: '@drupal-canvas/headless-astro/routes/disable-draft',
          });
          injectRoute({
            pattern: componentsRoutePath,
            entrypoint: '@drupal-canvas/headless-astro/routes/components',
          });
        }
      },

      'astro:config:done': ({ config }) => {
        projectRoot = fileURLToPath(config.root);

        // The default profile injected server routes; a static build
        // without an adapter has nothing to run them, and Astro's own
        // failure ("NoAdapterInstalled") would not say what to do about
        // Canvas. Fail with the two ways out instead.
        if (
          options.static !== true &&
          config.output === 'static' &&
          !config.adapter
        ) {
          throw new Error(
            "The canvas() integration injects server routes that an `output: 'static'` build without an adapter can never run. Either add an SSR adapter (for example @astrojs/node) or pass `static: true` to the canvas() integration to build the static profile without them.",
          );
        }
      },

      'astro:build:start': async ({ logger }) => {
        const manifest = await writeComponentManifest({ projectRoot });
        logger.info(
          `Wrote the component manifest: ${manifest.components.length} component(s), ${manifest.warnings.length} warning(s).`,
        );
        for (const warning of manifest.warnings) {
          logger.warn(warning.message);
        }
      },
    },
  };
}

export default canvas;
