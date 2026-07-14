/**
 * @file
 * Component metadata exposure: the discovery-backed payload builder and the
 * build-time manifest it is served from in production. Node-only (the
 * discovery pipeline reads the filesystem) — this subpath must never be
 * re-exported from the package root or the server entry, which browser
 * bundles and edge runtimes may load.
 */

export {
  buildComponentMetadataPayload,
  COMPONENT_METADATA_PAYLOAD_VERSION,
  type BuildComponentMetadataOptions,
  type ComponentMetadataEntry,
  type ComponentMetadataPayload,
  type ComponentMetadataWarning,
} from './component-metadata';
export {
  COMPONENT_MANIFEST_PATH,
  readComponentManifest,
  type ComponentManifestOptions,
} from './manifest-read';
export { writeComponentManifest } from './manifest';
export {
  createComponentMetadataHandler,
  type ComponentMetadataHandlerOptions,
} from './handler';
