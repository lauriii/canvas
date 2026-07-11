import { useParams } from 'react-router-dom';
import { ContainerIcon } from '@radix-ui/react-icons';
import { Button, Tooltip } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useEditorNavigation } from '@/hooks/useEditorNavigation';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import {
  PAGE_VARIANT_ENTITY_TYPE,
  useGetPageVariantsQuery,
} from '@/services/pageVariants';

/**
 * Offers to jump from editing a page to editing its resolved page variant.
 *
 * While editing a page, the variant renders the chrome around the content and
 * is visible but locked; this is the single affordance to edit the variant
 * itself.
 */
const PageVariantJumpButton = () => {
  const { entityType, entityId } = useParams();
  const { isTemplateContext, isTemplatePreviewRoute } = useTemplateRef();
  const { navigateToEditor } = useEditorNavigation();

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
    return null;
  }
  const label = variants?.[variantId]?.label || variantId;

  return (
    <Tooltip content="Edit the page variant that renders this page">
      <Button
        color="gray"
        variant="soft"
        size="1"
        data-testid="canvas-page-variant-jump"
        onClick={() => navigateToEditor(PAGE_VARIANT_ENTITY_TYPE, variantId)}
      >
        <ContainerIcon />
        {label}
      </Button>
    </Tooltip>
  );
};

export default PageVariantJumpButton;
