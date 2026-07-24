/**
 * @file
 * Browser-only, framework-neutral discovery and geometry measurement for
 * Drupal Canvas preview markup.
 */

export {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
  getCanvasTemplateMarkerAttributes,
  parseCanvasCommentMarker,
  type CanvasMarker,
  type CanvasMarkerPosition,
  type CanvasTemplateMarkerAttributes,
} from './markers';
export {
  getCanvasStackDirection,
  measureCanvasBoundary,
  measureCanvasGeometry,
  unionCanvasRects,
} from './measure';
export { createCanvasGeometryObserver } from './observer';
export { isCanvasGeometrySnapshot } from './validation';
export type {
  CanvasBoundary,
  CanvasBoundaryType,
  CanvasGeometry,
  CanvasGeometryObserver,
  CanvasGeometryObserverOptions,
  CanvasGeometryRoot,
  CanvasMarkerFormat,
  CanvasRect,
  CanvasStackDirection,
  DiscoverCanvasBoundariesOptions,
  MeasureCanvasGeometryOptions,
} from './types';
