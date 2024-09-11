import type React from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectIsContextMenuOpen,
  setIsContextMenuOpen,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import type { ViewPortSize } from '@/features/layout/preview/Viewport';
import DropDownContextMenu from './DropDownContextMenu';

export interface RightClickMenuProps {
  viewportSize: ViewPortSize;
  elementId: string;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  mouseEventPosition: { pageX: number; pageY: number };
}

const RightClickMenu: React.FC<RightClickMenuProps> = (props) => {
  const { elementId, iframeRef, viewportSize, mouseEventPosition } = props;
  const contextMenuOpen = useAppSelector(selectIsContextMenuOpen);
  const [contextMenuPosition, setContextMenuPosition] = useState<{
    x: number;
    y: number;
  }>({ x: 0, y: 0 });
  const [isPositionUpdated, setIsPositionUpdated] = useState(false);
  const contextMenuOpenRef = useRef(contextMenuOpen);
  const dispatch = useAppDispatch();

  useEffect(() => {
    contextMenuOpenRef.current = contextMenuOpen;
  }, [contextMenuOpen]);

  const updateContextMenuPosition = useCallback(() => {
    if (iframeRef.current) {
      const iframeRect = iframeRef.current.getBoundingClientRect();
      setContextMenuPosition({
        x: iframeRect.x + mouseEventPosition.pageX,
        y: iframeRect.y + mouseEventPosition.pageY,
      });
      setIsPositionUpdated(true);
    }
  }, [iframeRef, mouseEventPosition]);

  useEffect(() => {
    if (contextMenuOpen === viewportSize) {
      setIsPositionUpdated(false);
      updateContextMenuPosition();
    }
  }, [contextMenuOpen, updateContextMenuPosition, viewportSize]);

  const handleLeftClick = useCallback(
    (event: MouseEvent) => {
      if (contextMenuOpenRef.current) {
        event.preventDefault();
        dispatch(setIsContextMenuOpen(undefined));
        dispatch(unsetHoveredComponent());
        setIsPositionUpdated(false);
      }
    },
    [dispatch],
  );

  useEffect(() => {
    document.addEventListener('click', handleLeftClick);
    document.addEventListener('contextmenu', handleLeftClick);
    return () => {
      document.removeEventListener('click', handleLeftClick);
      document.removeEventListener('contextmenu', handleLeftClick);
    };
  }, [handleLeftClick]);

  if (contextMenuOpen === viewportSize && isPositionUpdated) {
    return (
      <>
        <DropDownContextMenu
          elementId={elementId}
          contextMenuPosition={contextMenuPosition}
          contextMenuOpen={contextMenuOpen}
        />
      </>
    );
  }
};

export default RightClickMenu;
