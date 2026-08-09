/**
 * Supported entry point for the component build and the upload pipeline.
 *
 * Split from `@drupal-canvas/cli/internals` because everything here pulls in
 * Vite, Tailwind and their WebAssembly. Importing this costs seconds; importing
 * the other entry point costs milliseconds. Consumers that only talk to the
 * Canvas API should not pay for a toolchain they never run.
 *
 * The same compatibility commitment applies as for the main entry point.
 */

export { buildCanvasProject } from '../utils/build-project.js';
export type {
  BuiltComponent,
  CanvasProjectBuildResult,
} from '../utils/build-project.js';

export {
  prepareGlobalAssetLibraryUpdate,
  uploadComponents,
} from '../utils/prepare-push.js';

export {
  syncManifestArtifacts,
  updateGlobalAssetLibraryForPush,
} from '../commands/push.js';
