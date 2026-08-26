import { Box } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import PageVariantLayer from '@/features/layout/layers/PageVariantLayer';
import RegionLayer from '@/features/layout/layers/RegionLayer';
import { selectContentRegion } from '@/features/layout/layoutModelSlice';
import useExpandParentsOnSelection from '@/hooks/useExpandParentsOnSelection';
import useSyncCollapsedLayersLocalStorage from '@/hooks/useSyncCollapsedLayersLocalStorage';

import type React from 'react';

interface LayersProps {}

const Layers: React.FC<LayersProps> = () => {
  const contentRegion = useAppSelector(selectContentRegion);
  useSyncCollapsedLayersLocalStorage();
  useExpandParentsOnSelection();

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
