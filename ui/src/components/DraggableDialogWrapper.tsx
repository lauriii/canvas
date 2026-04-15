import React, { useCallback, useMemo, useRef, useState } from 'react';
import clsx from 'clsx';
import { Dialog } from 'radix-ui';
import { Box, Theme } from '@radix-ui/themes';

import Panel from '@/components/Panel';

import type { ReactNode } from 'react';

import styles from './DraggableDialogWrapper.module.css';

interface DraggableDialogWrapperProps {
  onOpenChange: Function;
  open: boolean;
  description: ReactNode;
  children: ReactNode;
}

const PANEL_PADDING = '4';

const DraggableDialogWrapper: React.FC<DraggableDialogWrapperProps> = ({
  onOpenChange,
  open,
  description,
  children,
}) => {
  const dialogWidth = 500;
  const windowWidth = window.visualViewport?.width || 100;
  const windowHeight = window.visualViewport?.height || 100;
  const [isDragging, setIsDragging] = useState(false);
  const initialPosition = useMemo(() => {
    return {
      x: windowWidth / 2 - dialogWidth / 2,
      y: (windowHeight / 100) * 20, // 20% from the top
    };
  }, [windowHeight, windowWidth, dialogWidth]);
  const [position, setPosition] = useState(initialPosition);
  const dialogRef = useRef<HTMLDivElement | null>(null);

  const handleOpenChange = useCallback(
    (open: boolean) => {
      setIsDragging(false);
      onOpenChange(open);
      setPosition(initialPosition);
    },
    [initialPosition, onOpenChange],
  );

  const handleMouseDown = (event: React.MouseEvent<HTMLDivElement>) => {
    // Only begin dragging from primary (left) mouse button.
    if (event.button !== 0) {
      return;
    }
    setIsDragging(true);
  };

  const handleMouseMove = useCallback(
    (event: MouseEvent) => {
      // Stop stale drag states if we missed mouseup outside the document/iframe.
      if (isDragging && event.buttons === 0) {
        setIsDragging(false);
        return;
      }
      if (isDragging && dialogRef.current) {
        // Ensure the dialog cannot be dragged so far off the edge that it can't be dragged back on again.
        const innerBound = 40;
        const minX = 0 - dialogWidth + innerBound;
        const maxX = windowWidth - innerBound;
        const minY = 0 - innerBound / 2;
        const maxY = windowHeight - innerBound;
        setPosition((prevPosition) => {
          const newX = prevPosition.x + event.movementX;
          const newY = prevPosition.y + event.movementY;

          return {
            x: Math.max(minX, Math.min(newX, maxX)),
            y: Math.max(minY, Math.min(newY, maxY)),
          };
        });
      }
    },
    [isDragging, windowHeight, windowWidth],
  );

  const handleMouseUp = () => {
    setIsDragging(false);
  };

  React.useEffect(() => {
    if (isDragging) {
      document.addEventListener('mousemove', handleMouseMove);
      document.addEventListener('mouseup', handleMouseUp);
      window.addEventListener('blur', handleMouseUp);
    } else {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
      window.removeEventListener('blur', handleMouseUp);
    }

    return () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
      window.removeEventListener('blur', handleMouseUp);
    };
  }, [handleMouseMove, isDragging]);

  return (
    <Dialog.Root modal={false} open={open} onOpenChange={handleOpenChange}>
      <Dialog.Portal>
        <Theme>
          <Dialog.Content
            className={clsx(styles.DialogContent)}
            onEscapeKeyDown={(e) => {
              // @todo https://www.drupal.org/i/3506657: This can be removed once we stop using esc key events to close the context menu.
              e.preventDefault();
            }}
            asChild
            style={{
              transform: `translate(${position.x}px, ${position.y}px)`,
              position: 'absolute',
            }}
            onPointerDownOutside={(event) => {
              event.preventDefault();
            }}
            onInteractOutside={(event) => {
              event.preventDefault();
            }}
            ref={dialogRef}
            // aria-describedby={undefined} is needed when there is no description.
            // @see https://www.radix-ui.com/primitives/docs/components/dialog#description
            {...(!description && { 'aria-describedby': undefined })}
          >
            <Panel p={PANEL_PADDING}>
              <Box
                mt={`-${PANEL_PADDING}`}
                pt={PANEL_PADDING}
                mx={`-${PANEL_PADDING}`}
                px={PANEL_PADDING}
                className={styles.DraggableArea}
                onMouseDown={handleMouseDown}
                style={{
                  cursor: 'move',
                }}
              />

              {children}
            </Panel>
          </Dialog.Content>
        </Theme>
      </Dialog.Portal>
    </Dialog.Root>
  );
};

export default DraggableDialogWrapper;
