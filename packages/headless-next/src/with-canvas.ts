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

const CSP_HEADER = 'content-security-policy';

type NextConfigInput =
  | NextConfig
  | ((
      phase: string,
      context: { defaultConfig: NextConfig },
    ) => NextConfig | Promise<NextConfig>);

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
 * - Rejects static CSP header rules: mount the request-time Canvas
 *   middleware/proxy separately and supply the app's CSP on its response.
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

    const userHeaders = config.headers;
    const headers: NonNullable<NextConfig['headers']> = async () => {
      const userRules = userHeaders ? await userHeaders() : [];
      if (
        userRules.some((rule) =>
          rule.headers.some(
            (header) => header.key.toLowerCase() === CSP_HEADER,
          ),
        )
      ) {
        throw new Error(
          '[canvas] Move Content-Security-Policy out of next.config headers(). ' +
            'Set the complete policy on the response passed to applyCanvasHeaders() ' +
            'from @drupal-canvas/headless-next/middleware in proxy.ts (Next.js 16) ' +
            'or middleware.ts (Next.js 15). Static header rules can replace the ' +
            'request-time policy rather than merge with it.',
        );
      }
      return userRules;
    };
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
      headers,
      webpack,
    };
  };
}
