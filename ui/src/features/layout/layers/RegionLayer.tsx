import { Box } from '@radix-ui/themes';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import type { RegionNode } from '@/features/layout/layoutModelSlice';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import type React from 'react';
import { useCallback } from 'react';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useNavigate, useParams } from 'react-router-dom';
import {
  DEFAULT_REGION,
  selectIsComponentHovered,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';

const RegionLayer: React.FC<{ region: RegionNode; isPage?: boolean }> = ({
  region,
  isPage = false,
}) => {
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  const { setSelectedRegion } = useEditorNavigation();
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });

  // Navigate to the clicked region, or back out to the content region if we are focused in the clicked region already
  const handleRegionClick = useCallback(() => {
    if (focusedRegion === region.id) {
      navigate('/editor');
    } else {
      setSelectedRegion(region.id);
    }
  }, [focusedRegion, navigate, region.id, setSelectedRegion]);

  // Prevent selecting text when double-clicking regions in the layers panel (double-click normally selects text).
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
        onDoubleClick={handleRegionClick}
        onMouseDown={handleMouseDown}
        onMouseOver={handleMouseOver}
        onMouseOut={handleMouseOut}
        draggable={false}
        title={region.name}
        variant={isPage ? 'page' : 'region'}
        open={region.id === focusedRegion}
        hovered={isHovered}
        data-hovered={isHovered}
      />

      {region.id === focusedRegion && (
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
      )}
    </Box>
  );
};

export default RegionLayer;
