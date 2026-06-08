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
export { findDuplicateMachineNames, loadComponentsMetadata } from './metadata';
export type {
  CanvasConfig,
  CanvasSyncConfig,
  ComponentMetadata,
  DiscoveredComponent,
  DiscoveredContentTemplate,
  DiscoveredPage,
  DiscoveredRegion,
  DiscoveryOptions,
  DiscoveryResult,
  DiscoveryWarning,
  DiscoveryWarningCode,
} from './types';
