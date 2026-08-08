/**
 * Supported entry point for tools that build on the Canvas CLI.
 *
 * The `canvas` binary is the product; this is the small surface other packages
 * are allowed to depend on, so they do not reach into `dist/` by path. It exists
 * for `canvas-fleet`, which distributes one component library to many sites and
 * needs the same build, upload and API code rather than a copy of it.
 *
 * Anything exported here is a compatibility commitment: change it the way you
 * would change any published API, and treat a breaking change as a major of
 * this package. Everything not exported here is private, and may move or
 * disappear without notice.
 */

// Re-exported rather than made a dependency: `@drupal-canvas/discovery` and
// `@drupal-canvas/auth` are private workspace packages that are bundled into
// this package's build, so a consumer outside the monorepo cannot install them.
export { discoverCanvasProject } from '@drupal-canvas/discovery';
export type {
  DiscoveredComponent,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
export { getTokenEntry } from '@drupal-canvas/auth';

export { ApiService } from './services/api.js';
export type { ApiOptions } from './services/api.js';

export { getConfig, getDefaultScope, setConfig } from './config.js';
export type { Config } from './config.js';

export { buildCanvasProject } from './utils/build-project.js';
export type {
  BuiltComponent,
  CanvasProjectBuildResult,
} from './utils/build-project.js';

export {
  prepareGlobalAssetLibraryUpdate,
  uploadComponents,
} from './utils/prepare-push.js';

export {
  syncManifestArtifacts,
  updateGlobalAssetLibraryForPush,
} from './commands/push.js';

export { parseImportedJsComponents } from './utils/process-component-files.js';

export { updateConfigFromOptions } from './utils/command-helpers.js';

export type {
  AssetLibrary,
  Component,
  UploadedArtifact,
} from './types/Component.js';
export type { Result } from './types/Result.js';
