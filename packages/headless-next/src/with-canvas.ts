import path from 'node:path';
import { writeComponentManifest } from '@drupal-canvas/headless/components-endpoint';
import {
  mergeFrameAncestors,
  resolveDraftConfig,
} from '@drupal-canvas/headless/server';

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

/**
 * The environment variable naming the origins allowed to embed the app. A
 * whitespace- or comma-separated list, because a Drupal site can be reached
 * on more than one origin the editor's browser might use: a multi-origin
 * topology where the app server and the browser see Drupal differently, one
 * frontend previewed from both a staging and a production Drupal, or a
 * multisite serving several editor hostnames. Unset, the origin of
 * CANVAS_SITE_URL is used, which is correct for the single-origin
 * deployment and needs no configuration at all.
 */
const EDITOR_ORIGINS_ENV_VARIABLE = 'CANVAS_EDITOR_ORIGINS';

type NextConfigInput =
  | NextConfig
  | ((
      phase: string,
      context: { defaultConfig: NextConfig },
    ) => NextConfig | Promise<NextConfig>);

type HeaderRule = Awaited<
  ReturnType<NonNullable<NextConfig['headers']>>
>[number];

/**
 * The host shapes a Canvas editor can be served on: LDH domain labels, a
 * dotted-quad IPv4 literal, or a bracketed IPv6 literal.
 *
 * Parsing with `URL` is not sufficient on its own. The URL host parser
 * permits characters that are meaningless in a hostname but meaningful in a
 * policy: `*` makes the source a wildcard matching every subdomain (and
 * `https://*` matches every origin), while `;` ends the directive and `,`
 * ends the whole policy, so a configured value could append a directive of
 * its own. Matching the host against this pattern, and taking the port from
 * `URL` (which parses it as digits), is what keeps a configured value to the
 * one origin it names.
 */
const EDITOR_HOST_PATTERN =
  /^(?:\[[0-9A-Fa-f:.]+\]|(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}|[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*)$/;

/**
 * One configured value as a CSP host-source, or null when it is not one.
 *
 * Values carrying credentials, using a scheme the editor cannot be served
 * over, or naming a host outside EDITOR_HOST_PATTERN are dropped rather than
 * repaired.
 */
function toEditorOrigin(value: string): string | null {
  try {
    const url = new URL(value);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
      return null;
    }
    if (url.username || url.password) {
      return null;
    }
    if (!EDITOR_HOST_PATTERN.test(url.hostname)) {
      return null;
    }
    return url.origin;
  } catch {
    return null;
  }
}

/** The deduplicated editor origins, in configured order. */
function resolveEditorOrigins(): string[] {
  const configured =
    process.env[EDITOR_ORIGINS_ENV_VARIABLE] ??
    process.env.CANVAS_SITE_URL ??
    '';
  return [
    ...new Set(
      configured
        .split(/[\s,]+/)
        .filter(Boolean)
        .map(toEditorOrigin)
        .filter((origin): origin is string => origin !== null),
    ),
  ];
}

/**
 * The `frame-ancestors` source list: 'self' always, plus every configured
 * editor origin. Resolved at build time from configuration rather than per
 * request from the draft session, so one static rule serves every host
 * identically — a rule conditioned on the session cookie is evaluated
 * differently by Next.js's own server and by hosted routing layers, which
 * silently dropped the editor origin wherever the two disagreed.
 */
function resolveFrameAncestors(): string {
  const origins = resolveEditorOrigins();
  if (origins.length === 0) {
    console.warn(
      `[canvas] Neither ${EDITOR_ORIGINS_ENV_VARIABLE} nor CANVAS_SITE_URL ` +
        'names a usable http(s) origin, so no Canvas editor may embed this ' +
        'app and previews will be refused. Set ' +
        `${EDITOR_ORIGINS_ENV_VARIABLE} to the origin editors reach Drupal on.`,
    );
  }
  return ["'self'", ...origins].join(' ');
}

/** Merges the directive into one header rule, leaving its other headers. */
function mergeRuleFrameAncestors(
  rule: HeaderRule,
  frameAncestors: string,
): HeaderRule {
  return {
    ...rule,
    headers: rule.headers.map((header) =>
      header.key.toLowerCase() === CSP_HEADER
        ? {
            ...header,
            value: mergeFrameAncestors(header.value, frameAncestors).join(', '),
          }
        : header,
    ),
  };
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
 * - Sends `Content-Security-Policy: frame-ancestors`, admitting the app
 *   itself and every origin named by CANVAS_EDITOR_ORIGINS (default: the
 *   origin of CANVAS_SITE_URL). Merged into the app's own `headers()` rules,
 *   so no directive of theirs is discarded and an application-owned
 *   frame-ancestors stays authoritative.
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

    const transpilePackages = [
      ...new Set([
        ...(config.transpilePackages ?? []),
        '@drupal-canvas/headless',
        '@drupal-canvas/headless-next',
        '@drupal-canvas/headless-react',
      ]),
    ];

    const userHeaders = config.headers;
    const headers: NonNullable<NextConfig['headers']> = async () => {
      // When several rules match a path and set the same key, Next.js keeps
      // the LAST value, so the catch-all goes first and every app rule that
      // sets a Content-Security-Policy gets the directive merged into its
      // value. On paths the app's own rules match, the app's merged value
      // wins; everywhere else the catch-all applies. No app directive is
      // discarded, and an app-owned frame-ancestors stays authoritative.
      const frameAncestors = resolveFrameAncestors();
      const userRules = userHeaders ? await userHeaders() : [];
      return [
        {
          source: '/:path*',
          headers: [
            {
              key: 'Content-Security-Policy',
              value: mergeFrameAncestors(null, frameAncestors).join(', '),
            },
          ],
        },
        ...userRules.map((rule) =>
          mergeRuleFrameAncestors(rule, frameAncestors),
        ),
      ];
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
      headers,
      webpack,
    };
  };
}
