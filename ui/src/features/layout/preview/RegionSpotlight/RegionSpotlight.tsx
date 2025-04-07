import { useEffect, useState } from 'react';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import { DEFAULT_REGION, selectDragging } from '@/features/ui/uiSlice';
import { Spotlight } from '@/components/spotlight/Spotlight';
import useXbParams from '@/hooks/useXbParams';
import { useAppSelector } from '@/app/hooks';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';
type Props = {};

export const RegionSpotlight = (props: Props) => {
  const { regionsMap } = useDataToHtmlMapValue();
  const { regionId: focusedRegion = DEFAULT_REGION } = useXbParams();
  const [spotlight, setSpotlight] = useState(false);
  const rect = useSyncPreviewElementSize(regionsMap[focusedRegion]?.elements);
  const { isDragging } = useAppSelector(selectDragging);

  useEffect(() => {
    if (focusedRegion && regionsMap) {
      if (focusedRegion !== DEFAULT_REGION) {
        setSpotlight(true);
        return;
      }
    }
    setSpotlight(false);
  }, [focusedRegion, regionsMap]);

  if (spotlight && rect) {
    return (
      <Spotlight
        top={rect.top}
        left={rect.left}
        width={rect.width}
        height={rect.height}
        blocking={!isDragging}
      />
    );
  }
  return null;
};
