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
  const layout = useAppSelector(selectLayout);
  useSyncCollapsedLayersLocalStorage();
  useExpandParentsOnSelection();

  // Per-content editing: surface the content template this entity follows at the
  // top of the layers, as a shortcut to editing it (permission-gated: only a
  // user who may edit templates gets the jump). @see useMatchingContentTemplate.
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const { urlForTemplateEditor } = useEditorNavigation();
  const contentTemplate = useMatchingContentTemplate();

  return (
    <Box>
      {isPerContentMode && contentTemplate && (
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
      {/* The variant renders the chrome around the content: it is the
          outermost layer, read-only here, and edited separately. In per-content
          mode the content is one region per exposed slot, all of which show,
          even when empty. */}
      <PageVariantLayer>
        {isPerContentMode ? (
          layout.map((region) => (
            <RegionLayer key={region.id} region={region} />
          ))
        ) : (
          <RegionLayer region={contentRegion} />
        )}
      </PageVariantLayer>
    </Box>
  );
};

export default Layers;
