import { createServerFn } from '@tanstack/react-start';

import { CanvasComponentTree } from './canvas-component-tree';
import { getComponentPreviewData } from './server';

import type { Page } from '@drupal-canvas/headless/server';

export interface ComponentPreviewProps {
  page: Page;
}

/** Loads the isolated component preview through a server-only draft session. */
export const loadComponentPreview = createServerFn().handler(
  getComponentPreviewData,
);

/** Isolated document content that reuses the current Canvas draft session. */
export function ComponentPreview({ page }: ComponentPreviewProps) {
  return (
    <main data-canvas-component-preview-document>
      <style>{`
        html, body { margin: 0; background: white; }
        [data-canvas-component-preview-document] {
          position: fixed;
          inset: 0;
          z-index: 2147483646;
          overflow: auto;
          background: white;
        }
      `}</style>
      <CanvasComponentTree tree={page.content} />
    </main>
  );
}

export default ComponentPreview;
