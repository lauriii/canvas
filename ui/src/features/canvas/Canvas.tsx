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
  setPanningIFrame,
  setPanningParent,
  selectIsContextMenuOpen,
  selectSelectedComponent,
} from '@/features/ui/uiSlice';
import { deleteNode } from '../layout/layoutModelSlice';

const Canvas = () => {
  const dispatch = useAppDispatch();
  const canvasRef = useRef<HTMLDivElement | null>(null);
  const canvasPaneRef = useRef<HTMLDivElement | null>(null);
  const animFrameIdRef = useRef<number | null>(null);
  const previewsContainerRef = useRef<HTMLDivElement | null>(null);
  const [startPos, setStartPos] = useState({ x: 0, y: 0 });
  const canvasViewPort = useAppSelector(selectCanvasViewPort);
  const { isPanning, isPanningIFrame, isPanningParent } =
    useAppSelector(selectPanning);
  const contextMenuOpen = useAppSelector(selectIsContextMenuOpen);
  const [modifierKeyPressed, setModifierKeyPressed] = useState(false);
  const modifierKeyPressedRef = useRef(false);
  const selectedComponent = useAppSelector(selectSelectedComponent);
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
  useHotkeys(['Backspace', 'Delete'], () => {
    if (selectedComponent) {
      dispatch(deleteNode(selectedComponent));
    }
  });
  const isPanningParentRef = useRef(isPanningParent);
  const isPanningIFrameRef = useRef(isPanningIFrame);

  useEffect(() => {
    isPanningParentRef.current = isPanningParent;
  }, [isPanningParent]);

  useEffect(() => {
    isPanningIFrameRef.current = isPanningIFrame;
  }, [isPanningIFrame]);

  useEffect(() => {
    modifierKeyPressedRef.current = modifierKeyPressed;
  }, [modifierKeyPressed]);

  // Add an event listener for a message from the iFrame that a user used hot keys for zooming in/out
  // while inside the iFrame.
  useEffect(() => {
    function handleIframeEvent(event: MessageEvent) {
      const type = event.data.type ? event.data.type : event.data;
      switch (type) {
        case 'dispatchZoomIn':
          dispatch(canvasViewPortZoomIn());
          break;
        case 'dispatchZoomOut':
          dispatch(canvasViewPortZoomOut());
          break;
        case 'dispatchZoomDelta':
          dispatch(canvasViewPortZoomDelta(event.data.delta));
          break;
        case 'dispatchModifierKeyDown':
          setModifierKeyPressed(true);
          break;
        case 'dispatchModifierKeyUp':
          setModifierKeyPressed(false);
          break;
        case 'dispatchMiddleMouseDown':
          dispatch(setPanningIFrame(true));
          setStartPos(event.data.coordinates);
          break;
        case 'dispatchMiddleMouseUp':
          dispatch(setPanningIFrame(false));
          dispatch(setPanningParent(false));
          break;
        case 'dispatchMouseMove':
          isPanningIFrameRef.current &&
            handlePreviewMouseMove(event.data.coordinates);
          break;
        case 'dispatchDeleteKey':
          selectedComponent && dispatch(deleteNode(selectedComponent));
      }
    }
    window.addEventListener('message', handleIframeEvent);
    return () => {
      window.removeEventListener('message', handleIframeEvent);
    };
  });

  useEffect(() => {
    if (previewsContainerRef.current && canvasRef.current) {
      // let previewContainerWidth = previewsContainerRef.current.offsetWidth;
      // console.log(previewContainerWidth);
      // let previewContainerHeight = previewsContainerRef.current.offsetHeight;
      // let canvasX = canvasRef.current.offsetWidth / 2;
      // let canvasY = canvasRef.current.offsetHeight / 2;
      //
      // canvasX = canvasX + previewContainerWidth;
      // canvasY = canvasY - previewContainerHeight / 2;
      // @todo - this calc should be dynamic to correctly center the preview container in the canvas pane on page load. https://www.drupal.org/project/experience_builder/issues/3469894
      dispatch(setCanvasViewPort({ x: 670, y: 880 }));
    }
  }, [dispatch]);

  const handlePaneScroll = useCallback(
    (event: React.UIEvent<HTMLDivElement>) => {
      if (event.currentTarget) {
        dispatch(
          setCanvasViewPort({
            x: event.currentTarget.scrollLeft,
            y: event.currentTarget.scrollTop,
          }),
        );
      }
    },
    [dispatch],
  );

  const handleMouseDown = (e: React.MouseEvent<HTMLDivElement>) => {
    if (e.button === 1) {
      const { clientX, clientY } = e;
      dispatch(setPanningParent(true));
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
    if (isPanningParentRef.current) {
      const { clientX, clientY } = e;
      const translationX = startPos.x - clientX;
      const translationY = startPos.y - clientY;

      if (animFrameIdRef.current) {
        cancelAnimationFrame(animFrameIdRef.current);
      }

      animFrameIdRef.current = requestAnimationFrame(() => {
        if (canvasRef.current) {
          canvasRef.current.style.transform = `scale(${canvasViewPort.scale})`;
        }
        if (canvasPaneRef.current) {
          canvasPaneRef.current.scrollLeft = translationX;
          canvasPaneRef.current.scrollTop = translationY;
          dispatch(
            setCanvasViewPort({
              x: translationX,
              y: translationY,
            }),
          );
        }
      });
    }
  };

  const handlePreviewMouseMove = ({ x, y }: { x: number; y: number }) => {
    if (isPanningIFrameRef.current) {
      const translationX = startPos.x - x;
      const translationY = startPos.y - y;

      if (animFrameIdRef.current) {
        cancelAnimationFrame(animFrameIdRef.current);
      }

      animFrameIdRef.current = requestAnimationFrame(() => {
        if (canvasPaneRef.current) {
          canvasPaneRef.current.scrollLeft += translationX;
          canvasPaneRef.current.scrollTop += translationY;
        }
      });
    }
  };

  const handleMouseUp = useCallback(() => {
    dispatch(setPanningParent(false));
    dispatch(setPanningIFrame(false));
  }, [dispatch]);

  const handleWheel = useCallback(
    (e: WheelEvent) => {
      if (!contextMenuOpen) {
        if (e.ctrlKey) {
          e.preventDefault();
          dispatch(canvasViewPortZoomDelta(e.deltaY));
        }
      }
    },
    [dispatch, contextMenuOpen],
  );

  useEffect(() => {
    if (animFrameIdRef.current) {
      cancelAnimationFrame(animFrameIdRef.current);
    }

    animFrameIdRef.current = requestAnimationFrame(() => {
      if (canvasRef.current) {
        canvasRef.current.style.transform = `scale(${canvasViewPort.scale})`;
      }
      if (canvasPaneRef.current) {
        canvasPaneRef.current.scrollLeft = canvasViewPort.x;
        canvasPaneRef.current.scrollTop = canvasViewPort.y;
      }
    });
  }, [canvasViewPort.x, canvasViewPort.y, canvasViewPort.scale]);

  useEffect(() => {
    window.addEventListener('mouseup', handleMouseUp);
    window.addEventListener('wheel', handleWheel, { passive: false });

    return () => {
      window.removeEventListener('mouseup', handleMouseUp);
      window.removeEventListener('wheel', handleWheel);
    };
  }, [handleWheel, handleMouseUp]);

  return (
    <div
      className={clsx(
        styles.canvasPane,
        {
          [styles.modifierKeyPressed]: modifierKeyPressed,
          [styles.isPanning]: isPanning,
        },
        {
          [styles.hoveredComponent]: contextMenuOpen,
        },
      )}
      onMouseDown={handleMouseDown}
      onMouseMove={handleCanvasMouseMove}
      onScroll={handlePaneScroll}
      onMouseUp={handleMouseUp}
      onMouseLeave={handleMouseUp}
      ref={canvasPaneRef}
    >
      <div
        className={styles.canvas}
        ref={canvasRef}
        data-testid="canvasElement"
        style={{
          transform: `scale(${canvasViewPort.scale})`,
        }}
      >
        <div className={styles.previewsContainer} ref={previewsContainerRef}>
          <ErrorBoundary
            title="An unexpected error has occurred while rendering preview."
            variant="alert"
          >
            <Preview />
          </ErrorBoundary>
        </div>
      </div>
    </div>
  );
};

export default Canvas;
