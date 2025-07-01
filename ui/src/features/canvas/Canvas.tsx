import type React from 'react';
import { useLayoutEffect } from 'react';
import { useEffect, useRef, useState, useCallback } from 'react';
import styles from './Canvas.module.css';
import clsx from 'clsx';
import Preview from '@/features/layout/preview/Preview';
import { useHotkeys } from 'react-hotkeys-hook';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import {
  selectCanvasViewPort,
  canvasViewPortZoomIn,
  canvasViewPortZoomOut,
  setCanvasViewPort,
  setIsPanning,
  selectFirstLoadComplete,
  setCanvasModeEditing,
  setCanvasModeInteractive,
  setIsZooming,
  selectPanning,
  selectDragging,
} from '@/features/ui/uiSlice';
import PreviewOverlay from '@/features/layout/previewOverlay/PreviewOverlay';
import { deleteNode } from '../layout/layoutModelSlice';
import useCopyPasteComponents from '@/hooks/useCopyPasteComponents';
import useComponentSelection from '@/hooks/useComponentSelection';
import { useUndoRedo } from '@/hooks/useUndoRedo';
import ViewportToolbar from '@/features/layout/preview/ViewportToolbar';
import { useDebouncedCallback } from 'use-debounce';
import { getHalfwayScrollPosition } from '@/utils/function-utils';
import { useParams } from 'react-router';

