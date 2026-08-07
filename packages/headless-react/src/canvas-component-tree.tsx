import { createElement } from 'react';

import '@drupal-canvas/headless/preview.css';

import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  findCanvasComponent,
  getCanvasComponentRenderData,
  getCanvasTemplateMarkerAttributes,
  isCanvasComponentTreeDraft,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
} from '@drupal-canvas/headless';

import type { ElementType, ReactNode } from 'react';
import type {
  CanvasComponentTreeElement,
  CanvasMarker as CanvasMarkerProps,
} from '@drupal-canvas/headless';

/** App component implementations keyed by component.yml machine name. */
export type CanvasComponentRegistry = Record<string, ElementType>;

export interface CanvasComponentTreeProps {
  tree: CanvasComponentTreeElement | null;
  components: CanvasComponentRegistry;
}

interface CanvasElementProps {
  node: CanvasComponentTreeElement;
  components: CanvasComponentRegistry;
  path: string;
  editor: boolean;
}

/**
 * Renders a structured Canvas component tree.
 *
 * HTML strings are intentionally inserted as HTML. Apps must only pass trusted
 * rendered output here.
 */
export function CanvasComponentTree({
  tree,
  components,
}: CanvasComponentTreeProps) {
  const editor = isCanvasComponentTreeDraft(tree);
  const emptyRegion = editor && isCanvasComponentTreeEmpty(tree);
  const content = tree ? (
    <CanvasElement
      node={tree}
      components={components}
      path="tree"
      editor={editor}
      key={getCanvasElementKey(tree, 'tree')}
    />
  ) : null;

  return editor ? (
    <>
      <CanvasMarker position="start" type="region" id="content" />
      {emptyRegion && <CanvasEmptyRegionPlaceholder />}
      {content}
      <CanvasMarker position="end" type="region" id="content" />
    </>
  ) : (
    content
  );
}

/** Renders an empty-region drop area while editing. */
function CanvasEmptyRegionPlaceholder() {
  return (
    <div aria-hidden="true" className={CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS} />
  );
}

function CanvasElement({ node, components, path, editor }: CanvasElementProps) {
  if (node.element === 'drupal-markup') {
    return (
      <>
        {Object.values(node.slots ?? {}).flatMap((slot, slotIndex) =>
          normalizeCanvasComponentTreeSlot(slot).map((child, childIndex) => {
            const childPath = `${path}:${slotIndex}:${childIndex}`;
            return typeof child === 'string' ? (
              <CanvasMarkup html={child} key={childPath} />
            ) : (
              <CanvasElement
                node={child}
                components={components}
                path={childPath}
                editor={editor}
                key={getCanvasElementKey(child, childPath)}
              />
            );
          }),
        )}
      </>
    );
  }

  const componentData = getCanvasComponentRenderData(node);
  if (!componentData) {
    return (
      <>
        {renderSlots(node, components, path, editor).flatMap(
          ({ content }) => content,
        )}
      </>
    );
  }

  const Component = findCanvasComponent(components, componentData);
  if (!Component) {
    reportMissingCanvasComponent(componentData, path);
    return null;
  }

  const renderedSlots = renderSlots(node, components, path, editor);
  const slotProps = Object.fromEntries(
    renderedSlots.map(({ name, content }) => [
      name === 'default' ? 'children' : name,
      content,
    ]),
  );
  const component = createElement(Component, {
    ...componentData.props,
    ...slotProps,
  });

  if (!editor) {
    return component;
  }
  if (!componentData.componentUuid) {
    reportMissingCanvasComponentUuid(componentData, path);
    return component;
  }
  return (
    <>
      <CanvasMarker
        position="start"
        type="component"
        id={componentData.componentUuid}
      />
      {component}
      <CanvasMarker
        position="end"
        type="component"
        id={componentData.componentUuid}
      />
    </>
  );
}

function renderSlots(
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
) {
  const componentData = getCanvasComponentRenderData(node);
  return Object.entries(node.slots ?? {}).map(([name, slot]) => {
    const empty = isCanvasComponentTreeSlotEmpty(slot);
    const children =
      editor && empty ? [] : normalizeCanvasComponentTreeSlot(slot);
    return {
      name,
      content: wrapSlot(
        children.map((child, index) => {
          const childPath = `${path}:${name}:${index}`;
          return typeof child === 'string' ? (
            <CanvasMarkup html={child} key={childPath} />
          ) : (
            <CanvasElement
              node={child}
              components={components}
              path={childPath}
              editor={editor}
              key={getCanvasElementKey(child, childPath)}
            />
          );
        }),
        editor,
        componentData?.componentUuid,
        name,
        empty,
      ),
    };
  });
}

/** Keeps a Canvas component's React identity tied to its stored UUID. */
function getCanvasElementKey(
  node: CanvasComponentTreeElement,
  fallback: string,
): string {
  const componentUuid = getCanvasComponentRenderData(node)?.componentUuid;
  return componentUuid ? `component:${componentUuid}` : fallback;
}

function wrapSlot(
  content: ReactNode[],
  editor: boolean,
  componentUuid: string | undefined,
  slotName: string,
  empty: boolean,
): ReactNode[] {
  if (!editor || !componentUuid) {
    return content;
  }
  const id = `${componentUuid}/${slotName}`;
  return [
    <CanvasMarker position="start" type="slot" id={id} key={`${id}:start`} />,
    ...(empty
      ? [<CanvasEmptySlotPlaceholder key={`${id}:empty-placeholder`} />]
      : []),
    ...content,
    <CanvasMarker position="end" type="slot" id={id} key={`${id}:end`} />,
  ];
}

/** Renders a minimum empty-slot drop area while editing. */
function CanvasEmptySlotPlaceholder() {
  return (
    <div aria-hidden="true" className={CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS} />
  );
}

/** React uses template markers because it cannot render comment nodes. */
function CanvasMarker({ position, type, id }: CanvasMarkerProps) {
  return createElement(
    'template',
    getCanvasTemplateMarkerAttributes({ position, type, id }),
  );
}

function CanvasMarkup({ html }: { html: string }) {
  return (
    <span
      style={{ display: 'contents' }}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}
