import { useEffect, useState } from 'react';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import {
  clearSelection,
  DEFAULT_REGION,
  selectDragging,
} from '@/features/ui/uiSlice';
import { Spotlight } from '@/components/spotlight/Spotlight';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';
import { useParams } from 'react-router';

export const RegionSpotlight = () => {
  const { regionsMap } = useDataToHtmlMapValue();
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  const [spotlight, setSpotlight] = useState(false);
  const rect = useSyncPreviewElementSize(regionsMap[focusedRegion]?.elements);
  const { isDragging } = useAppSelector(selectDragging);
  const dispatch = useAppDispatch();

  useEffect(() => {
    // When focusing into a different region, clear the multi selection
    dispatch(clearSelection());
  }, [dispatch, focusedRegion]);

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
