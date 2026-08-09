// Carry the ambient declaration of the virtual components module into any
// TypeScript program that includes this file — consumer apps type-check this
// runtime source directly, without the package's tsconfig.
// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />

import canvasComponents from 'virtual:@drupal-canvas/headless/components';

import '@drupal-canvas/headless/preview.css';

import { createCommentVNode, defineComponent, Fragment, h } from 'vue';
import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  findCanvasComponent,
  formatCanvasCommentMarker,
  getCanvasComponentRenderData,
  isCanvasComponentTreeDraft,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
} from '@drupal-canvas/headless';

import type {
  CanvasComponentTreeElement,
  CanvasMarker,
} from '@drupal-canvas/headless';
import type { Component, PropType, VNodeChild } from 'vue';

export type CanvasComponentRegistry = Record<string, Component>;

/** Renders a structured Canvas component tree. */
export default defineComponent({
  name: 'CanvasComponentTree',
  props: {
    tree: {
      type: [Object, null] as PropType<CanvasComponentTreeElement | null>,
      required: true,
    },
    components: {
      type: Object as PropType<CanvasComponentRegistry>,
      default: () => canvasComponents,
    },
  },
  setup(props) {
    return () => {
      const editor = isCanvasComponentTreeDraft(props.tree);
      const emptyRegion = editor && isCanvasComponentTreeEmpty(props.tree);
      const content = h(
        Fragment,
        { key: 'tree' },
        props.tree
          ? [
              renderElement(
                props.tree,
                props.components ?? canvasComponents,
                'tree',
                editor,
              ),
            ]
          : [],
      );
      return editor
        ? h(Fragment, { key: 'region:content' }, [
            marker({ type: 'region', position: 'start', id: 'content' }),
            ...(emptyRegion ? [emptyRegionPlaceholder()] : []),
            content,
            marker({ type: 'region', position: 'end', id: 'content' }),
          ])
        : content;
    };
  },
});

/** Renders an empty-region drop area while editing. */
function emptyRegionPlaceholder() {
  return h('div', {
    'aria-hidden': 'true',
    class: CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  });
}

function renderElement(
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
): VNodeChild {
  const slots = Object.entries(node.slots ?? {}).map(([name, value]) => ({
    name,
    children: normalizeCanvasComponentTreeSlot(value),
    empty: isCanvasComponentTreeSlotEmpty(value),
  }));
  const componentData = getCanvasComponentRenderData(node);

  if (node.element === 'drupal-markup' || !componentData) {
    return h(
      Fragment,
      { key: path },
      slots.flatMap(({ name, children }) =>
        children.map((child, index) =>
          typeof child === 'string'
            ? renderMarkup(child, `${path}:${name}:${index}`)
            : renderElement(
                child,
                components,
                `${path}:${name}:${index}`,
                editor,
              ),
        ),
      ),
    );
  }

  const component = findCanvasComponent(components, componentData);
  if (!component) {
    reportMissingCanvasComponent(componentData, path);
    return null;
  }

  if (editor && !componentData.componentUuid) {
    reportMissingCanvasComponentUuid(componentData, path);
  }

  const renderedSlots = Object.fromEntries(
    slots.map(({ name, children, empty }) => [
      name,
      () => {
        const visibleChildren = editor && empty ? [] : children;
        const content = visibleChildren.map((child, index) =>
          typeof child === 'string'
            ? renderMarkup(child, `${path}:${name}:${index}`)
            : renderElement(
                child,
                components,
                `${path}:${name}:${index}`,
                editor,
              ),
        );
        if (!editor || !componentData.componentUuid) {
          return content;
        }
        const slotId = `${componentData.componentUuid}/${name}`;
        return h(Fragment, { key: `slot:${slotId}` }, [
          marker({ type: 'slot', position: 'start', id: slotId }),
          ...(empty ? [emptySlotPlaceholder()] : []),
          ...content,
          marker({ type: 'slot', position: 'end', id: slotId }),
        ]);
      },
    ]),
  );

  const rendered = h(
    component,
    { ...componentData.props, key: componentData.componentUuid ?? path },
    renderedSlots,
  );
  return editor && componentData.componentUuid
    ? h(Fragment, { key: `component:${componentData.componentUuid}` }, [
        marker({
          type: 'component',
          position: 'start',
          id: componentData.componentUuid,
        }),
        rendered,
        marker({
          type: 'component',
          position: 'end',
          id: componentData.componentUuid,
        }),
      ])
    : rendered;
}

function marker(value: CanvasMarker) {
  return createCommentVNode(` ${formatCanvasCommentMarker(value)} `);
}

/** Renders a minimum empty-slot drop area while editing. */
function emptySlotPlaceholder() {
  return h('div', {
    'aria-hidden': 'true',
    class: CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  });
}

function renderMarkup(html: string, key?: string) {
  return h('span', {
    key,
    style: { display: 'contents' },
    innerHTML: html,
  });
}
