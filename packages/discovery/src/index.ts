export { discoverCanvasProject, JS_EXTENSIONS } from './discover';
export {
  ASSET_EXTENSIONS,
  AUDIO_EXTENSIONS,
  FONT_EXTENSIONS,
  IMAGE_EXTENSIONS,
  SVG_EXTENSIONS,
  VIDEO_EXTENSIONS,
} from './asset-extensions';
export { resolveCanvasConfig } from './config';
export { findDuplicateMachineNames, loadComponentsMetadata } from './metadata';
export type {
  CanvasConfig,
  ComponentMetadata,
  DiscoveredComponent,
  DiscoveredPage,
  DiscoveryOptions,
  DiscoveryResult,
  DiscoveryWarning,
  DiscoveryWarningCode,
} from './types';
