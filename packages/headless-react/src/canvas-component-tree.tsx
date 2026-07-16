import { createElement } from 'react';
import {
  findCanvasComponent,
  getCanvasComponentRenderData,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
} from '@drupal-canvas/headless';

import type { ElementType } from 'react';
import type { CanvasComponentTreeElement } from '@drupal-canvas/headless/server';

/** App component implementations keyed by component.yml machine name. */
export type CanvasComponentRegistry = Record<string, ElementType>;

export interface CanvasComponentTreeProps {
  tree: CanvasComponentTreeElement | string;
  components: CanvasComponentRegistry;
}

interface CanvasElementProps {
  node: CanvasComponentTreeElement;
  components: CanvasComponentRegistry;
  path: string;
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
  return typeof tree === 'string' ? (
    <CanvasMarkup html={tree} />
  ) : (
    <CanvasElement node={tree} components={components} path="tree" />
  );
}

function CanvasElement({ node, components, path }: CanvasElementProps) {
  if (node.element === 'drupal-markup') {
    return (
      <>
        {Object.values(node.slots ?? {}).flatMap((slot, slotIndex) =>
          normalizeCanvasComponentTreeSlot(slot).map((child, childIndex) =>
            typeof child === 'string' ? (
              <CanvasMarkup
                html={child}
                key={`${path}:${slotIndex}:${childIndex}`}
              />
            ) : (
              <CanvasElement
                node={child}
                components={components}
                path={`${path}:${slotIndex}:${childIndex}`}
                key={`${path}:${slotIndex}:${childIndex}`}
              />
            ),
          ),
        )}
      </>
    );
  }

  const componentData = getCanvasComponentRenderData(node);
  if (!componentData) {
    return (
      <>
        {renderSlots(node, components, path).flatMap(({ content }) => content)}
      </>
    );
  }

  const Component = findCanvasComponent(components, componentData);
  if (!Component) {
    reportMissingCanvasComponent(componentData, path);
    return null;
  }

  const renderedSlots = renderSlots(node, components, path);
  const slotProps = Object.fromEntries(
    renderedSlots.map(({ name, content }) => [
      name === 'default' ? 'children' : name,
      content,
    ]),
  );
  return createElement(Component, {
    ...componentData.props,
    ...slotProps,
  });
}

function renderSlots(
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
) {
  return Object.entries(node.slots ?? {}).map(([name, slot]) => ({
    name,
    content: normalizeCanvasComponentTreeSlot(slot).map((child, index) =>
      typeof child === 'string' ? (
        <CanvasMarkup html={child} key={`${path}:${name}:${index}`} />
      ) : (
        <CanvasElement
          node={child}
          components={components}
          path={`${path}:${name}:${index}`}
          key={`${path}:${name}:${index}`}
        />
      ),
    ),
  }));
}

function CanvasMarkup({ html }: { html: string }) {
  return (
    <span
      style={{ display: 'contents' }}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}
