import { useEffect } from 'react';
import { useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Spotlight } from '@/components/spotlight/Spotlight';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import {
  clearSelection,
  DEFAULT_REGION,
  selectDragging,
} from '@/features/ui/uiSlice';

export const RegionSpotlight = () => {
  const { geometryMap } = usePreviewGeometry();
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  const regionGeometry = geometryMap.region[focusedRegion];
  const { isDragging } = useAppSelector(selectDragging);
  const dispatch = useAppDispatch();

  useEffect(() => {
    // When focusing into a different region, clear the multi selection
    dispatch(clearSelection());
  }, [dispatch, focusedRegion]);

  if (focusedRegion !== DEFAULT_REGION && regionGeometry) {
    return (
      <Spotlight
        top={regionGeometry.rect.top}
        left={regionGeometry.rect.left}
        width={regionGeometry.rect.width}
        height={regionGeometry.rect.height}
        blocking={!isDragging}
      />
    );
  }
  return null;
};
