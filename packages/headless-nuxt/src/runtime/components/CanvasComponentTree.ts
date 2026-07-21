// Carry the ambient declaration of the virtual components module into any
// TypeScript program that includes this file — consumer apps type-check this
// runtime source directly, without the package's tsconfig.
// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />
import canvasComponents from 'virtual:@drupal-canvas/headless/components';
import { defineComponent, Fragment, h } from 'vue';
import {
  findCanvasComponent,
  getCanvasComponentRenderData,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
} from '@drupal-canvas/headless';

import type { CanvasComponentTreeElement } from '@drupal-canvas/headless/server';
import type { Component, PropType, VNodeChild } from 'vue';

export type CanvasComponentRegistry = Record<string, Component>;

/** Renders a structured Canvas component tree. */
export default defineComponent({
  name: 'CanvasComponentTree',
  props: {
    tree: {
      type: [Object, String] as PropType<CanvasComponentTreeElement | string>,
      required: true,
    },
    components: {
      type: Object as PropType<CanvasComponentRegistry>,
      default: () => canvasComponents,
    },
  },
  setup(props) {
    return () =>
      typeof props.tree === 'string'
        ? renderMarkup(props.tree)
        : renderElement(props.tree, props.components, 'tree');
  },
});

function renderElement(
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
): VNodeChild {
  const slots = Object.entries(node.slots ?? {}).map(([name, value]) => ({
    name,
    children: normalizeCanvasComponentTreeSlot(value),
  }));
  const componentData = getCanvasComponentRenderData(node);

  if (node.element === 'drupal-markup' || !componentData) {
    return h(
      Fragment,
      null,
      slots.flatMap(({ name, children }) =>
        children.map((child, index) =>
          typeof child === 'string'
            ? renderMarkup(child, `${path}:${name}:${index}`)
            : renderElement(child, components, `${path}:${name}:${index}`),
        ),
      ),
    );
  }

  const component = findCanvasComponent(components, componentData);
  if (!component) {
    reportMissingCanvasComponent(componentData, path);
    return null;
  }

  const renderedSlots = Object.fromEntries(
    slots.map(({ name, children }) => [
      name,
      () =>
        children.map((child, index) =>
          typeof child === 'string'
            ? renderMarkup(child, `${path}:${name}:${index}`)
            : renderElement(child, components, `${path}:${name}:${index}`),
        ),
    ]),
  );

  return h(component, componentData.props, renderedSlots);
}

function renderMarkup(html: string, key?: string) {
  return h('span', {
    key,
    style: { display: 'contents' },
    innerHTML: html,
  });
}
