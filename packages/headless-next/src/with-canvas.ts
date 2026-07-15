import { DRAFT_DATA_COOKIE_NAME } from '@drupal-canvas/headless';
import { writeComponentManifest } from '@drupal-canvas/headless/components-endpoint';
import {
  hasFrameAncestors,
  mergeFrameAncestors,
  resolveFrameAncestors,
} from '@drupal-canvas/headless/server';

import type { NextConfig } from 'next';

// Mirrors PHASE_PRODUCTION_BUILD from next/constants without importing it:
// the value is a stable public constant, and next/constants has no exports
// map entry resolvable from a raw-TS package in every consumer setup.
const PHASE_PRODUCTION_BUILD = 'phase-production-build';
const CSP_HEADER = 'content-security-policy';

// Next.js header rules can capture a named group from a cookie and insert it
// into a header value. The cookie parser has already URL-decoded the JSON.
// Capture only a URL-serialized HTTP(S) origin from the signed renewal URL;
// the restricted host and port grammar cannot inject CSP delimiters.
const DRAFT_EDITOR_ORIGIN_COOKIE_PATTERN = String.raw`.*"renewUrl":"(?<editorOrigin>https?://(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?)(?:/[^"\\]*)?".*`;

type NextConfigInput =
  | NextConfig
  | ((
      phase: string,
      context: { defaultConfig: NextConfig },
    ) => NextConfig | Promise<NextConfig>);

type HeaderRule = Awaited<
  ReturnType<NonNullable<NextConfig['headers']>>
>[number];

const draftSessionCookieMatch = {
  type: 'cookie' as const,
  key: DRAFT_DATA_COOKIE_NAME,
  value: DRAFT_EDITOR_ORIGIN_COOKIE_PATTERN,
};

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

function ruleNeedsDraftEditorOrigin(rule: HeaderRule): boolean {
  return rule.headers.some(
    (header) =>
      header.key.toLowerCase() === CSP_HEADER &&
      !hasFrameAncestors(header.value),
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
 * - Adds the SDK packages to `transpilePackages` (they ship raw
 *   TypeScript).
 * - Sends a `Content-Security-Policy: frame-ancestors` header. Responses
 *   are 'self'-only by default; a draft session also admits the exact
 *   editor origin carried by its signed renewal URL. An application-owned
 *   frame-ancestors directive remains authoritative.
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

    if (
      phase === PHASE_PRODUCTION_BUILD &&
      !process.env[MANIFEST_ENV_VARIABLE]
    ) {
      const manifest = await writeComponentManifest({
        projectRoot: options.projectRoot,
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
        '@drupal-canvas/discovery',
      ]),
    ];

    const userHeaders = config.headers;
    const headers: NonNullable<NextConfig['headers']> = async () => {
      // When several header rules match a path and set the same key,
      // Next.js keeps the LAST value — it does not emit repeated fields.
      // So the SDK's catch-all rule goes first, and every user rule that
      // sets a Content-Security-Policy gets the frame-ancestors directive
      // merged into its value: on paths the app's own CSP rules match,
      // the app's (merged) value wins; everywhere else the catch-all
      // applies. A second cookie-matched rule admits the signed editor
      // origin only for requests carrying a draft session. Either way no
      // app directive is discarded.
      const frameAncestors = resolveFrameAncestors();
      const userRules = userHeaders ? await userHeaders() : [];
      const mergedUserRules = userRules.flatMap((rule) => {
        const fallback = mergeRuleFrameAncestors(rule, frameAncestors);
        if (!ruleNeedsDraftEditorOrigin(rule)) {
          return [fallback];
        }
        return [
          fallback,
          mergeRuleFrameAncestors(
            {
              ...rule,
              has: [...(rule.has ?? []), draftSessionCookieMatch],
            },
            "'self' :editorOrigin",
          ),
        ];
      });
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
        {
          source: '/:path*',
          has: [draftSessionCookieMatch],
          headers: [
            {
              key: 'Content-Security-Policy',
              value: "frame-ancestors 'self' :editorOrigin",
            },
          ],
        },
        ...mergedUserRules,
      ];
    };

    return {
      ...config,
      transpilePackages,
      env: {
        ...config.env,
        // Present on build-phase evaluations; undefined in dev, where the
        // endpoint scans the codebase live.
        ...(process.env[MANIFEST_ENV_VARIABLE]
          ? { [MANIFEST_ENV_VARIABLE]: process.env[MANIFEST_ENV_VARIABLE] }
          : {}),
      },
      headers,
    };
  };
}
