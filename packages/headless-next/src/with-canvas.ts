import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { writeComponentManifest } from '@drupal-canvas/headless/components-endpoint';
import { resolveDraftConfig } from '@drupal-canvas/headless/server';

import { writeComponentRegistryModule } from './component-registry';
import { watchComponentRegistry } from './component-registry-watcher';

import type { NextConfig } from 'next';

// Mirrors PHASE_PRODUCTION_BUILD from next/constants without importing it:
// the value is a stable public constant, and next/constants has no exports
// map entry resolvable from a raw-TS package in every consumer setup.
const PHASE_PRODUCTION_BUILD = 'phase-production-build';
const PHASE_DEVELOPMENT_SERVER = 'phase-development-server';
const COMPONENTS_MODULE_ID =
  '@drupal-canvas/headless-next-generated-components';

/** The middleware entry point the app must mount, and where Next.js looks. */
const MIDDLEWARE_SPECIFIER = '@drupal-canvas/headless-next/middleware';
const MIDDLEWARE_DIRECTORIES = ['.', 'src'];
// Next.js 16 renamed the convention from `middleware` to `proxy`; 15 knows
// only the old name. Each is resolved by its own export name.
const MIDDLEWARE_FILE_NAMES = ['proxy', 'middleware'];
const CSP_HEADER = 'content-security-policy';
/** Print-once marker for the missing-middleware warning. */
const WARNED_ENV_VARIABLE = 'CANVAS_MIDDLEWARE_WARNING_SHOWN';

const MOUNT_INSTRUCTIONS =
  'Create proxy.ts (middleware.ts, exporting `middleware`, before Next.js 16) ' +
  'in the project root, or in src/, containing:\n\n' +
  `  export { canvasMiddleware as proxy } from '${MIDDLEWARE_SPECIFIER}';\n\n` +
  '  export const config = {\n' +
  "    matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],\n" +
  '  };\n';

type NextConfigInput =
  | NextConfig
  | ((
      phase: string,
      context: { defaultConfig: NextConfig },
    ) => NextConfig | Promise<NextConfig>);

/**
 * Warns when no file that could be the app's middleware mentions this
 * package. The `frame-ancestors` policy is sent from middleware, and Next.js
 * allows one per project, so only the app can install it: an upgrade that
 * skipped that step would leave the app framable by anyone, while previews
 * kept working, which is the one failure nothing else surfaces.
 *
 * ponytail: a warning, not a build gate, and deliberately a shallow one. What
 * the app needs is not a file but a handler that actually runs on document
 * responses, and that depends on the export name, the `config.matcher`, and
 * the module graph — none of which a static check can settle. Proving it
 * needs the running app, so this only catches the common case (no file at
 * all) and never blocks a build it cannot reason about.
 */
async function warnWhenMiddlewareMissing(
  projectRoot: string,
  pageExtensions: string[],
): Promise<void> {
  // Next.js evaluates the config several times, including from worker
  // processes that inherit this variable, so the warning is printed once.
  if (process.env[WARNED_ENV_VARIABLE]) {
    return;
  }
  for (const directory of MIDDLEWARE_DIRECTORIES) {
    for (const fileName of MIDDLEWARE_FILE_NAMES) {
      for (const extension of pageExtensions) {
        const file = path.join(
          projectRoot,
          directory,
          `${fileName}.${extension}`,
        );
        const source = await readFile(file, 'utf8').catch(() => null);
        if (source?.includes(MIDDLEWARE_SPECIFIER)) {
          return;
        }
      }
    }
  }
  process.env[WARNED_ENV_VARIABLE] = '1';
  console.warn(
    `[canvas] No middleware mounting ${MIDDLEWARE_SPECIFIER} was found, so ` +
      'this build sends no Content-Security-Policy frame-ancestors header ' +
      `and anyone may embed the app. ${MOUNT_INSTRUCTIONS}`,
  );
}

/**
 * Fails the build when the app configures Content-Security-Policy through
 * `next.config`'s `headers()`.
 *
 * Those rules are applied by the hosting platform's routing layer, after
 * the middleware has run, and they replace rather than merge — so an app
 * CSP configured there silently overwrites the session-aware policy for
 * every path it matches, and the Canvas editor's iframe is refused again.
 * The app's own directives belong on the response handed to
 * applyCanvasHeaders(), where they are merged.
 */
async function assertNoConfiguredCsp(config: NextConfig): Promise<void> {
  const rules = config.headers ? await config.headers() : [];
  const offending = rules.find((rule) =>
    rule.headers.some((header) => header.key.toLowerCase() === CSP_HEADER),
  );
  if (!offending) {
    return;
  }
  throw new Error(
    `[canvas] The headers() rule for "${offending.source}" sets a ` +
      'Content-Security-Policy. Hosting platforms apply next.config header ' +
      'rules after the middleware runs and replace the whole value, so this ' +
      'would drop the frame-ancestors directive that admits the Canvas ' +
      'editor. Set the policy on the response instead, in the middleware:\n\n' +
      `  import { applyCanvasHeaders } from '${MIDDLEWARE_SPECIFIER}';\n` +
      "  import { NextResponse } from 'next/server';\n\n" +
      "  import type { NextRequest } from 'next/server';\n\n" +
      '  export function proxy(request: NextRequest) {\n' +
      '    const response = NextResponse.next();\n' +
      "    response.headers.set('Content-Security-Policy', \"default-src 'self'\");\n" +
      '    return applyCanvasHeaders(request, response);\n' +
      '  }\n',
  );
}

