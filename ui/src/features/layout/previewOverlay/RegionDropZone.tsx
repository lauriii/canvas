import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { kebabCase } from 'lodash';
import { useDroppable } from '@dnd-kit/core';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface RegionDropZoneProps {
  region: RegionNode;
  position: 'before' | 'after';
}
const RegionDropZone: React.FC<RegionDropZoneProps> = (props) => {
  const { region, position } = props;
  const layout = useAppSelector(selectLayout);
  const [activeOrigin, setActiveOrigin] = useState('');
  const accepts = ['overlay', 'library'];

  const regionIndex = layout.findIndex((r) => r.id === region.id);
  const regionPath = [regionIndex];

  if (position === 'after') {
    regionPath.push(layout[regionIndex].components.length);
  } else {
    regionPath.push(0);
  }

  const positionLabel = position === 'before' ? 'start' : 'end';
  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: `${region.id}_${position}`,
    disabled: !accepts.includes(activeOrigin),
    data: {
      region: region,
      parentRegion: region,
      path: regionPath,
      accepts,
    },
  });

  useEffect(() => {
    if (active) {
      setActiveOrigin(active.data?.current?.origin);
    } else {
      setActiveOrigin('');
    }
  }, [active]);

  const dropzoneStyle = styles[position];

  return (
    <div
      className={clsx(styles.regionDropZone, dropzoneStyle, {
        [styles.isOver]: isOver,
      })}
      ref={setDropRef}
      data-testid={`canvas-region-drop-zone-${positionLabel}-${kebabCase(region.name)}`}
    ></div>
  );
};

export default RegionDropZone;
