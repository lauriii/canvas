import type React from 'react';
import { useEffect, useState } from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import clsx from 'clsx';
import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';
import { useDroppable } from '@dnd-kit/core';
import { useAppSelector } from '@/app/hooks';
import { FileIcon } from '@radix-ui/react-icons';
import { Text } from '@radix-ui/themes';

export interface EmptyRegionDropZoneProps {
  region: RegionNode;
}
const EmptyRegionDropZone: React.FC<EmptyRegionDropZoneProps> = (props) => {
  const { region } = props;
  const layout = useAppSelector(selectLayout);
  const [activeName, setActiveName] = useState('');

  const regionIndex = layout.findIndex((r) => r.id === region.id);
  const regionPath = [regionIndex, 0];

  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: region.id,
    data: {
      region: region,
      parentRegion: region,
      path: regionPath,
    },
  });

  useEffect(() => {
    if (isOver && active) {
      setActiveName(active.data?.current?.name);
    } else {
      setActiveName('');
    }
  }, [active, isOver]);

  return (
    <div className={styles.emptyPageContainer}>
      <div
        className={clsx(styles.emptyPageDropZone, {
          [styles.isOver]: isOver,
        })}
        ref={setDropRef}
      >
        {activeName ? (
          activeName
        ) : (
          <>
            <FileIcon />
            <Text weight={'medium'} mt="2" trim="start">
              Page content
            </Text>
            <div className={styles.regionMessage}>Place items here</div>
          </>
        )}
      </div>
    </div>
  );
};

export default EmptyRegionDropZone;
