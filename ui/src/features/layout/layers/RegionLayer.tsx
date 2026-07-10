import { useCallback } from 'react';
import { Box } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import {
  selectIsComponentHovered,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

const RegionLayer: React.FC<{ region: RegionNode }> = ({ region }) => {
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });

  // Prevent selecting text when double-clicking the page node (double-click normally selects text).
  const handleMouseDown = useCallback((event: React.MouseEvent) => {
    if (event.detail > 1) {
      event.preventDefault();
    }
  }, []);

  const handleMouseOver = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(setHoveredComponent(region.id));
    },
    [dispatch, region.id],
  );

  const handleMouseOut = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(unsetHoveredComponent());
    },
    [dispatch],
  );

  return (
    <Box>
      <SidebarNode
        onMouseDown={handleMouseDown}
        onMouseOver={handleMouseOver}
        onMouseOut={handleMouseOut}
        draggable={false}
        title={region.name}
        variant="page"
        open={true}
        hovered={isHovered}
        data-hovered={isHovered}
      />
      <Box role="tree">
        {region.components.map((component, index) => (
          <ComponentLayer
            index={index}
            key={component.uuid}
            component={component}
            parentNode={region}
            indent={1}
          />
        ))}
      </Box>
    </Box>
  );
};

export default RegionLayer;
