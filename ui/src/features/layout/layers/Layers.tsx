import React, { useMemo } from 'react';
import { useParams } from 'react-router';
import { Box, Separator } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import PermissionCheck from '@/components/PermissionCheck';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import RegionLayer from '@/features/layout/layers/RegionLayer';
import {
  selectIsPerContentMode,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import useExpandParentsOnSelection from '@/hooks/useExpandParentsOnSelection';
import useMatchingContentTemplate from '@/hooks/useMatchingContentTemplate';
import useSyncCollapsedLayersLocalStorage from '@/hooks/useSyncCollapsedLayersLocalStorage';

interface LayersProps {}

const Layers: React.FC<LayersProps> = () => {
  const regions = useAppSelector(selectLayout);
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  useSyncCollapsedLayersLocalStorage();
  useExpandParentsOnSelection();

  // Per-content editing: surface the content template this entity follows at the
  // top of the layers, as a shortcut to editing it (permission-gated: only a
  // user who may edit templates gets the jump). @see useMatchingContentTemplate.
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const { urlForTemplateEditor } = useEditorNavigation();
  const contentTemplate = useMatchingContentTemplate();

  const displayedRegions = useMemo(() => {
    let filteredRegions = regions.filter((region) => {
      // Per-content editing: every exposed-slot region shows, even empty.
      return (
        isPerContentMode ||
        region.components.length > 0 ||
        region.id === DEFAULT_REGION
      );
    });

    if (focusedRegion !== DEFAULT_REGION) {
      filteredRegions = filteredRegions.filter((region) => {
        return region.id === focusedRegion;
      });
    }

    return filteredRegions;
  }, [regions, focusedRegion, isPerContentMode]);

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
      {displayedRegions.map((region, index) => (
        <React.Fragment key={region.id}>
          {focusedRegion === region.id && region.id === DEFAULT_REGION ? (
            <Box>
              {index > 0 && (
                <Separator orientation="horizontal" size="4" my="2" />
              )}{' '}
              <RegionLayer
                region={region}
                isPage={region.id === DEFAULT_REGION}
              />
              {index < displayedRegions.length - 1 && (
                <Separator orientation="horizontal" size="4" my="2" />
              )}
            </Box>
          ) : (
            <RegionLayer region={region} />
          )}
        </React.Fragment>
      ))}
    </Box>
  );
};

export default Layers;
