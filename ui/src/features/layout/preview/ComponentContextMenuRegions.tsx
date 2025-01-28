// cspell:ignore CCMR
import type React from 'react';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import { useCallback, useMemo } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import type {
  ComponentNode,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import { moveNode } from '@/features/layout/layoutModelSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findParentRegion } from '@/features/layout/layoutUtils';

interface CCMRProps {
  component: ComponentNode;
}

const ComponentUnifiedMenuRegions: React.FC<CCMRProps> = (props) => {
  const { component } = props;
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);

  const parentRegion = useMemo(() => {
    return findParentRegion(layout, component.uuid);
  }, [layout, component.uuid]);

  const handleMoveClick = useCallback(
    (destinationRegionIndex: number, destinationRegion: RegionNode) => {
      const currentRegionIndex = layout.findIndex(
        (region) => region.id === parentRegion?.id,
      );

      // When component is moved down/below (based on layers panel), it’s added to the beginning of the region
      // When component is moved up/above (based on layers panel), it’s added to the end of the region
      let componentIndex = 0;
      if (currentRegionIndex > destinationRegionIndex) {
        componentIndex = destinationRegion.components.length;
      }
      dispatch(
        moveNode({
          uuid: component.uuid,
          to: [destinationRegionIndex, componentIndex],
        }),
      );
    },
    [layout, dispatch, component.uuid, parentRegion?.id],
  );

  return (
    <UnifiedMenu.Sub>
      <UnifiedMenu.SubTrigger>Move to global region</UnifiedMenu.SubTrigger>
      <UnifiedMenu.SubContent>
        {layout.map((region, ix) => (
          <UnifiedMenu.Item
            key={region.id}
            onClick={() => handleMoveClick(ix, region)}
            disabled={region.id === parentRegion?.id}
          >
            {region.name}
          </UnifiedMenu.Item>
        ))}
      </UnifiedMenu.SubContent>
    </UnifiedMenu.Sub>
  );
};

export default ComponentUnifiedMenuRegions;
