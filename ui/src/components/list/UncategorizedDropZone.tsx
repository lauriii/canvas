import clsx from 'clsx';
import { useDroppable } from '@dnd-kit/core';

import { useAppSelector } from '@/app/hooks';
import { selectDragging } from '@/features/ui/uiSlice';
import { usePermissionCheck } from '@/hooks/usePermissionCheck';

import type React from 'react';

import styles from './List.module.css';

interface UncategorizedDropZoneProps {
  itemType: string;
  hasItems: boolean;
  children: React.ReactNode;
}

const UncategorizedDropZone: React.FC<UncategorizedDropZoneProps> = ({
  itemType,
  hasItems,
  children,
}) => {
  const { previewDragging } = useAppSelector(selectDragging);
  const administerFolders = usePermissionCheck({
    hasPermission: 'folders',
  });
  const { setNodeRef, isOver, active } = useDroppable({
    id: `uncategorized-${itemType}`,
    data: {
      destination: 'uncategorized',
      accepts: ['library', 'code'],
    },
    disabled: !administerFolders || previewDragging,
  });

  const isValidDrag =
    active?.data?.current &&
    ['library', 'code'].includes(active.data.current.origin) &&
    active.data.current.type !== 'folder';

  return (
    <div
      ref={setNodeRef}
      data-testid={`canvas-uncategorized-drop-zone-${itemType}`}
      className={clsx({
        [styles.emptyDropZone]: !hasItems,
        [styles.isOver]: isOver && isValidDrag,
      })}
    >
      {children}
    </div>
  );
};

export default UncategorizedDropZone;
