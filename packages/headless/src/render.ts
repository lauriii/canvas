/**
 * @file
 * Framework-neutral helpers for rendering Canvas Custom Elements trees.
 * Framework bindings use these helpers to resolve app-owned components
 * consistently and to preserve Canvas instance identity.
 */

import type {
  CanvasComponentTreeElement,
  CanvasComponentTreeSlot,
  JsonValue,
} from './page';

/** The prop used on the wire for a Canvas component instance UUID. */
export const CANVAS_COMPONENT_UUID_PROP = 'canvasUuid';

/**
 * Component metadata separated from the props an app component receives.
 */
export interface CanvasComponentRenderData {
  /** The custom-element name emitted by Drupal, for example `js-hello-card`. */
  element: string;
  /** The app registry key, for example `hello-card`. */
  componentName: string;
  /** The Canvas component instance UUID, when Drupal included it. */
  componentUuid?: string;
  /** Props safe to pass to the app component. */
  props: Record<string, JsonValue>;
}

/**
 * Converts a slot's single-or-multiple wire shape to one iterable shape.
 */
export function normalizeCanvasComponentTreeSlot(
  slot: CanvasComponentTreeSlot | undefined,
): Array<string | CanvasComponentTreeElement> {
  if (slot === undefined) {
    return [];
  }
  if (!Array.isArray(slot)) {
    return [slot];
  }
  return slot.flatMap((child) => normalizeCanvasComponentTreeSlot(child));
}

/**
 * Whether a slot has no Canvas child components and needs an editor placeholder.
 * String-only values are component defaults/examples, which editor rendering
 * replaces with the empty-slot placeholder.
 */
export function isCanvasComponentTreeSlotEmpty(
  slot: CanvasComponentTreeSlot | undefined,
): boolean {
  const children = normalizeCanvasComponentTreeSlot(slot);
  return children.length === 0 || children.every(isCanvasSlotDefaultChild);
}

/** Whether a top-level Canvas region has no rendered page content. */
export function isCanvasComponentTreeEmpty(
  tree: CanvasComponentTreeElement | null,
): boolean {
  return tree === null || isCanvasComponentTreeElementEmpty(tree);
}

/** Whether one structured element contains no rendered page content. */
function isCanvasComponentTreeElementEmpty(
  element: CanvasComponentTreeElement,
): boolean {
  if (getCanvasComponentRenderData(element)) {
    return false;
  }
  return Object.values(element.slots ?? {}).every((slot) =>
    normalizeCanvasComponentTreeSlot(slot).every((child) =>
      typeof child === 'string'
        ? child.trim() === ''
        : isCanvasComponentTreeElementEmpty(child),
    ),
  );
}

/** Whether the structured root node was marked as draft output. */
export function isCanvasComponentTreeDraft(
  tree: CanvasComponentTreeElement | null,
): boolean {
  return tree?.canvasDraftMode === true;
}

/** Whether one slot child is default markup rather than a Canvas component. */
function isCanvasSlotDefaultChild(
  child: string | CanvasComponentTreeElement,
): boolean {
  if (typeof child === 'string') {
    return true;
  }
  if (child.element !== 'drupal-markup') {
    return false;
  }
  return Object.values(child.slots ?? {}).every((slot) =>
    normalizeCanvasComponentTreeSlot(slot).every(isCanvasSlotDefaultChild),
  );
}

/**
 * Gets the app registry key from a Drupal component custom-element name.
 *
 * Canvas external Code Components use the `js.` component source ID. The
 * Custom Elements API converts that to a valid element name with a `js-`
 * prefix. Registry keys intentionally remain the component.yml machine name.
 */
export function componentNameFromElement(element: string): string | null {
  return element.startsWith('js-') && element.length > 3
    ? element.slice(3)
    : null;
}

/**
 * Converts a component.yml machine name to Drupal's custom-element name.
 * Custom element names are lowercase, so registry lookup must compare this
 * normalized value instead of assuming the wire format preserved casing.
 */
export function componentElementFromName(componentName: string): string {
  return `js-${componentName.replaceAll(/[.:_]/g, '-').toLowerCase()}`;
}

/** Resolves a component implementation without losing camelCase machine names. */
export function findCanvasComponent<T>(
  components: Record<string, T>,
  data: Pick<CanvasComponentRenderData, 'componentName' | 'element'>,
): T | undefined {
  return (
    components[data.componentName] ??
    Object.entries(components).find(
      ([name]) => componentElementFromName(name) === data.element,
    )?.[1]
  );
}

/**
 * Resolves an app-owned component and removes Canvas-only metadata from its
 * props. Non-component structural elements return null.
 */
export function getCanvasComponentRenderData(
  node: CanvasComponentTreeElement,
): CanvasComponentRenderData | null {
  const componentName = componentNameFromElement(node.element);
  if (!componentName) {
    return null;
  }

  const props = { ...node.props };
  const componentUuid = props[CANVAS_COMPONENT_UUID_PROP];
  delete props[CANVAS_COMPONENT_UUID_PROP];

  return {
    element: node.element,
    componentName,
    ...(typeof componentUuid === 'string' && componentUuid !== ''
      ? { componentUuid }
      : {}),
    props,
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
