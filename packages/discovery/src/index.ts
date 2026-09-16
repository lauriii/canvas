export { discoverCanvasProject, JS_EXTENSIONS } from './discover';
export {
  ASSET_EXTENSIONS,
  AUDIO_EXTENSIONS,
  FONT_EXTENSIONS,
  IMAGE_EXTENSIONS,
  SVG_EXTENSIONS,
  VIDEO_EXTENSIONS,
} from './asset-extensions';
export { DEFAULT_CANVAS_CONFIG, resolveCanvasConfig } from './config';
export type { CanvasConfigWarning } from './config';
export { detectHeadlessSdk } from './detect-headless-sdk';
export { getContentEntityReferenceTarget } from './content-entity-reference';
export type { ContentEntityReferenceTarget } from './content-entity-reference';
export {
  findDuplicateMachineNames,
  loadComponentMetadata,
  loadComponentsMetadata,
} from './metadata';
export {
  ComponentMetadataValidationError,
  componentMetadataDiagnosticFromError,
  componentMetadataDiagnosticFromParts,
  formatComponentMetadataDiagnostics,
  normalizeComponentMetadata,
  parseComponentMetadata,
  validateComponentMetadataEnvelope,
} from './metadata-validation';
export type {
  ComponentMetadataDiagnostic,
  ParsedComponentMetadata,
} from './metadata-validation';
export type {
  CanvasConfig,
  CanvasLegacyRegionConfig,
  CanvasSyncConfig,
  ComponentMetadata,
  DiscoveredComponent,
  DiscoveredContentTemplate,
  DiscoveredPage,
  DiscoveredPageTemplate,
  DiscoveryOptions,
  DiscoveryResult,
  DiscoveryWarning,
  DiscoveryWarningCode,
} from './types';
