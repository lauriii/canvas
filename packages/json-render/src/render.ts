/**
 * @file
 * Framework-neutral helpers for rendering Canvas json-render documents.
 * Framework bindings use these helpers to resolve app-owned components
 * consistently and to preserve Canvas instance identity.
 */

import { parseCanvasCommentMarker } from '@drupal-canvas/preview-geometry';

import {
  CANVAS_MARKER_ELEMENT,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  CANVAS_SLOT_PLACEHOLDER_ELEMENT,
  DRUPAL_MARKUP_ELEMENT,
  RENDERLESS_CONTAINER_ELEMENT,
} from './constants';

import type {
  CanvasComponentTree,
  CanvasComponentTreeElement,
  CanvasComponentTreeSlot,
  JsonValue,
} from './schema';

export {
  CANVAS_MARKER_ELEMENT,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
} from './constants';

/**
 * Component metadata separated from the props an app component receives.
 */
export interface CanvasComponentRenderData {
  /** The Canvas component ID, for example `js.hello-card`, or another wire type. */
  element: string;
  /** The app registry key, for example `hello-card`. */
  componentName: string;
  /** The Canvas component instance UUID supplied as its document element key. */
  componentUuid?: string;
  /** Props safe to pass to the app component. */
  props: Record<string, JsonValue>;
}

/**
 * Returns one slot's ordered element keys.
 */
export function normalizeCanvasComponentTreeSlot(
  slot: CanvasComponentTreeSlot | undefined,
): string[] {
  return slot ?? [];
}

/**
 * Whether a slot has no Canvas child components and needs an editor placeholder.
 * Markup without Canvas component boundaries is a component default/example,
 * which editor rendering replaces with the empty-slot placeholder.
 */
export function isCanvasComponentTreeSlotEmpty(
  tree: CanvasComponentTree,
  slot: CanvasComponentTreeSlot | undefined,
): boolean {
  const children = normalizeCanvasComponentTreeSlot(slot);
  return (
    children.length === 0 ||
    children.every((key) => isCanvasSlotDefaultChild(tree, key))
  );
}

/** Whether a top-level Canvas region has no rendered page content. */
export function isCanvasComponentTreeEmpty(
  tree: CanvasComponentTree | null,
): boolean {
  return (
    tree === null ||
    tree.root === '' ||
    isCanvasComponentTreeElementEmpty(tree, tree.root)
  );
}

/** Whether a collection of element keys contains no rendered page content. */
export function areCanvasComponentTreeChildrenEmpty(
  tree: CanvasComponentTree,
  childKeys: string[],
): boolean {
  return childKeys.every((key) => isCanvasComponentTreeElementEmpty(tree, key));
}

/**
 * Whether one element contains no rendered page content. Structural containers
 * inherit their children's emptiness; application component types are populated.
 */
function isCanvasComponentTreeElementEmpty(
  tree: CanvasComponentTree,
  key: string,
): boolean {
  const element = tree.elements[key];
  if (!element) {
    return true;
  }
  switch (element.type) {
    case RENDERLESS_CONTAINER_ELEMENT:
    case CANVAS_PREVIEW_CONTENT_REGION_ELEMENT:
      return getCanvasComponentTreeElementChildren(element).every((childKey) =>
        isCanvasComponentTreeElementEmpty(tree, childKey),
      );
    case DRUPAL_MARKUP_ELEMENT:
      return (
        typeof element.props.markup !== 'string' ||
        element.props.markup.trim() === ''
      );
    case CANVAS_SLOT_PLACEHOLDER_ELEMENT:
      return true;
    case CANVAS_MARKER_ELEMENT:
    default:
      return false;
  }
}

/** Whether a tree contains an explicit page content region. */
export function hasCanvasPreviewContentRegion(
  tree: CanvasComponentTree | null,
): boolean {
  if (tree === null || tree.root === '') {
    return false;
  }
  return hasCanvasPreviewContentRegionElement(tree, tree.root);
}

/** Whether one subtree contains the routed page content region. */
function hasCanvasPreviewContentRegionElement(
  tree: CanvasComponentTree,
  key: string,
): boolean {
  const element = tree.elements[key];
  if (!element) {
    return false;
  }
  if (element.type === CANVAS_PREVIEW_CONTENT_REGION_ELEMENT) {
    return true;
  }
  return getCanvasComponentTreeElementChildren(element).some((childKey) =>
    hasCanvasPreviewContentRegionElement(tree, childKey),
  );
}

