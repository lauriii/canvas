import { Box } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
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
      <RegionLayer region={contentRegion} />
    </Box>
  );
};

export default Layers;
