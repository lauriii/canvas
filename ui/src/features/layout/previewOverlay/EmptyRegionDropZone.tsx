import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { kebabCase } from 'lodash';
import TemplateIcon from '@assets/icons/template.svg?react';
import { useDroppable } from '@dnd-kit/core';
import { FileTextIcon } from '@radix-ui/react-icons';
import { Text } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import { useTemplateCaption } from '@/hooks/useTemplateCaption';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface EmptyRegionDropZoneProps {
  region: RegionNode;
}
const EmptyRegionDropZone: React.FC<EmptyRegionDropZoneProps> = (props) => {
  const { region } = props;
  const layout = useAppSelector(selectLayout);
  const [activeName, setActiveName] = useState('');
  const [activeOrigin, setActiveOrigin] = useState('');
  const isTemplateRoute =
    useAppSelector(selectEditorFrameContext) === 'template';

  const regionIndex = layout.findIndex((r) => r.id === region.id);
  const regionPath = [regionIndex, 0];
  const accepts = ['overlay', 'library'];

  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: region.id,
    disabled: !accepts.includes(activeOrigin),
    data: {
      region: region,
      parentRegion: region,
      path: regionPath,
      accepts,
    },
  });

  const templateCaption = useTemplateCaption();

  useEffect(() => {
    if (isOver && active) {
      setActiveName(active.data?.current?.name);
    } else {
      setActiveName('');
    }
  }, [active, isOver]);

  useEffect(() => {
    if (active) {
      setActiveOrigin(active.data?.current?.origin);
    } else {
      setActiveOrigin('');
    }
  }, [active]);

  return (
    <div className={styles.emptyPageContainer}>
      <div
        className={clsx(styles.emptyPageDropZone, {
          [styles.isOver]: isOver,
        })}
        ref={setDropRef}
        data-testid={`canvas-empty-region-drop-zone-${kebabCase(region.name)}`}
      >
        {activeName ? (
          activeName
        ) : (
          <>
            {isTemplateRoute ? <TemplateIcon /> : <FileTextIcon />}
            <Text weight={'medium'} mt="2" trim="start">
              {isTemplateRoute ? templateCaption || 'Template' : 'Page content'}
            </Text>
            <div className={styles.regionMessage}>Place items here</div>
          </>
        )}
      </div>
    </div>
  );
};

export default EmptyRegionDropZone;
