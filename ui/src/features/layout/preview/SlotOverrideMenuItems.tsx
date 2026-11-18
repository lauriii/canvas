import { useAppDispatch } from '@/app/hooks';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import { revertSlotOverride } from '@/features/layout/layoutModelSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useOverrideSlot from '@/hooks/useOverrideSlot';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

interface SlotOverrideMenuItemsProps {
  // A per-content exposed slot region that is inherited or overridden.
  region: RegionNode;
  isInherited: boolean;
  testIdPrefix: string;
}

/**
 * Per-content editing: the menu items of an exposed slot region. An inherited
 * slot offers Override; an overridden slot offers Revert to default.
 */
const SlotOverrideMenuItems: React.FC<SlotOverrideMenuItemsProps> = ({
  region,
  isInherited,
  testIdPrefix,
}) => {
  const dispatch = useAppDispatch();
  const { unsetSelectedComponent } = useComponentSelection();
  const overrideSlot = useOverrideSlot();

  return (
    <>
      <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
      <UnifiedMenu.Separator />
      {isInherited ? (
        <UnifiedMenu.Item
          onClick={() => overrideSlot(region.id)}
          data-testid={`${testIdPrefix}-override-${region.id}`}
        >
          Override
        </UnifiedMenu.Item>
      ) : (
        <UnifiedMenu.Item
          onClick={() => {
            dispatch(revertSlotOverride(region.id));
            // The reverted content is gone: drop any selection of it,
            // including the routed /component/:uuid URL.
            unsetSelectedComponent();
          }}
          data-testid={`${testIdPrefix}-revert-${region.id}`}
        >
          Revert to default
        </UnifiedMenu.Item>
      )}
    </>
  );
};

export default SlotOverrideMenuItems;
