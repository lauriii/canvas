// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />

import canvasComponents from 'virtual:@drupal-canvas/headless/components';
import { CanvasComponentTree as ReactCanvasComponentTree } from '@drupal-canvas/headless-react';

import type { CanvasComponentTreeProps as ReactCanvasComponentTreeProps } from '@drupal-canvas/headless-react';

export type CanvasComponentTreeProps = Pick<
  ReactCanvasComponentTreeProps,
  'tree'
>;

/** Renders a Canvas tree with every component discovered by canvas(). */
export function CanvasComponentTree({ tree }: CanvasComponentTreeProps) {
  return <ReactCanvasComponentTree tree={tree} components={canvasComponents} />;
}

export default CanvasComponentTree;
