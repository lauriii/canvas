import { useParams } from 'react-router-dom';
import { skipToken } from '@reduxjs/toolkit/query';

import { ListIndentContext } from '@/components/sidePanel/ListIndentContext';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import { useEditorNavigation } from '@/hooks/useEditorNavigation';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import {
  PAGE_VARIANT_ENTITY_TYPE,
  useGetPageVariantsQuery,
} from '@/services/pageVariants';

import type React from 'react';

/**
 * A locked layer for the page variant that renders the edited page.
 *
 * The page's content renders inside the variant (at its "Page content"
 * marker), so the content region nests under this layer. The variant itself
 * is edited separately; clicking the layer jumps to editing it.
 */
const PageVariantLayer = ({ children }: { children: React.ReactNode }) => {
  const { entityType, entityId } = useParams();
  const { isTemplateContext, isTemplatePreviewRoute } = useTemplateRef();
  const { urlForEditor } = useEditorNavigation();

  const isEntityEditorRoute =
    !!entityType &&
    !!entityId &&
    !isTemplateContext &&
    !isTemplatePreviewRoute &&
    entityType !== PAGE_VARIANT_ENTITY_TYPE;

  const { data: layout } = useGetPageLayoutQuery(
    isEntityEditorRoute ? { entityType, entityId } : skipToken,
  );
  const { data: variants } = useGetPageVariantsQuery(undefined, {
    skip: !isEntityEditorRoute,
  });

  const variantId = layout?.resolvedPageVariant;
  if (!isEntityEditorRoute || !variantId) {
    return children;
  }

  return (
    <div data-testid="canvas-page-variant-layer">
      <SidebarNode
        title={variants?.[variantId]?.label || variantId}
        variant="template"
        to={urlForEditor(PAGE_VARIANT_ENTITY_TYPE, variantId)}
      />
      {/* Component rows below add a 20px collapse-triangle gutter; indent the
          region row two steps so the tree levels read evenly. */}
      <ListIndentContext.Provider value={2}>
        {children}
      </ListIndentContext.Provider>
    </div>
  );
};

export default PageVariantLayer;
