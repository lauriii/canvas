import { Box } from '@radix-ui/themes';
import SidebarNode from '@/components/sidebar/SidebarNode';
import type { RegionNode } from '@/features/layout/layoutModelSlice';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import { useCallback } from 'react';
import SortableContainer from '@/features/layout/layers/SortableContainer';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import { useNavigate } from 'react-router-dom';
import useXbParams from '@/hooks/useXbParams';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';

const RegionLayer: React.FC<{ region: RegionNode }> = ({ region }) => {
  const { regionId: focusedRegion = DEFAULT_REGION } = useXbParams();
  const { setSelectedRegion } = useNavigationUtils();
  const navigate = useNavigate();

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

  return (
    <Box>
      <SidebarNode
        onDoubleClick={handleRegionClick}
        onMouseDown={handleMouseDown}
        title={region.name}
        variant="region"
        open={region.id === focusedRegion}
      />

      {region.id === focusedRegion && (
        <Box>
          <SortableContainer slotId={region.id} indent={0}>
            {region.components.map((component) => (
              <ComponentLayer
                key={component.uuid}
                component={component}
                parentNode={region}
                indent={1}
              />
            ))}
          </SortableContainer>
        </Box>
      )}
    </Box>
  );
};

export default RegionLayer;