/** Whether one slot child is preview-only structure or default markup. */
function isCanvasSlotDefaultChild(
  tree: CanvasComponentTree,
  key: string,
): boolean {
  const element = tree.elements[key];
  if (!element) {
    return false;
  }
  if (element.type === CANVAS_SLOT_PLACEHOLDER_ELEMENT) {
    return true;
  }
  if (element.type !== 'drupal-markup') {
    return false;
  }
  if (typeof element.props.markup === 'string') {
    for (const [, comment] of element.props.markup.matchAll(
      /<!--([\s\S]*?)-->/g,
    )) {
      if (parseCanvasCommentMarker(comment)?.type === 'component') {
        return false;
      }
    }
  }
  return getCanvasComponentTreeElementChildren(element).every((childKey) =>
    isCanvasSlotDefaultChild(tree, childKey),
  );
}

/** Gets every child key from an element in render order. */
export function getCanvasComponentTreeElementChildren(
  element: CanvasComponentTreeElement,
): string[] {
  return [
    ...element.children,
    ...Object.values(element.slots ?? {}).flatMap((slot) =>
      normalizeCanvasComponentTreeSlot(slot),
    ),
  ];
}

/** Gets the default and named slots of one json-render element. */
export function getCanvasComponentTreeElementSlots(
  element: CanvasComponentTreeElement,
): Array<[string, string[]]> {
  const namedSlots = new Set(Object.keys(element.slots ?? {}));
  namedSlots.delete('default');

  return [
    ...(element.children.length > 0
      ? ([['default', element.children]] as Array<[string, string[]]>)
      : []),
    ...[...namedSlots].map((name): [string, string[]] => [
      name,
      element.slots?.[name] ?? [],
    ]),
  ];
}

/**
 * Gets the app registry key from a Canvas JavaScript component wire type.
 *
 * json-render preserves the `js.` component source ID and machine name.
 */
export function componentNameFromElement(element: string): string | null {
  return element.startsWith('js.') && element.length > 3
    ? element.slice(3)
    : null;
}

/**
 * Converts a component.yml machine name to its Canvas JavaScript component ID.
 */
export function componentElementFromName(componentName: string): string {
  return `js.${componentName}`;
}

/** Resolves exact component types and machine names. */
export function findCanvasComponent<T>(
  components: Record<string, T>,
  data: Pick<CanvasComponentRenderData, 'componentName' | 'element'>,
): T | undefined {
  return (
    (Object.hasOwn(components, data.element)
      ? components[data.element]
      : undefined) ??
    (Object.hasOwn(components, data.componentName)
      ? components[data.componentName]
      : undefined)
  );
}

/**
 * Resolves an app-owned component and its identity from the element key.
 * Application props are preserved. Non-component structural elements return null.
 */
export function getCanvasComponentRenderData(
  node: CanvasComponentTreeElement,
  elementKey?: string,
): CanvasComponentRenderData | null {
  const componentName = componentNameFromElement(node.type);
  if (!componentName) {
    return null;
  }

  return {
    element: node.type,
    componentName,
    ...(elementKey ? { componentUuid: elementKey } : {}),
    props: { ...node.props },
  };
}

/**
 * Resolves Canvas wire components and component types registered by exact key.
 * Exact keys let other json-render producers use their native type names.
 */
export function getJsonRenderComponentData<T>(
  node: CanvasComponentTreeElement,
  components: Record<string, T>,
  elementKey?: string,
): CanvasComponentRenderData | null {
  const canvasData = getCanvasComponentRenderData(node, elementKey);
  if (canvasData) {
    return canvasData;
  }
  if (!Object.hasOwn(components, node.type)) {
    return null;
  }
  return {
    element: node.type,
    componentName: node.type,
    props: { ...node.props },
  };
}

/** Reports that editor markers cannot identify a component instance. */
export function reportMissingCanvasComponentUuid(
  data: Pick<CanvasComponentRenderData, 'componentName'>,
  path: string,
): void {
  console.error(
    `[canvas] Canvas component "${data.componentName}" has no instance UUID; editor markers were omitted at "${path}".`,
  );
}

/** Reports that editor markers cannot identify a structural marker instance. */
export function reportMissingCanvasMarkerComponentUuid(path: string): void {
  console.error(
    `[canvas] Canvas marker has no instance UUID; editor markers were omitted at "${path}".`,
  );
}

/** Reports that a component and its subtree were omitted during rendering. */
export function reportMissingCanvasComponent(
  data: Pick<CanvasComponentRenderData, 'componentName' | 'componentUuid'>,
  path: string,
): void {
  const instance = data.componentUuid
    ? ` (instance "${data.componentUuid}")`
    : '';
  console.error(
    `[canvas] Canvas component "${data.componentName}"${instance} is not registered; omitted subtree at "${path}".`,
  );
}
