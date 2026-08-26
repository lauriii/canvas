import { CANVAS_COMPONENT_PREVIEW_QUERY } from '@drupal-canvas/headless';
import { isPageRedirect } from '@drupal-canvas/headless/server';

import { CanvasComponentTree } from './canvas-component-tree';
import { fetchComponentPreview, getDraftData } from './server';

interface ComponentPreviewPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

/**
 * The isolated App Router page that reuses the current Canvas draft session
 * for component-library thumbnails.
 * Mount it at app/api/canvas/component-preview/page.tsx.
 */
export async function ComponentPreviewPage({
  searchParams,
}: ComponentPreviewPageProps) {
  const rawComponentId = (await searchParams)[CANVAS_COMPONENT_PREVIEW_QUERY];
  const componentId =
    typeof rawComponentId === 'string' ? rawComponentId : null;
  const draftData = await getDraftData();
  if (!draftData || !componentId) {
    return null;
  }

  const page = await fetchComponentPreview(componentId);
  if (!page || isPageRedirect(page)) {
    return null;
  }

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
        nextjs-portal { display: none !important; }
      `}</style>
      <CanvasComponentTree tree={page.content} />
    </main>
  );
}

export default ComponentPreviewPage;
