import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { kebabCase } from 'lodash';
import { useDroppable } from '@dnd-kit/core';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import useGetComponentName from '@/hooks/useGetComponentName';

import type React from 'react';
import type {
  ComponentNode,
  RegionNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface ComponentDropZoneProps {
  component: ComponentNode;
  position: 'top' | 'bottom' | 'left' | 'right';
  parentSlot?: SlotNode;
  parentRegion?: RegionNode;
}
const ComponentDropZone: React.FC<ComponentDropZoneProps> = (props) => {
  const { component, position, parentSlot, parentRegion } = props;
  const layout = useAppSelector(selectLayout);
  const [draggedItem, setDraggedItem] = useState('');
  const componentName = useGetComponentName(component);
  const [activeOrigin, setActiveOrigin] = useState('');
  const accepts = ['overlay', 'library'];

  function getPositionRelation(position: ComponentDropZoneProps['position']) {
    return position === 'top' || position === 'left' ? 'before' : 'after';
  }

  const dropPath = findNodePathByUuid(layout, component.uuid);
  if (!dropPath) {
    throw new Error(
      `Unable to ascertain 'path' to component ${component.uuid}`,
    );
  }
  if (dropPath) {
    if (position === 'bottom' || position === 'right') {
      dropPath[dropPath.length - 1] += 1;
    }
  }

  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: `${component.uuid}_${position}`,
    disabled: draggedItem === component.uuid || !accepts.includes(activeOrigin),
    data: {
      component: component,
      parentSlot: parentSlot,
      parentRegion: parentRegion,
      path: dropPath,
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

  useEffect(() => {
    // use the id of the dragged to disable it's dropzone so you can't drop it inside itself.
    setDraggedItem((active?.id as string) || '');
  }, [active]);

  const dropzoneStyle = styles[position];

  return (
    <div
      className={clsx(styles.componentDropZone, dropzoneStyle, {
        [styles.isOver]: isOver,
      })}
      ref={setDropRef}
      data-testid={`canvas-component-drop-zone-${getPositionRelation(position)}-${kebabCase(componentName)}`}
      // aria-label={`Drop items ${getPositionRelation(position)} ${componentName}`}
    ></div>
  );
};

export default ComponentDropZone;
