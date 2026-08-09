/**
 * Supported entry point for tools that build on the Canvas CLI.
 *
 * The `canvas` binary is the product; this is the surface other packages are
 * allowed to depend on, so they do not reach into `dist/` by path. It exists for
 * `canvas-fleet`, which distributes one component library to many sites and
 * needs the same API client and discovery code rather than a copy of them.
 *
 * Anything exported here is a compatibility commitment: change it the way you
 * would change any published API, and treat a breaking change as a major of
 * this package. Everything not exported here is private, and may move or
 * disappear without notice.
 *
 * This entry point is deliberately light. The component build and the upload
 * pipeline pull in Vite, Tailwind and their WebAssembly, which costs seconds to
 * load, so they live in `@drupal-canvas/cli/internals/build` and are only paid
 * for by consumers that actually build.
 *
 * ⚠️ Importing this reads `canvas.config.json` and `.env` relative to
 * `process.cwd()` at import time, because the CLI's configuration is a module
 * singleton. Import it after any `process.chdir()`, and treat `setConfig()` as
 * process-global rather than per-instance.
 */

// Re-exported rather than made dependencies: `@drupal-canvas/discovery` and
// `@drupal-canvas/auth` are private workspace packages bundled into this
// package's build, so a consumer outside the monorepo cannot install them.
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

export { parseImportedJsComponents } from './utils/process-component-files.js';

export { updateConfigFromOptions } from './utils/command-helpers.js';

export type {
  AssetLibrary,
  Component,
  UploadedArtifact,
} from './types/Component.js';
export type { Result } from './types/Result.js';
