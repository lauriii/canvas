/**
 * @file
 * Framework-neutral preparation for rendering Canvas json-render documents.
 */

import {
  hasCanvasPreviewContentRegion,
  isCanvasComponentTreeEmpty,
} from './render';
import { CanvasComponentTreeSchema } from './schema';

import type { CanvasComponentTree } from './schema';

/** A Canvas document prepared for a framework json-render implementation. */
export interface PreparedCanvasJsonRender {
  /** A copy whose component keys remain stable across API responses. */
  spec: CanvasComponentTree | null;
  /** Whether editor markers and empty drop areas should render. */
  preview: boolean;
  /** Whether the renderer must wrap the complete document in a content region. */
  needsOuterRegion: boolean;
  /** Whether an outer content region needs an empty drop area. */
  emptyRegion: boolean;
  /** Stable key for the framework renderer's root component. */
  rootRenderKey: string;
}

/** Rendering context that is intentionally separate from the document. */
export interface PrepareCanvasJsonRenderOptions {
  /** Emit Canvas editor boundaries and empty drop areas. */
  preview?: boolean;
}

/**
 * Validates and prepares a Canvas document without importing a framework
 * renderer. Component UUID keys and element references are preserved.
 */
export function prepareCanvasJsonRender(
  tree: CanvasComponentTree | null,
  options: PrepareCanvasJsonRenderOptions = {},
): PreparedCanvasJsonRender {
  const preview = options.preview === true;
  const validatedTree =
    tree === null ? null : CanvasComponentTreeSchema.parse(tree);
  const needsOuterRegion =
    preview && !hasCanvasPreviewContentRegion(validatedTree);

  return {
    spec: validatedTree,
    preview,
    needsOuterRegion,
    emptyRegion: needsOuterRegion && isCanvasComponentTreeEmpty(validatedTree),
    rootRenderKey: validatedTree?.root || 'canvas-empty-tree',
  };
}
