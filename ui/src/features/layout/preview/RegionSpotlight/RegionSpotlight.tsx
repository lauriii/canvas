import { useEffect, useState } from 'react';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import { DEFAULT_REGION, selectDragging } from '@/features/ui/uiSlice';
import { Spotlight } from '@/components/spotlight/Spotlight';
import useXbParams from '@/hooks/useXbParams';
import { useAppSelector } from '@/app/hooks';
type Props = {};

export const RegionSpotlight = (props: Props) => {
  const { regionsMap } = useDataToHtmlMapValue();
  const { regionId: focusedRegion = DEFAULT_REGION } = useXbParams();
  const [spotlight, setSpotlight] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const { isDragging } = useAppSelector(selectDragging);

  useEffect(() => {
    if (focusedRegion && regionsMap) {
      const regionEl = regionsMap[focusedRegion]?.element;
      if (regionEl && focusedRegion !== DEFAULT_REGION) {
        setSpotlight(true);
        setRect(regionEl.getBoundingClientRect());
        return;
      }
    }
    setSpotlight(false);
  }, [focusedRegion, regionsMap]);

  if (spotlight && rect) {
    return (
      <Spotlight
        top={rect.top + 40}
        left={rect.left}
        width={rect.width}
        height={rect.height - 80}
        blocking={!isDragging}
      />
    );
  }
  return null;
};
