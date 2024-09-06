import type React from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectIsContextMenuOpen,
  setIsContextMenuOpen,
  setSelectedComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import { DropdownMenu } from '@radix-ui/themes';
import {
  deleteNode,
  duplicateNode,
  shiftNode,
} from '@/features/layout/layoutModelSlice';
import type { ViewPortSize } from '@/features/layout/preview/Viewport';

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

  function handleDeleteClick() {
    dispatch(setIsContextMenuOpen(undefined));
    dispatch(unsetHoveredComponent());
    if (elementId) {
      dispatch(deleteNode(elementId));
    }
  }

  function handleSelectClick() {
    dispatch(unsetHoveredComponent());
    dispatch(setIsContextMenuOpen(undefined));
    if (elementId) {
      dispatch(setSelectedComponent(elementId));
    }
  }
  function handleDuplicateClick() {
    dispatch(setIsContextMenuOpen(undefined));
    dispatch(unsetHoveredComponent());

    if (elementId) {
      dispatch(duplicateNode({ uuid: elementId }));
    }
  }

  function handleMoveUpClick() {
    dispatch(setIsContextMenuOpen(undefined));
    dispatch(unsetHoveredComponent());

    dispatch(shiftNode({ uuid: elementId, direction: 'up' }));
  }

  function handleMoveDownClick() {
    dispatch(setIsContextMenuOpen(undefined));
    dispatch(unsetHoveredComponent());

    dispatch(shiftNode({ uuid: elementId, direction: 'down' }));
  }

  if (contextMenuOpen === viewportSize && isPositionUpdated) {
    return (
      <>
        <DropdownMenu.Root open={!!contextMenuOpen}>
          <DropdownMenu.Content
            style={{
              top: contextMenuPosition.y,
              left: contextMenuPosition.x,
              position: 'absolute',
              pointerEvents: 'all',
            }}
          >
            <DropdownMenu.Item shortcut="⌘ E" onClick={handleSelectClick}>
              Edit
            </DropdownMenu.Item>
            <DropdownMenu.Item shortcut="⌘ D" onClick={handleDuplicateClick}>
              Duplicate
            </DropdownMenu.Item>
            <DropdownMenu.Separator />

            <DropdownMenu.Sub>
              <DropdownMenu.SubTrigger>Move</DropdownMenu.SubTrigger>
              <DropdownMenu.SubContent>
                <DropdownMenu.Item onClick={handleMoveUpClick}>
                  Move up
                </DropdownMenu.Item>
                <DropdownMenu.Item onClick={handleMoveDownClick}>
                  Move down
                </DropdownMenu.Item>

                <DropdownMenu.Separator />
                <DropdownMenu.Item onClick={() => alert('Todo')}>
                  Move into
                </DropdownMenu.Item>
              </DropdownMenu.SubContent>
            </DropdownMenu.Sub>
            <DropdownMenu.Separator />
            <DropdownMenu.Item
              // shortcut="⌘ ⌫"
              color="red"
              onClick={handleDeleteClick}
            >
              Delete
            </DropdownMenu.Item>
          </DropdownMenu.Content>
        </DropdownMenu.Root>
      </>
    );
  }
};

export default RightClickMenu;
