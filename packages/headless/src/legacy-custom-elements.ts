import {
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  CANVAS_SLOT_PLACEHOLDER_ELEMENT,
  componentElementFromName,
  componentNameFromElement,
} from '@drupal-canvas/json-render/renderer';

import type {
  CanvasComponentTree,
  CanvasComponentTreeElement,
  JsonRenderSpec,
  JsonValue,
} from './page';

/** A nested Custom Elements document returned by older Canvas backends. */
export interface LegacyCustomElements {
  element: string;
  props?: Record<string, JsonValue>;
  slots?: Record<string, LegacyCustomElementsSlot>;
}

export type LegacyCustomElementsSlot =
  string | LegacyCustomElements | LegacyCustomElementsSlot[] | false | null;

/**
 * Converts the legacy Custom Elements format to json-render using the app's
 * component registry.
 * Native json-render documents can be passed directly to the renderer.
 *
 * @todo Remove this module and its renderer call sites when Canvas 1.13 is no longer supported.
 */
export function convertLegacyCustomElementsFormat(
  content: CanvasComponentTree | null,
  components: Readonly<Record<string, unknown>>,
): JsonRenderSpec | null {
  if (content === null || !('element' in content)) {
    return content;
  }

  const elements = new Map<string, CanvasComponentTreeElement>();
  let nextKey = 0;

  function addElement(element: CanvasComponentTreeElement, uuid?: string) {
    const key = uuid ?? `el-${nextKey++}`;
    if (elements.has(key)) {
      throw new Error(
        `Duplicate Canvas component UUID in content document: ${key}`,
      );
    }
    elements.set(key, element);
    return key;
  }

  function addSlot(slot: LegacyCustomElementsSlot): string[] {
    if (slot === false || slot === null) {
      return [];
    }
    if (Array.isArray(slot)) {
      return slot.flatMap(addSlot);
    }
    if (typeof slot === 'string') {
      return [
        addElement({
          type: 'drupal-markup',
          props: { markup: slot },
          children: [],
        }),
      ];
    }

    const slots = Object.entries(slot.slots ?? {}).map(
      ([name, value]): [string, string[]] => [name, addSlot(value)],
    );
    if (slot.element === CANVAS_PREVIEW_CONTENT_REGION_ELEMENT) {
      return [
        addElement({
          type: slot.element,
          props: {},
          children: slots.flatMap(([, keys]) => keys),
        }),
      ];
    }
    const { canvasUuid, ...props } = slot.props ?? {};
    if (
      !slot.element.startsWith('js-') ||
      slot.element.length === 3 ||
      typeof canvasUuid !== 'string' ||
      canvasUuid === ''
    ) {
      // Canvas supplies a UUID for Code Components. Preserve other elements' children.
      return slots.flatMap(([, keys]) => keys);
    }

    const tag = slot.element.slice(3);
    // Legacy tags discarded case and separators. Use the literal machine
    // name first, then the first registry key that maps to the same tag.
    const registryKey = Object.hasOwn(components, tag)
      ? tag
      : Object.keys(components).find(
          (name) =>
            (componentNameFromElement(name) ?? name)
              .replaceAll(/[.:_]/g, '-')
              .toLowerCase() === tag,
        );
    const name =
      registryKey === undefined
        ? tag
        : (componentNameFromElement(registryKey) ?? registryKey);
    const defaultSlot = slots.find(([name]) => name === 'default');
    const children = defaultSlot?.[1] ?? [];
    // A default slot normally has no entry when its children list is empty.
    // Keep an explicit legacy slot so the renderer can expose its drop zone.
    if (defaultSlot && children.length === 0) {
      children.push(
        addElement({
          type: CANVAS_SLOT_PLACEHOLDER_ELEMENT,
          props: {},
          children: [],
        }),
      );
    }
    const namedSlots = slots.filter(([name]) => name !== 'default');

    return [
      addElement(
        {
          type: componentElementFromName(name),
          props,
          children,
          ...(namedSlots.length > 0 && {
            slots: Object.fromEntries(namedSlots),
          }),
        },
        canvasUuid,
      ),
    ];
  }

  const roots = addSlot(content);
  const root =
    roots.length > 1
      ? addElement({
          type: 'renderless-container',
          props: {},
          children: roots,
        })
      : (roots[0] ?? '');
  return { root, elements: Object.fromEntries(elements) };
}
