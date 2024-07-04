import type React from 'react';
import { useEffect, useRef, useState, useCallback } from 'react';
import styles from './Canvas.module.css';
import clsx from 'clsx';
import Preview from '@/features/layout/preview/Preview';
import { useHotkeys } from 'react-hotkeys-hook';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPort,
  canvasViewPortZoomIn,
  canvasViewPortZoomOut,
  setCanvasViewPort,
} from '@/features/ui/uiSlice';

const Canvas = () => {
  const dispatch = useAppDispatch();
  const canvasRef = useRef<HTMLDivElement | null>(null);
  const canvasPaneRef = useRef<HTMLDivElement | null>(null);
  const animFrameIdRef = useRef<number | null>(null);
  const previewsContainerRef = useRef<HTMLDivElement | null>(null);
  const [isPanning, setIsPanning] = useState(false);
  const [startPos, setStartPos] = useState({ x: 0, y: 0 });
  const canvasViewPort = useAppSelector(selectCanvasViewPort);
  const [modifierKeyPressed, setModifierKeyPressed] = useState(false);
  const modifierKeyPressedRef = useRef(false);
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
        case 'dispatchModifierKeyDown':
          setModifierKeyPressed(true);
          break;
        case 'dispatchModifierKeyUp':
          setModifierKeyPressed(false);
          break;
        case 'dispatchMiddleMouseDown':
          setIsPanning(true);
          // @todo the coordinates of where the iframe is clicked should be added probably to the top left position of the iframe on the canvas.
          setStartPos(event.data.coordinates);
          break;
        case 'dispatchMiddleMouseUp':
          setIsPanning(false);
          break;
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
      // @todo - this calc should be dynamic to correctly center the preview container in the canvas pane.
      dispatch(setCanvasViewPort({ x: 3740, y: 4500 }));
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
      setIsPanning(true);
      if (canvasPaneRef.current) {
        setStartPos({
          x: clientX + canvasPaneRef.current.scrollLeft,
          y: clientY + canvasPaneRef.current.scrollTop,
        });
      }
    }
  };

  const handleMouseMove = (e: React.MouseEvent<HTMLDivElement>) => {
    console.log('isPanning', isPanning);
    if (isPanning) {
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

  const handleMouseUp = useCallback(() => {
    setIsPanning(false);
  }, []);

  const handleWheel = useCallback(
    (e: WheelEvent) => {
      if (modifierKeyPressedRef.current) {
        // Determine zoom direction
        e.deltaY > 0
          ? dispatch(canvasViewPortZoomOut())
          : dispatch(canvasViewPortZoomIn());
      } else {
        e.preventDefault();
        if (canvasPaneRef.current) {
          canvasPaneRef.current.scrollTop += e.deltaY;
          canvasPaneRef.current.scrollLeft += e.deltaX;
        }
      }
    },
    [dispatch],
  );

  useEffect(() => {
    if (previewsContainerRef.current) {
      if (isPanning) {
        previewsContainerRef.current.style.pointerEvents = 'none';
      } else {
        previewsContainerRef.current.style.pointerEvents = 'all';
      }
    }
  }, [isPanning]);

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
      className={clsx(styles.canvasPane, {
        [styles.modifierKeyPressed]: modifierKeyPressed,
        [styles.isPanning]: isPanning,
      })}
      onMouseDown={handleMouseDown}
      onMouseMove={handleMouseMove}
      onScroll={handlePaneScroll}
      onMouseUp={handleMouseUp}
      onMouseLeave={handleMouseUp}
      ref={canvasPaneRef}
    >
      <div
        className={styles.canvas}
        ref={canvasRef}
        style={{
          transform: `scale(${canvasViewPort.scale})`,
        }}
      >
        <div className={styles.previewsContainer} ref={previewsContainerRef}>
          <Preview />
        </div>
      </div>
    </div>
  );
};

export default Canvas;
