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
import { hasPermission } from '@/utils/permissions';

import type React from 'react';

/**
 * A locked layer for the page variant that renders the edited page.
 *
 * The page's content renders inside the variant (at its "Page content"
 * marker), so the content region nests under this layer. The variant itself
 * is edited separately; clicking the layer jumps to editing it.
 *
 * Editing a variant needs "administer page template". Without that permission
 * both the variant editor route and the variants list (which supplies the
 * label) return 403, so the row is rendered as a non-navigating, generically
 * labeled node rather than dropping the user into the editor's error boundary.
 */
const PageVariantLayer = ({ children }: { children: React.ReactNode }) => {
  const { entityType, entityId } = useParams();
  const { isTemplateContext, isTemplatePreviewRoute } = useTemplateRef();
  const { urlForEditor } = useEditorNavigation();
  // Same flag the Templates panel gates its "Page templates" section on.
  const canEditVariants = hasPermission('pageVariants');

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
    skip: !isEntityEditorRoute || !canEditVariants,
  });

  const variantId = layout?.resolvedPageVariant;
  if (!isEntityEditorRoute || !variantId) {
    return children;
  }

  return (
    <div data-testid="canvas-page-variant-layer">
      <SidebarNode
        // Without the permission the variants list is unavailable, so use a
        // generic label instead of exposing the raw machine name.
        title={
          canEditVariants
            ? variants?.[variantId]?.label || variantId
            : 'Page template'
        }
        variant="template"
        to={
          canEditVariants
            ? urlForEditor(PAGE_VARIANT_ENTITY_TYPE, variantId)
            : undefined
        }
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
