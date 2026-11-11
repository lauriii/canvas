import { useMemo } from 'react';
import clsx from 'clsx';
import { kebabCase } from 'lodash';
import { useDroppable } from '@dnd-kit/core';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { selectDragging } from '@/features/ui/uiSlice';
import useGetComponentName from '@/hooks/useGetComponentName';

import type React from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface ComponentDropZoneProps {
  component: ComponentNode;
  position: 'top' | 'bottom' | 'left' | 'right';
  parentSlot?: SlotNode;
}
const ComponentDropZone: React.FC<ComponentDropZoneProps> = (props) => {
  const { component, position, parentSlot } = props;
  const layout = useAppSelector(selectLayout);
  const componentName = useGetComponentName(component);
  // Only components from the preview or the library can be dropped here.
  const { previewDragging, listDragging } = useAppSelector(selectDragging);
  const acceptsActive = previewDragging || listDragging;
  const accepts = ['overlay', 'library'];

  function getPositionRelation(position: ComponentDropZoneProps['position']) {
    return position === 'top' || position === 'left' ? 'before' : 'after';
  }

  const dropPath = useMemo(() => {
    const path = findNodePathByUuid(layout, component.uuid);
    if (!path) {
      throw new Error(
        `Unable to ascertain 'path' to component ${component.uuid}`,
      );
    }
    if (position === 'bottom' || position === 'right') {
      path[path.length - 1] += 1;
    }
    return path;
  }, [layout, component.uuid, position]);

  // ComponentOverlay doesn't render the drop zones of the dragged component.
  const { setNodeRef: setDropRef, isOver } = useDroppable({
    id: `${component.uuid}_${position}`,
    disabled: !acceptsActive,
    data: {
      component: component,
      parentSlot: parentSlot,
      path: dropPath,
      accepts,
    },
  });

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
