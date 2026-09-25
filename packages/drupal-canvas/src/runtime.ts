/**
 * @file
 * The runtime marker that identifies the environments in which the legacy
 * `getPageData()`, `getSiteData()`, and `new JsonApiClient()` APIs keep
 * working: Drupal-rendered Code Components and Canvas Workbench previews.
 *
 * The marker is established explicitly by those integrations before any Code
 * Component module is evaluated. It is a compatibility check, not a security
 * boundary: the presence of `window`, a backend URL, or a user-created
 * `drupalSettings` object is deliberately not treated as proof of either
 * environment.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

/** The environments that support the legacy runtime APIs. */
export type CanvasRuntimeEnvironment = 'drupal' | 'workbench';

/**
 * The global property holding the marker. Integrations that cannot import this
 * package (Drupal's classic hydration script) set it directly.
 */
export const CANVAS_RUNTIME_GLOBAL = '__drupalCanvasRuntime';

interface CanvasRuntimeMarker {
  environment: CanvasRuntimeEnvironment;
}

type RuntimeGlobal = typeof globalThis & {
  [CANVAS_RUNTIME_GLOBAL]?: CanvasRuntimeMarker;
};

/**
 * Declares the current runtime environment. Called by Drupal and Workbench
 * integrations, never by Code Components or headless applications.
 */
export function declareCanvasRuntime(
  environment: CanvasRuntimeEnvironment,
): void {
  (globalThis as RuntimeGlobal)[CANVAS_RUNTIME_GLOBAL] = { environment };
}

/**
 * Returns the declared runtime environment, or `null` outside Drupal and
 * Workbench.
 */
export function getCanvasRuntime(): CanvasRuntimeEnvironment | null {
  const marker = (globalThis as RuntimeGlobal)[CANVAS_RUNTIME_GLOBAL];
  if (
    marker !== null &&
    typeof marker === 'object' &&
    (marker.environment === 'drupal' || marker.environment === 'workbench')
  ) {
    return marker.environment;
  }
  return null;
}

/**
 * Whether the legacy runtime APIs are supported in the current environment.
 */
export function isLegacyRuntimeSupported(): boolean {
  return getCanvasRuntime() !== null;
}