const Canvas = () => {
  const dispatch = useAppDispatch();
  const canvasRef = useRef<HTMLDivElement | null>(null);
  const canvasPaneRef = useRef<HTMLDivElement | null>(null);
  const animFrameScrollRef = useRef<number | null>(null);
  const animFrameScaleRef = useRef<number | null>(null);
  const scalingContainerRef = useRef<HTMLDivElement | null>(null);
  const [startPos, setStartPos] = useState({ x: 0, y: 0 });
  const canvasViewPort = useAppSelector(selectCanvasViewPort);
  const firstLoadComplete = useAppSelector(selectFirstLoadComplete);
  const [isVisible, setIsVisible] = useState(false);
  const [middleMouseDown, setMiddleMouseDown] = useState(false);
  const isPanning = useAppSelector(selectPanning);
  const [modifierKeyPressed, setModifierKeyPressed] = useState(false);
  const modifierKeyPressedRef = useRef(false);
  const { componentId: selectedComponent } = useParams();
  const { unsetSelectedComponent } = useComponentSelection();
  const middleMouseDownRef = useRef(middleMouseDown);
  const { copySelectedComponent, pasteAfterSelectedComponent } =
    useCopyPasteComponents();
  const { isUndoable, dispatchUndo } = useUndoRedo();
  const { isDragging } = useAppSelector(selectDragging);

  useHotkeys(['NumpadAdd', 'Equal'], () => dispatch(canvasViewPortZoomIn()));
  useHotkeys(['Minus', 'NumpadSubtract'], () =>
    dispatch(canvasViewPortZoomOut()),
  );
  useHotkeys('ctrl', () => setModifierKeyPressed(true), {
    keydown: true,
    keyup: false,
  });
  useHotkeys('ctrl', () => setModifierKeyPressed(false), {
    keydown: false,
    keyup: true,
  });

  // TODO This should have a better keyboard shortcut, but as the Interactive mode is still
  // in development/buggy, leaving it as something obscure for now.
  useHotkeys('v', () => dispatch(setCanvasModeInteractive()), {
    keydown: true,
    keyup: false,
  });
  useHotkeys('v', () => dispatch(setCanvasModeEditing()), {
    keydown: false,
    keyup: true,
  });
  useHotkeys(['Backspace', 'Delete'], () => {
    if (selectedComponent) {
      dispatch(deleteNode(selectedComponent));
      unsetSelectedComponent();
    }
  });
  useHotkeys('mod+c', () => {
    copySelectedComponent();
  });
  useHotkeys('mod+v', () => {
    pasteAfterSelectedComponent();
  });

  const debouncedScrollPosUpdate = useDebouncedCallback(() => {
    if (!isDragging) {
      dispatch(
        setCanvasViewPort({
          x: canvasPaneRef.current?.scrollLeft,
          y: canvasPaneRef.current?.scrollTop,
        }),
      );
    }
  }, 250);

  const debouncedIsPanningUpdate = useDebouncedCallback(() => {
    dispatch(setIsPanning(false));
  }, 250);

  const debouncedIsZoomingUpdate = useDebouncedCallback(() => {
    dispatch(setIsZooming(false));
  }, 250);

  useEffect(() => {
    middleMouseDownRef.current = middleMouseDown;
  }, [middleMouseDown]);

  useEffect(() => {
    modifierKeyPressedRef.current = modifierKeyPressed;
  }, [modifierKeyPressed]);

  useEffect(() => {
    if (!firstLoadComplete) {
      return;
    }
    if (scalingContainerRef.current && canvasPaneRef.current) {
      // hardcoded value of 68 to account for the height of the UI (top bar 48px) + 20px gap.
      const previewHeight =
        scalingContainerRef.current.getBoundingClientRect().height;

      // Calculate the center offset inside the canvas.
      const canvasHeight = canvasPaneRef.current.scrollHeight;
      const centerOffset = (canvasHeight - previewHeight) / 2;

      const y = centerOffset - 50;
      const x = getHalfwayScrollPosition(canvasPaneRef.current);

      // Scroll the preview to the middle top.
      dispatch(setCanvasViewPort({ x: x, y: y }));
      setIsVisible(true);
    }
  }, [dispatch, firstLoadComplete]);

  useEffect(() => {
    // We can't update the scroll position while dragging is happening because DNDKit seems to cancel the drag operation
    // as soon as we do. So, when isDragging becomes false, we update the scroll position to make sure it's updated after.
    if (!isDragging) {
      debouncedScrollPosUpdate();
    }
  }, [debouncedScrollPosUpdate, isDragging]);

  const handlePaneScroll = useCallback(
    (event: React.UIEvent<HTMLDivElement>) => {
      if (event.currentTarget) {
        dispatch(setIsPanning(true));
        debouncedScrollPosUpdate();
        debouncedIsPanningUpdate();
      }
    },
    [debouncedIsPanningUpdate, debouncedScrollPosUpdate, dispatch],
  );

  const handleMouseDown = (e: React.MouseEvent<HTMLDivElement>) => {
    // Reset modifierKeyPressed to false if left button is clicked along with ctrl key press
    if (e.ctrlKey && e.button === 0) {
      setModifierKeyPressed(false);
      e.preventDefault();
      return;
    }
    if (e.button === 1) {
      const { clientX, clientY } = e;
      setMiddleMouseDown(true);
      dispatch(setIsPanning(true));
      if (canvasPaneRef.current) {
        setStartPos({
          x: clientX + canvasPaneRef.current.scrollLeft,
          y: clientY + canvasPaneRef.current.scrollTop,
        });
      }
      e.preventDefault();
    }
  };

  const handleCanvasMouseMove = (e: React.MouseEvent<HTMLDivElement>) => {
    if (middleMouseDownRef.current) {
      const { clientX, clientY } = e;
      const translationX = startPos.x - clientX;
      const translationY = startPos.y - clientY;

      if (animFrameScrollRef.current) {
        cancelAnimationFrame(animFrameScrollRef.current);
      }

      animFrameScrollRef.current = requestAnimationFrame(() => {
        if (scalingContainerRef.current) {
          scalingContainerRef.current.style.transform = `scale(${canvasViewPort.scale})`;
        }
        if (canvasPaneRef.current) {
          canvasPaneRef.current.scrollLeft = translationX;
          canvasPaneRef.current.scrollTop = translationY;
        }
        debouncedScrollPosUpdate();
      });
    }
  };

  const handleMouseUp = useCallback(() => {
    setMiddleMouseDown(false);
    debouncedIsPanningUpdate();
  }, [debouncedIsPanningUpdate]);

  // Track the last time we processed a wheel event.
  const lastWheelEventTimeRef = useRef<number>(0);
  const wheelEventBufferTimeMs = 50; // Only process wheel events every 50ms.

  const handleWheel = useCallback(
    (e: WheelEvent) => {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();

        // Throttle wheel events to avoid too rapid scaling.
        const now = Date.now();
        if (now - lastWheelEventTimeRef.current > wheelEventBufferTimeMs) {
          dispatch(setIsZooming(true));
          lastWheelEventTimeRef.current = now;
          // Only care about the direction, not the magnitude.
          if (e.deltaY > 0) {
            dispatch(canvasViewPortZoomOut());
          } else {
            dispatch(canvasViewPortZoomIn());
          }

          debouncedIsZoomingUpdate();
        }
      }
    },
    [debouncedIsZoomingUpdate, dispatch],
  );

  useEffect(() => {
    if (animFrameScrollRef.current) {
      cancelAnimationFrame(animFrameScrollRef.current);
    }

    animFrameScrollRef.current = requestAnimationFrame(() => {
      if (canvasPaneRef.current) {
        canvasPaneRef.current.scrollLeft = canvasViewPort.x;
        canvasPaneRef.current.scrollTop = canvasViewPort.y;
      }
    });
  }, [canvasViewPort.x, canvasViewPort.y]);

  useEffect(() => {
    if (animFrameScaleRef.current) {
      cancelAnimationFrame(animFrameScaleRef.current);
    }

    animFrameScaleRef.current = requestAnimationFrame(() => {
      if (scalingContainerRef.current) {
        scalingContainerRef.current.style.transform = `scale(${canvasViewPort.scale})`;
      }
    });
  }, [canvasViewPort.scale]);

  useEffect(() => {
    window.addEventListener('mouseup', handleMouseUp);
    window.addEventListener('wheel', handleWheel, { passive: false });

    return () => {
      window.removeEventListener('mouseup', handleMouseUp);
      window.removeEventListener('wheel', handleWheel);
    };
  }, [handleWheel, handleMouseUp]);

  useLayoutEffect(() => {
    if (!scalingContainerRef.current || !canvasRef.current) {
      return;
    }
    // Increase the total width/height of the canvas to accommodate the scaled xbCanvasScalingContainer.
    const rect = scalingContainerRef.current?.getBoundingClientRect();
    canvasRef.current.style.width = `${rect.width}px`;
    canvasRef.current.style.height = `${rect.height}px`;
  }, [canvasViewPort.scale]);

  return (
    <div className={styles.canvasContainer}>
      <div
        className={clsx(styles.canvasPane, {
          [styles.modifierKeyPressed]: modifierKeyPressed,
          [styles.isPanning]: isPanning,
        })}
        onMouseDown={handleMouseDown}
        onMouseMove={handleCanvasMouseMove}
        onScroll={handlePaneScroll}
        onMouseUp={handleMouseUp}
        onMouseLeave={handleMouseUp}
        ref={canvasPaneRef}
      >
        <div
          className={clsx(styles.canvas, {
            [styles.visible]: isVisible,
          })}
          // @ts-ignore
          style={{ '--canvas-scale': canvasViewPort.scale }}
          ref={canvasRef}
          data-testid="xb-canvas"
        >
          <div style={{ position: 'relative' }} id="positionAnchor">
            <div
              className={clsx(
                'xbCanvasScalingContainer',
                styles.xbCanvasScalingContainer,
              )}
              data-testid="xb-canvas-scaling"
              style={{
                transform: `scale(${canvasViewPort.scale})`,
              }}
              ref={scalingContainerRef}
            >
              <ErrorBoundary
                title="An unexpected error has occurred while rendering preview."
                variant="alert"
                onReset={isUndoable ? dispatchUndo : undefined}
                resetButtonText={isUndoable ? 'Undo last action' : undefined}
              >
                <Preview />
              </ErrorBoundary>
            </div>

            <PreviewOverlay />
          </div>
        </div>
      </div>
      <ViewportToolbar
        canvasPaneRef={canvasPaneRef}
        scalingContainerRef={scalingContainerRef}
      />
    </div>
  );
};

export default Canvas;