export interface WithCanvasOptions {
  /**
   * The app project root the component manifest is generated from.
   * Default: process.cwd() (where `next build` runs).
   */
  projectRoot?: string;
}

/**
 * The environment variable the manifest travels in, from the build phase
 * into the server bundle: Next.js inlines `env` config values at build
 * time, so the component metadata route serves the registry without any
 * filesystem read (a dynamic file read in a route's module graph makes
 * Next.js's file tracer sweep the whole project into the route's output).
 * During the build itself the variable doubles as the generate-once
 * marker: Next.js evaluates the config several times, including from
 * worker processes, which inherit it from the main build process.
 */
export const MANIFEST_ENV_VARIABLE = 'CANVAS_COMPONENT_MANIFEST_JSON';

/**
 * Wraps a Next.js config with the Drupal Canvas headless integration:
 *
 * - Generates the component manifest at build time, before compilation
 *   starts, and inlines it into the server bundle through Next.js env
 *   injection — in production the metadata endpoint serves this manifest,
 *   so the registry always describes the deployed build and no file
 *   outside the build output is needed at runtime. A malformed
 *   component.yml fails the build; a broken registry never ships
 *   silently.
 * - Watches local component definitions in development and updates the
 *   generated implementation registry when components are added or removed.
 * - Adds the SDK packages to `transpilePackages` (the adapter packages
 *   ship TypeScript source).
 * - Refuses a `Content-Security-Policy` configured through `headers()`, and
 *   warns when nothing looks like it mounts this package's middleware. The
 *   header is sent from middleware (see ./middleware.ts), which only the app
 *   can install.
 *
 * ```ts
 * // next.config.ts
 * import { withCanvas } from '@drupal-canvas/headless-next';
 * export default withCanvas();
 * ```
 */
export function withCanvas(
  nextConfig: NextConfigInput = {},
  options: WithCanvasOptions = {},
) {
  return async (
    phase: string,
    context: { defaultConfig: NextConfig },
  ): Promise<NextConfig> => {
    const config: NextConfig =
      typeof nextConfig === 'function'
        ? await nextConfig(phase, context)
        : nextConfig;
    const projectRoot = path.resolve(options.projectRoot ?? process.cwd());
    if (
      phase === PHASE_DEVELOPMENT_SERVER ||
      phase === PHASE_PRODUCTION_BUILD
    ) {
      await assertNoConfiguredCsp(config);
      await warnWhenMiddlewareMissing(
        projectRoot,
        config.pageExtensions ?? context.defaultConfig.pageExtensions ?? [],
      );
    }
    if (phase === PHASE_DEVELOPMENT_SERVER) {
      resolveDraftConfig();
    }
    const componentRegistryPath =
      await writeComponentRegistryModule(projectRoot);
    if (phase === PHASE_DEVELOPMENT_SERVER) {
      watchComponentRegistry(projectRoot);
    }
    const turbopackComponentRegistryPath = `./${path
      .relative(projectRoot, componentRegistryPath)
      .split(path.sep)
      .join('/')}`;

    if (
      phase === PHASE_PRODUCTION_BUILD &&
      !process.env[MANIFEST_ENV_VARIABLE]
    ) {
      const manifest = await writeComponentManifest({
        projectRoot,
      });
      // Set only after the write succeeded: a failed generation must not
      // be skipped on the next config evaluation.
      process.env[MANIFEST_ENV_VARIABLE] = JSON.stringify(manifest);
      console.info(
        `[canvas] Wrote the component manifest: ${manifest.components.length} component(s), ${manifest.warnings.length} warning(s).`,
      );
      for (const warning of manifest.warnings) {
        console.warn(`[canvas] ${warning.message}`);
      }
    }

    const transpilePackages = [
      ...new Set([
        ...(config.transpilePackages ?? []),
        '@drupal-canvas/headless',
        '@drupal-canvas/headless-next',
        '@drupal-canvas/headless-react',
      ]),
    ];

    const userWebpack = config.webpack;
    const webpack: NonNullable<NextConfig['webpack']> = (
      webpackConfig,
      webpackOptions,
    ) => {
      const resolvedConfig = userWebpack
        ? userWebpack(webpackConfig, webpackOptions)
        : webpackConfig;
      resolvedConfig.resolve ??= {};
      resolvedConfig.resolve.alias = {
        ...(resolvedConfig.resolve.alias ?? {}),
        [COMPONENTS_MODULE_ID]: componentRegistryPath,
      };
      return resolvedConfig;
    };

    return {
      ...config,
      transpilePackages,
      turbopack: {
        ...config.turbopack,
        resolveAlias: {
          ...config.turbopack?.resolveAlias,
          [COMPONENTS_MODULE_ID]: turbopackComponentRegistryPath,
        },
      },
      env: {
        ...config.env,
        // Present on build-phase evaluations; undefined in dev, where the
        // endpoint scans the codebase live.
        ...(process.env[MANIFEST_ENV_VARIABLE]
          ? { [MANIFEST_ENV_VARIABLE]: process.env[MANIFEST_ENV_VARIABLE] }
          : {}),
      },
      webpack,
    };
  };
}
