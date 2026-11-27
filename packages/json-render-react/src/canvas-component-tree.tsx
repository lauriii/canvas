import { createElement } from 'react';

import '@drupal-canvas/json-render/preview.css';

import {
  areCanvasComponentTreeChildrenEmpty,
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  CANVAS_MARKER_ELEMENT,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  CANVAS_SLOT_PLACEHOLDER_ELEMENT,
  findCanvasComponent,
  getCanvasComponentTreeElementSlots,
  getCanvasTemplateMarkerAttributes,
  getJsonRenderComponentData,
  isCanvasComponentTreeSlotEmpty,
  prepareCanvasJsonRender,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
  reportMissingCanvasMarkerComponentUuid,
} from '@drupal-canvas/json-render/renderer';

import type { ElementType, ReactNode } from 'react';
import type {
  CanvasComponentTreeElement,
  CanvasComponentTree as CanvasComponentTreeSpec,
} from '@drupal-canvas/json-render';
import type { CanvasMarker as CanvasMarkerProps } from '@drupal-canvas/json-render/renderer';

/** App component implementations keyed by component.yml machine name. */
export type CanvasComponentRegistry = Record<string, ElementType>;

/**
 * Wraps one rendered app component, for example to provide the instance's
 * style selection through context. React Server Components, which cannot use
 * context, read `element.styles` here instead.
 */
export type CanvasRenderElement = (
  rendered: ReactNode,
  element: CanvasComponentTreeElement,
) => ReactNode;

export interface CanvasComponentTreeProps {
  tree: CanvasComponentTreeSpec | null;
  components: CanvasComponentRegistry;
  /** Whether Canvas editor boundaries and empty drop areas should render. */
  preview?: boolean;
  /** Wraps each rendered app component. */
  renderElement?: CanvasRenderElement;
}

interface CanvasElementProps {
  tree: CanvasComponentTreeSpec;
  elementKey: string;
  components: CanvasComponentRegistry;
  path: string;
  editor: boolean;
  renderElement?: CanvasRenderElement;
}

/**
 * Renders a Canvas json-render document with application-owned components.
 *
 * HTML strings are intentionally inserted as HTML. Apps must only pass trusted
 * rendered output here.
 */
export function CanvasComponentTree({
  tree,
  components,
  preview = false,
  renderElement,
}: CanvasComponentTreeProps) {
  const prepared = prepareCanvasJsonRender(tree, { preview });
  const content = prepared.spec?.root ? (
    <CanvasElement
      tree={prepared.spec}
      elementKey={prepared.spec.root}
      components={components}
      path={`elements.${prepared.spec.root}`}
      editor={prepared.preview}
      renderElement={renderElement}
      key={prepared.rootRenderKey}
    />
  ) : null;

  if (!prepared.needsOuterRegion) {
    return content;
  }

  return (
    <>
      <CanvasMarker position="start" type="region" id="content" />
      {prepared.emptyRegion && <CanvasEmptyRegionPlaceholder />}
      {content}
      <CanvasMarker position="end" type="region" id="content" />
    </>
  );
}

function CanvasElement({
  tree,
  elementKey,
  components,
  path,
  editor,
  renderElement,
}: CanvasElementProps) {
  const node = tree.elements[elementKey];
  if (!node) {
    return null;
  }

  if (node.type === CANVAS_SLOT_PLACEHOLDER_ELEMENT) {
    return null;
  }

  if (node.type === CANVAS_PREVIEW_CONTENT_REGION_ELEMENT) {
    const content = renderStructuralChildren(
      tree,
      node,
      components,
      path,
      editor,
      renderElement,
    );
    if (!editor) {
      return <>{content}</>;
    }
    const childKeys = getCanvasComponentTreeElementSlots(node).flatMap(
      ([, keys]) => keys,
    );
    return (
      <>
        <CanvasMarker position="start" type="region" id="content" />
        {areCanvasComponentTreeChildrenEmpty(tree, childKeys) && (
          <CanvasEmptyRegionPlaceholder />
        )}
        {content}
        <CanvasMarker position="end" type="region" id="content" />
      </>
    );
  }

  if (node.type === CANVAS_MARKER_ELEMENT) {
    const content = renderStructuralChildren(
      tree,
      node,
      components,
      path,
      editor,
      renderElement,
    );
    if (!editor) {
      return <>{content}</>;
    }
    const componentUuid = elementKey;
    if (!componentUuid) {
      reportMissingCanvasMarkerComponentUuid(path);
      return <>{content}</>;
    }
    return (
      <>
        <CanvasMarker position="start" type="component" id={componentUuid} />
        {content}
        <CanvasMarker position="end" type="component" id={componentUuid} />
      </>
    );
  }

  if (node.type === 'drupal-markup') {
    const markup = node.props.markup;
    return typeof markup === 'string' ? <CanvasMarkup html={markup} /> : null;
  }

  const componentData = getJsonRenderComponentData(
    node,
    components,
    elementKey,
  );
  if (!componentData) {
    return (
      <>
        {renderStructuralChildren(
          tree,
          node,
          components,
          path,
          editor,
          renderElement,
        )}
      </>
    );
  }

  const Component = findCanvasComponent(components, componentData);
  if (!Component) {
    reportMissingCanvasComponent(componentData, path);
    return null;
  }

  const slotProps = Object.fromEntries(
    renderSlots(
      tree,
      node,
      elementKey,
      components,
      path,
      editor,
      renderElement,
    ).map(({ name, content }) => [
      name === 'default' ? 'children' : name,
      content,
    ]),
  );
  // Styles: the same `className` and `style` Drupal-rendered code components
  // receive. Present only when the SDK opted in and the selection is set.
  const styleProps: Record<string, unknown> = {};
  if (node.attributes?.class) styleProps.className = node.attributes.class;
  if (node.attributes?.style) styleProps.style = node.attributes.style;
  const created = createElement(Component, {
    ...componentData.props,
    ...styleProps,
    ...slotProps,
  });
  const component = renderElement ? renderElement(created, node) : created;

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

function renderStructuralChildren(
  tree: CanvasComponentTreeSpec,
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
  renderElement?: CanvasRenderElement,
): ReactNode[] {
  return getCanvasComponentTreeElementSlots(node).flatMap(([name, childKeys]) =>
    childKeys.map((childKey, index) => (
      <CanvasElement
        tree={tree}
        elementKey={childKey}
        components={components}
        path={`${path}:${name}:${index}`}
        editor={editor}
        renderElement={renderElement}
        key={childKey}
      />
    )),
  );
}

function renderSlots(
  tree: CanvasComponentTreeSpec,
  node: CanvasComponentTreeElement,
  elementKey: string,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
  renderElement?: CanvasRenderElement,
) {
  const componentData = getJsonRenderComponentData(
    node,
    components,
    elementKey,
  );
  return getCanvasComponentTreeElementSlots(node).map(([name, childKeys]) => {
    const empty = isCanvasComponentTreeSlotEmpty(tree, childKeys);
    const visibleChildKeys = editor && empty ? [] : childKeys;
    const content = visibleChildKeys.map((childKey, index) => (
      <CanvasElement
        tree={tree}
        elementKey={childKey}
        components={components}
        path={`${path}:${name}:${index}`}
        editor={editor}
        renderElement={renderElement}
        key={childKey}
      />
    ));
    return {
      name,
      content: wrapSlot(
        content,
        editor,
        componentData?.componentUuid,
        name,
        empty,
      ),
    };
  });
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

/** Renders an empty-region drop area while editing. */
function CanvasEmptyRegionPlaceholder() {
  return (
    <div aria-hidden="true" className={CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS} />
  );
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
