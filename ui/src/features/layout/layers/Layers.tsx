import { Box, Separator } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import PermissionCheck from '@/components/PermissionCheck';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import PageVariantLayer from '@/features/layout/layers/PageVariantLayer';
import RegionLayer from '@/features/layout/layers/RegionLayer';
import {
  selectContentRegion,
  selectIsPerContentMode,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import useExpandParentsOnSelection from '@/hooks/useExpandParentsOnSelection';
import useMatchingContentTemplate from '@/hooks/useMatchingContentTemplate';
import useSyncCollapsedLayersLocalStorage from '@/hooks/useSyncCollapsedLayersLocalStorage';

import type React from 'react';

interface LayersProps {}

const Layers: React.FC<LayersProps> = () => {
  const contentRegion = useAppSelector(selectContentRegion);
  const regions = useAppSelector(selectLayout);
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const { urlForTemplateEditor } = useEditorNavigation();
  const contentTemplate = useMatchingContentTemplate();
  useSyncCollapsedLayersLocalStorage();
  useExpandParentsOnSelection();

  // Per-content editing: the layout is one region per exposed slot rather than
  // the entity's own single content region, and the content template this
  // entity follows is surfaced at the top as a shortcut to editing it
  // (permission-gated: only a user who may edit templates gets the jump).
  // @see useMatchingContentTemplate
  if (isPerContentMode) {
    return (
      <Box>
        {contentTemplate && (
          <PermissionCheck hasPermission="contentTemplates">
            <SidebarNode
              variant="template"
              title={`${contentTemplate.viewModeLabel} template`}
              to={urlForTemplateEditor(contentTemplate)}
              data-testid="layers-content-template"
            />
            <Separator orientation="horizontal" size="4" my="2" />
          </PermissionCheck>
        )}
        {regions.map((region) => (
          <RegionLayer key={region.id} region={region} />
        ))}
      </Box>
    );
  }

  return (
    <Box>
      {/* The variant renders the chrome around the content: it is the
          outermost layer, locked here, and edited separately. */}
      <PageVariantLayer>
        <RegionLayer region={contentRegion} />
      </PageVariantLayer>
    </Box>
  );
};

export default Layers;
