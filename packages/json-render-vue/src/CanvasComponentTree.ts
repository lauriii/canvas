import '@drupal-canvas/json-render/preview.css';

import { createCommentVNode, defineComponent, Fragment, h } from 'vue';
import {
  areCanvasComponentTreeChildrenEmpty,
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  CANVAS_MARKER_ELEMENT,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  CANVAS_SLOT_PLACEHOLDER_ELEMENT,
  findCanvasComponent,
  formatCanvasCommentMarker,
  getCanvasComponentTreeElementSlots,
  getJsonRenderComponentData,
  isCanvasComponentTreeSlotEmpty,
  prepareCanvasJsonRender,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
  reportMissingCanvasMarkerComponentUuid,
} from '@drupal-canvas/json-render/renderer';

import type {
  CanvasComponentTree,
  CanvasComponentTreeElement,
} from '@drupal-canvas/json-render';
import type { CanvasMarker } from '@drupal-canvas/json-render/renderer';
import type { Component, PropType, VNodeChild } from 'vue';

export type CanvasComponentRegistry = Record<string, Component>;

/** Renders a Canvas json-render document through Vue components. */
export default defineComponent({
  name: 'CanvasComponentTree',
  props: {
    tree: {
      type: [Object, null] as PropType<CanvasComponentTree | null>,
      required: true,
    },
    components: {
      type: Object as PropType<CanvasComponentRegistry>,
      required: true,
    },
    preview: {
      type: Boolean,
      default: false,
    },
  },
  setup(props) {
    return () => {
      const prepared = prepareCanvasJsonRender(props.tree, {
        preview: props.preview,
      });
      const tree = prepared.spec;
      const content = h(
        Fragment,
        { key: prepared.rootRenderKey },
        tree?.root
          ? [
              renderElement(
                tree,
                tree.root,
                props.components,
                `elements.${tree.root}`,
                prepared.preview,
              ),
            ]
          : [],
      );

      return prepared.needsOuterRegion
        ? h(Fragment, { key: 'region:content' }, [
            marker({ type: 'region', position: 'start', id: 'content' }),
            ...(prepared.emptyRegion ? [emptyRegionPlaceholder()] : []),
            content,
            marker({ type: 'region', position: 'end', id: 'content' }),
          ])
        : content;
    };
  },
});

function renderElement(
  tree: CanvasComponentTree,
  elementKey: string,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
): VNodeChild {
  const node = tree.elements[elementKey];
  if (!node) {
    return null;
  }

  if (node.type === CANVAS_SLOT_PLACEHOLDER_ELEMENT) {
    return null;
  }

  if (node.type === CANVAS_PREVIEW_CONTENT_REGION_ELEMENT) {
    const childKeys = getCanvasComponentTreeElementSlots(node).flatMap(
      ([, keys]) => keys,
    );
    const content = renderStructuralChildren(tree, node, components, editor);
    return editor
      ? h(Fragment, { key: elementKey }, [
          marker({ type: 'region', position: 'start', id: 'content' }),
          ...(areCanvasComponentTreeChildrenEmpty(tree, childKeys)
            ? [emptyRegionPlaceholder()]
            : []),
          ...content,
          marker({ type: 'region', position: 'end', id: 'content' }),
        ])
      : h(Fragment, { key: elementKey }, content);
  }

  if (node.type === CANVAS_MARKER_ELEMENT) {
    const content = renderStructuralChildren(tree, node, components, editor);
    if (!editor) {
      return h(Fragment, { key: elementKey }, content);
    }
    const componentUuid = elementKey;
    if (!componentUuid) {
      reportMissingCanvasMarkerComponentUuid(path);
      return h(Fragment, { key: elementKey }, content);
    }
    return h(Fragment, { key: `component:${componentUuid}` }, [
      marker({ type: 'component', position: 'start', id: componentUuid }),
      ...content,
      marker({ type: 'component', position: 'end', id: componentUuid }),
    ]);
  }

  if (node.type === 'drupal-markup') {
    const markup = node.props.markup;
    return typeof markup === 'string' ? renderMarkup(markup, elementKey) : null;
  }

  const componentData = getJsonRenderComponentData(
    node,
    components,
    elementKey,
  );
  if (!componentData) {
    return h(
      Fragment,
      { key: elementKey },
      renderStructuralChildren(tree, node, components, editor),
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

  const slots = getCanvasComponentTreeElementSlots(node).map(
    ([name, childKeys]) => ({
      name,
      childKeys,
      empty: isCanvasComponentTreeSlotEmpty(tree, childKeys),
    }),
  );
  const renderedSlots = Object.fromEntries(
    slots.map(({ name, childKeys, empty }) => [
      name,
      () => {
        const visibleChildKeys = editor && empty ? [] : childKeys;
        const content = visibleChildKeys.map((childKey) =>
          renderElement(
            tree,
            childKey,
            components,
            `elements.${childKey}`,
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
    { ...componentData.props, key: componentData.componentUuid ?? elementKey },
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

function renderStructuralChildren(
  tree: CanvasComponentTree,
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  editor: boolean,
): VNodeChild[] {
  return getCanvasComponentTreeElementSlots(node).flatMap(([, childKeys]) =>
    childKeys.map((childKey) =>
      renderElement(tree, childKey, components, `elements.${childKey}`, editor),
    ),
  );
}

/** Renders an empty-region drop area while editing. */
function emptyRegionPlaceholder() {
  return h('div', {
    'aria-hidden': 'true',
    class: CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  });
}

/** Renders a minimum empty-slot drop area while editing. */
function emptySlotPlaceholder() {
  return h('div', {
    'aria-hidden': 'true',
    class: CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  });
}

function marker(value: CanvasMarker) {
  return createCommentVNode(` ${formatCanvasCommentMarker(value)} `);
}

function renderMarkup(html: string, key: string) {
  return h('span', {
    key,
    style: { display: 'contents' },
    innerHTML: html,
  });
}
