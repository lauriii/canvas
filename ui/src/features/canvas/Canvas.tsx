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

const modifierKey = 'Space';

const Canvas = () => {
  const dispatch = useAppDispatch();
  const canvasRef = useRef<HTMLDivElement | null>(null);
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
  useHotkeys(modifierKey, () => setModifierKeyPressed(true), {
    keydown: true,
    keyup: false,
  });
  useHotkeys(modifierKey, () => setModifierKeyPressed(false), {
    keydown: false,
    keyup: true,
  });

  useEffect(() => {
    modifierKeyPressedRef.current = modifierKeyPressed;
  }, [modifierKeyPressed]);

  // Add an event listener for a message from the iFrame that a user used hot keys for zooming in/out
  // while inside the iFrame.
  useEffect(() => {
    function dispatchZoom(event: MessageEvent) {
      if (event.data === 'dispatchZoomIn') {
        dispatch(canvasViewPortZoomIn());
      }
      if (event.data === 'dispatchZoomOut') {
        dispatch(canvasViewPortZoomOut());
      }
      if (event.data === 'dispatchModifierKeyDown') {
        setModifierKeyPressed(true);
      }
      if (event.data === 'dispatchModifierKeyUp') {
        setModifierKeyPressed(false);
      }
    }
    window.addEventListener('message', dispatchZoom);
    return () => {
      window.removeEventListener('message', dispatchZoom);
    };
  });

  useEffect(() => {
    if (previewsContainerRef.current && canvasRef.current) {
      let previewContainerWidth = previewsContainerRef.current.offsetWidth;
      let previewContainerHeight = previewsContainerRef.current.offsetHeight;
      let canvasX = canvasRef.current.offsetWidth / 2;
      let canvasY = canvasRef.current.offsetHeight / 2;

      canvasX = canvasX - previewContainerWidth / 2;
      canvasY = canvasY - previewContainerHeight / 2;

      // 320/40 to approx. account for primaryPanel width and topBar height - could be more accurate
      dispatch(setCanvasViewPort({ x: 320 - canvasX, y: 40 - canvasY }));
    }
  }, [dispatch]);

  const handleMouseDown = (e: React.MouseEvent<HTMLDivElement>) => {
    if (modifierKeyPressedRef.current) {
      const { clientX, clientY } = e;
      setIsPanning(true);
      setStartPos({
        x: clientX - canvasViewPort.x,
        y: clientY - canvasViewPort.y,
      });
    }
  };

  useEffect(() => {}, []);

  const handleMouseMove = (e: React.MouseEvent<HTMLDivElement>) => {
    if (isPanning) {
      const { clientX, clientY } = e;
      const translationX = clientX - startPos.x;
      const translationY = clientY - startPos.y;

      if (animFrameIdRef.current) {
        cancelAnimationFrame(animFrameIdRef.current);
      }

      animFrameIdRef.current = requestAnimationFrame(() => {
        if (canvasRef.current) {
          canvasRef.current.style.transform = `translate(${translationX}px, ${translationY}px) scale(${canvasViewPort.scale})`;
        }
      });

      dispatch(
        setCanvasViewPort({
          x: clientX - startPos.x,
          y: clientY - startPos.y,
        }),
      );
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
      }
    },
    [dispatch],
  );

  useEffect(() => {
    if (animFrameIdRef.current) {
      cancelAnimationFrame(animFrameIdRef.current);
    }

    animFrameIdRef.current = requestAnimationFrame(() => {
      if (canvasRef.current) {
        canvasRef.current.style.transform = `translate(${canvasViewPort.x}px, ${canvasViewPort.y}px) scale(${canvasViewPort.scale})`;
      }
    });
  }, [canvasViewPort.x, canvasViewPort.y, canvasViewPort.scale]);

  useEffect(() => {
    window.addEventListener('mouseup', handleMouseUp);
    window.addEventListener('wheel', handleWheel, { passive: true });

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
      onMouseUp={handleMouseUp}
      onMouseLeave={handleMouseUp}
    >
      <div
        className={styles.canvas}
        ref={canvasRef}
        style={{
          transform: `translate(${canvasViewPort.x}px, ${canvasViewPort.y}px) scale(${canvasViewPort.scale})`,
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
