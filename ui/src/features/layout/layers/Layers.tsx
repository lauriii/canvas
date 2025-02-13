import type React from 'react';
import { Box } from '@radix-ui/themes';
import { useAppSelector } from '@/app/hooks';

import { selectLayout } from '@/features/layout/layoutModelSlice';
import RegionLayer from '@/features/layout/layers/RegionLayer';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import useXbParams from '@/hooks/useXbParams';

interface LayersProps {}

const Layers: React.FC<LayersProps> = () => {
  const regions = useAppSelector(selectLayout);
  const { regionId: focusedRegion = DEFAULT_REGION } = useXbParams();

  let displayedRegions = regions.filter((region) => {
    return region.components.length > 0 || region.id === DEFAULT_REGION;
  });

  if (focusedRegion !== DEFAULT_REGION) {
    displayedRegions = displayedRegions.filter((region) => {
      return region.id === focusedRegion;
    });
  }

  return (
    <Box>
      {displayedRegions.map((region) => (
        <RegionLayer region={region} key={region.id} />
      ))}
    </Box>
  );
};

export default Layers;
