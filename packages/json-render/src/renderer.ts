/**
 * @file
 * Framework-neutral helpers for implementing Canvas json-render renderers.
 */

export {
  prepareCanvasJsonRender,
  type PreparedCanvasJsonRender,
  type PrepareCanvasJsonRenderOptions,
} from './prepare';
export {
  CANVAS_MARKER_ELEMENT,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  areCanvasComponentTreeChildrenEmpty,
  componentElementFromName,
  componentNameFromElement,
  findCanvasComponent,
  getCanvasComponentRenderData,
  getCanvasComponentTreeElementChildren,
  getCanvasComponentTreeElementSlots,
  getJsonRenderComponentData,
  hasCanvasPreviewContentRegion,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
  reportMissingCanvasMarkerComponentUuid,
  type CanvasComponentRenderData,
} from './render';
export {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  formatCanvasCommentMarker,
  getCanvasTemplateMarkerAttributes,
  type CanvasMarker,
} from '@drupal-canvas/preview-geometry';
export { CANVAS_SLOT_PLACEHOLDER_ELEMENT } from './constants';
