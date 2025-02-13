import type React from 'react';
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
  canvasViewPortZoomDelta,
  setCanvasViewPort,
  selectPanning,
  setIsPanning,
  selectFirstLoadComplete,
  setCanvasModeEditing,
  setCanvasModeInteractive,
} from '@/features/ui/uiSlice';
import PreviewOverlay from '@/features/layout/previewOverlay/PreviewOverlay';
import { deleteNode } from '../layout/layoutModelSlice';
import useCopyPasteComponents from '@/hooks/useCopyPasteComponents';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import useXbParams from '@/hooks/useXbParams';

const Canvas = () => {
  const dispatch = useAppDispatch();
  const canvasRef = useRef<HTMLDivElement | null>(null);
  const canvasPaneRef = useRef<HTMLDivElement | null>(null);
  const animFrameIdRef = useRef<number | null>(null);
  const previewsContainerRef = useRef<HTMLDivElement | null>(null);
  const [startPos, setStartPos] = useState({ x: 0, y: 0 });
  const canvasViewPort = useAppSelector(selectCanvasViewPort);
  const firstLoadComplete = useAppSelector(selectFirstLoadComplete);
  const [isVisible, setIsVisible] = useState(false);
  const [middleMouseDown, setMiddleMouseDown] = useState(false);
  const { isPanning } = useAppSelector(selectPanning);
  const [modifierKeyPressed, setModifierKeyPressed] = useState(false);
  const modifierKeyPressedRef = useRef(false);
  const { componentId: selectedComponent } = useXbParams();
  const { unsetSelectedComponent } = useNavigationUtils();
  const scrollTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const middleMouseDownRef = useRef(middleMouseDown);
  const { copySelectedComponent, pasteAfterSelectedComponent } =
    useCopyPasteComponents();

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

  useEffect(() => {
    middleMouseDownRef.current = middleMouseDown;
  }, [middleMouseDown]);

  useEffect(() => {
    modifierKeyPressedRef.current = modifierKeyPressed;
  }, [modifierKeyPressed]);

  useEffect(() => {
    if (!firstLoadComplete) {
      dispatch(setCanvasViewPort({ x: 0, y: 0 }));
      return;
    }
    if (previewsContainerRef.current && canvasRef.current) {
      // @todo Temporary/hardcoded values of 400/160 to account for the width/height
      // of the UI (top bar/layers panel) - replace with calculated values.
      const x = previewsContainerRef.current.getBoundingClientRect().left - 400;
      const y = previewsContainerRef.current.getBoundingClientRect().top - 160;

      // Dispatch the action with the calculated adjusted position
      dispatch(setCanvasViewPort({ x: x, y: y }));
      setIsVisible(true);
    }
  }, [dispatch, firstLoadComplete]);

  const handlePaneScroll = useCallback(
    (event: React.UIEvent<HTMLDivElement>) => {
      if (event.currentTarget) {
        dispatch(setIsPanning(true));
        // debounce the setIsPanning(false) so that elements hidden when panning don't flicker.
        if (scrollTimeoutRef.current) {
          clearTimeout(scrollTimeoutRef.current);
        }
        scrollTimeoutRef.current = setTimeout(() => {
          dispatch(setIsPanning(false));
        }, 140);
      }
    },
    [dispatch],
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

      if (animFrameIdRef.current) {
        cancelAnimationFrame(animFrameIdRef.current);
      }

      animFrameIdRef.current = requestAnimationFrame(() => {
        if (previewsContainerRef.current) {
          previewsContainerRef.current.style.transform = `scale(${canvasViewPort.scale})`;
        }
        if (canvasPaneRef.current) {
          canvasPaneRef.current.scrollLeft = translationX;
          canvasPaneRef.current.scrollTop = translationY;
        }
      });
    }
  };

  const handleMouseUp = useCallback(() => {
    setMiddleMouseDown(false);
    dispatch(setIsPanning(false));
  }, [dispatch]);

  const handleWheel = useCallback(
    (e: WheelEvent) => {
      if (e.ctrlKey) {
        e.preventDefault();
        dispatch(canvasViewPortZoomDelta(e.deltaY));
        dispatch(setIsPanning(true));

        // debounce the setIsPanning(false) so that elements hidden when zooming don't flicker.
        if (scrollTimeoutRef.current) {
          clearTimeout(scrollTimeoutRef.current);
        }
        scrollTimeoutRef.current = setTimeout(() => {
          dispatch(setIsPanning(false));
        }, 500);
      }
    },
    [dispatch],
  );

  useEffect(() => {
    if (animFrameIdRef.current) {
      cancelAnimationFrame(animFrameIdRef.current);
    }

    animFrameIdRef.current = requestAnimationFrame(() => {
      if (canvasPaneRef.current) {
        canvasPaneRef.current.scrollLeft = canvasViewPort.x;
        canvasPaneRef.current.scrollTop = canvasViewPort.y;
      }
    });
  }, [canvasViewPort.x, canvasViewPort.y]);

  useEffect(() => {
    if (animFrameIdRef.current) {
      cancelAnimationFrame(animFrameIdRef.current);
    }

    animFrameIdRef.current = requestAnimationFrame(() => {
      if (previewsContainerRef.current) {
        previewsContainerRef.current.style.transform = `scale(${canvasViewPort.scale})`;
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

  return (
    <>
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
          ref={canvasRef}
          data-testid="xb-canvas"
        >
          <div style={{ position: 'relative' }} id="positionAnchor">
            <div
              className={clsx('previewsContainer', styles.previewsContainer)}
              data-testid="xb-canvas-scaling"
              style={{
                transform: `scale(${canvasViewPort.scale})`,
              }}
              ref={previewsContainerRef}
            >
              <ErrorBoundary
                title="An unexpected error has occurred while rendering preview."
                variant="alert"
              >
                <Preview />
              </ErrorBoundary>
            </div>

            <PreviewOverlay
              canvasPaneRef={canvasPaneRef}
              previewsContainerRef={previewsContainerRef}
            />
          </div>
        </div>
      </div>
    </>
  );
};

export default Canvas;
