// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />

'use client';

import canvasComponents from '@drupal-canvas/headless-next-generated-components';
import { CanvasComponentTree as ReactCanvasComponentTree } from '@drupal-canvas/headless-react';

import type { CanvasComponentTreeProps as ReactCanvasComponentTreeProps } from '@drupal-canvas/headless-react';

export type CanvasComponentTreeProps = Pick<
  ReactCanvasComponentTreeProps,
  'tree' | 'context' | 'jsonApi'
>;

/**
 * Renders a Canvas tree with every component discovered by withCanvas().
 *
 * A client boundary: registered components render on the server for the
 * initial HTML and hydrate in the browser, where hooks and interactivity run.
 * Pass `context={page.context}` so `usePageContext()` and `useSiteContext()`
 * see the routed page's data. `useJsonApiClient()` is configured by the
 * `CanvasRuntime` server component (rendered once in the root layout), or by
 * an explicit `jsonApi` prop.
 */
export function CanvasComponentTree({
  tree,
  context,
  jsonApi,
}: CanvasComponentTreeProps) {
  return (
    <ReactCanvasComponentTree
      tree={tree}
      context={context}
      jsonApi={jsonApi}
      components={canvasComponents}
    />
  );
}

export default CanvasComponentTree;
