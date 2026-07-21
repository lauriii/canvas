import { loadEnv } from 'vite';
import {
  readComponentManifest,
  writeComponentManifest,
} from '@drupal-canvas/headless/components-endpoint';
import { resolveDraftConfig } from '@drupal-canvas/headless/server';
import { canvasComponentRegistry } from '@drupal-canvas/headless/vite';

import type { Plugin } from 'vite';

/**
 * The environment variables the SDK reads through process.env (see
 * resolveDraftConfig()). Vite's own .env loading
 * targets import.meta.env, which the framework-agnostic core cannot read,
 * so the plugin bridges these keys across.
 */
const ENV_KEYS = ['CANVAS_SITE_URL'] as const;

/**
 * The virtual module createComponentMetadataHandlers() imports the
 * production manifest from (see ./virtual.d.ts). Inlining the manifest
 * into the server bundle removes any filesystem assumption from the
 * deployed shape.
 */
const MANIFEST_VIRTUAL_ID =
  'virtual:@drupal-canvas/headless-tanstack-start/manifest';
const RESOLVED_MANIFEST_VIRTUAL_ID = `\0${MANIFEST_VIRTUAL_ID}`;

/**
 * The SDK packages the app's server build must compile rather than
 * externalize; the adapter packages ship TypeScript source.
 */
const SDK_PACKAGES = [
  '@drupal-canvas/headless',
  '@drupal-canvas/headless-react',
  '@drupal-canvas/headless-tanstack-start',
];

/**
 * The Drupal Canvas headless Vite plugin for TanStack Start:
 *
 * - Compiles the SDK packages into the SSR build (`ssr.noExternal`).
 * - Bridges the SDK's environment variables from the project's .env files
 *   into process.env, where the framework-agnostic core reads them.
 * - Generates the component manifest (`.canvas/components.manifest.json`)
 *   at build time and inlines it into the server bundle — in production
 *   the metadata endpoint serves this manifest, so the registry always
 *   describes the deployed build and the built output is self-contained.
 *   A malformed component.yml fails the build; a broken registry never
 *   ships silently.
 * - Registers the shared Vite component implementation registry, which updates
 *   when local components are added, removed, or renamed during development.
 *
 * ```ts
 * // vite.config.ts
 * import { canvas } from '@drupal-canvas/headless-tanstack-start/vite';
 * export default defineConfig({
 *   plugins: [canvas(), tanstackStart(), viteReact()],
 * });
 * ```
 */
export function canvas(): Plugin[] {
  return [canvasComponentRegistry(), canvasTanStackStart()];
}

function canvasTanStackStart(): Plugin {
  let projectRoot = process.cwd();
  let isDev = false;
  let manifestWritten = false;

  return {
    name: '@drupal-canvas/headless-tanstack-start',
    enforce: 'pre',

    config() {
      return {
        // Vite's dev-server CORS middleware answers preflights before the
        // component metadata route, and its localhost-only origin policy
        // omits Access-Control-Allow-Origin for the Drupal editor. Let the
        // route own its claim-bound CORS contract instead.
        server: {
          cors: false,
        },
        ssr: {
          noExternal: [...SDK_PACKAGES],
        },
      };
    },

    configResolved(config) {
      projectRoot = config.root;
      isDev = config.command === 'serve';
      // Vite's loadEnv merges the project's .env files with the actual
      // process environment, real environment variables winning — so
      // assigning the result back preserves the usual precedence.
      const env = loadEnv(config.mode, projectRoot, '');
      for (const key of ENV_KEYS) {
        if (env[key] !== undefined) {
          process.env[key] = env[key];
        }
      }
      if (isDev) {
        resolveDraftConfig();
      }
    },

    async buildStart() {
      // Once per build, not once per Vite environment (client, ssr).
      if (isDev || manifestWritten) {
        return;
      }
      manifestWritten = true;
      const manifest = await writeComponentManifest({ projectRoot });
      this.info(
        `Wrote the component manifest: ${manifest.components.length} component(s), ${manifest.warnings.length} warning(s).`,
      );
      for (const warning of manifest.warnings) {
        this.warn(warning.message);
      }
    },

    resolveId(id) {
      return id === MANIFEST_VIRTUAL_ID
        ? RESOLVED_MANIFEST_VIRTUAL_ID
        : undefined;
    },

    async load(id) {
      if (id !== RESOLVED_MANIFEST_VIRTUAL_ID) {
        return undefined;
      }
      if (isDev) {
        return 'export default null;';
      }
      // buildStart wrote the manifest before modules load.
      const manifest = await readComponentManifest({ projectRoot });
      return `export default ${JSON.stringify(manifest)};`;
    },
  };
}

export default canvas;
