import { CubeIcon } from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';
import styles from './Layers.module.css';
import type { RegionNode } from '@/features/layout/layoutModelSlice';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import { useCallback } from 'react';
import clsx from 'clsx';
import SortableContainer from '@/features/layout/layers/SortableContainer';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import { useNavigate, useParams } from 'react-router-dom';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
const RegionLayer: React.FC<{ region: RegionNode }> = ({ region }) => {
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
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
      <Flex
        p="2"
        align="center"
        onDoubleClick={handleRegionClick}
        onMouseDown={handleMouseDown}
        className={clsx(styles.layer, styles.regionLayer, {
          [styles.selected]: focusedRegion === region.id,
        })}
      >
        <Box width="var(--space-4)" mr="2">
          <CubeIcon className={styles.regionIcon} />
        </Box>
        <Text size="1">{region.name}</Text>
      </Flex>
      {region.id === focusedRegion && (
        <Box className="region-children">
          <SortableContainer slotId={region.id} indent={0}>
            {region.components.map((component) => (
              <ComponentLayer
                key={component.uuid}
                component={component}
                parentNode={region}
                indent={0}
              />
            ))}
          </SortableContainer>
        </Box>
      )}
    </Box>
  );
};

export default RegionLayer;
