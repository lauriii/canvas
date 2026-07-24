/** A DOM root that can contain Canvas preview markers. */
export type CanvasGeometryRoot = Document | DocumentFragment | Element;

/** The editable Canvas structure represented by a marker pair. */
export type CanvasBoundaryType = 'component' | 'slot' | 'region';

/** The markup syntax that supplied a marker pair. */
export type CanvasMarkerFormat = 'comment' | 'template';

/** A matched pair of start and end markers in document order. */
export interface CanvasBoundary {
  type: CanvasBoundaryType;
  id: string;
  start: Node;
  end: Node;
  markerFormat: CanvasMarkerFormat;
  componentUuid?: string;
  slotName?: string;
}

/** A serializable rectangle expressed in viewport CSS pixels. */
export interface CanvasRect {
  top: number;
  right: number;
  bottom: number;
  left: number;
  width: number;
  height: number;
}

/** The primary direction in which a slot container lays out its children. */
export type CanvasStackDirection =
  | 'vertical'
  | 'vertical-grid'
  | 'vertical-flex'
  | 'horizontal-flex'
  | 'horizontal-grid';

/** Serializable geometry and identity for one Canvas boundary. */
export interface CanvasGeometry {
  type: CanvasBoundaryType;
  id: string;
  rect: CanvasRect;
  markerFormat: CanvasMarkerFormat;
  componentUuid?: string;
  slotName?: string;
  stackDirection?: CanvasStackDirection;
}

export interface DiscoverCanvasBoundariesOptions {
  /** Discover Drupal's `<!-- canvas-… -->` markers. Defaults to true. */
  commentMarkers?: boolean;
  /** Discover `<template data-canvas-marker>` markers. Defaults to true. */
  templateMarkers?: boolean;
}

export type MeasureCanvasGeometryOptions = DiscoverCanvasBoundariesOptions;

export interface CanvasGeometryObserverOptions extends MeasureCanvasGeometryOptions {
  root: CanvasGeometryRoot;
  onChange: (geometry: CanvasGeometry[]) => void;
}

export interface CanvasGeometryObserver {
  /** Measures the current document without invoking `onChange`. */
  measure: () => CanvasGeometry[];
  /** Schedules discovery and measurement for the next animation frame. */
  refresh: () => void;
  /** Removes every observer and event listener created by this instance. */
  disconnect: () => void;
}
