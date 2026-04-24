import { useCallback, useEffect, useRef, useState } from 'react';
import clsx from 'clsx';

import { useAppSelector } from '@/app/hooks';
import ContextualPanel from '@/components/panel/ContextualPanel';
import Editor from '@/features/editor/Editor';
import {
  loadRightSidebarWidthPx,
  saveRightSidebarWidthPx,
  SIDEBAR_MAX_PX,
  SIDEBAR_MIN_PX,
} from '@/features/editor/editorLayoutStorage';
import {
  EditorFrameContext,
  EditorFrameMode,
  selectEditorFrameContext,
  selectEditorFrameMode,
  selectIsMultiSelect,
  selectSelectedComponentUuid,
} from '@/features/ui/uiSlice';

import type React from 'react';

import styles from '@/features/editor/EditorLayout.module.css';

interface EditorLayoutProps {
  context: EditorFrameContext;
}

const EditorLayout: React.FC<EditorLayoutProps> = ({ context }) => {
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const editorFrameMode = useAppSelector(selectEditorFrameMode);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);

  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  const isPanelHidden =
    editorFrameMode === EditorFrameMode.INTERACTIVE ||
    (isTemplateContext && !isMultiSelect && !selectedComponent);

  const [rightWidthPx, setRightWidthPx] = useState(loadRightSidebarWidthPx);
  const rightWidthPxRef = useRef(rightWidthPx);
  const layoutRef = useRef<HTMLDivElement>(null);
  const rightColumnRef = useRef<HTMLDivElement>(null);
  const resizeHandleRef = useRef<HTMLDivElement | null>(null);
  const pointerIdRef = useRef<number>(-1);
  const isDraggingRef = useRef(false);

  rightWidthPxRef.current = rightWidthPx;

  const getResizeCursor = useCallback((widthPx: number) => {
    if (widthPx <= SIDEBAR_MIN_PX) return 'w-resize';
    if (widthPx >= SIDEBAR_MAX_PX) return 'e-resize';
    return 'col-resize';
  }, []);

  const handlePointerMove = useCallback(
    (e: PointerEvent) => {
      if (
        !isDraggingRef.current ||
        !layoutRef.current ||
        !rightColumnRef.current
      )
        return;
      const rect = layoutRef.current.getBoundingClientRect();
      const newRight = rect.right - e.clientX;
      const clamped = Math.max(
        SIDEBAR_MIN_PX,
        Math.min(SIDEBAR_MAX_PX, newRight),
      );
      rightWidthPxRef.current = clamped;
      rightColumnRef.current.style.width = `${clamped}px`;
      setRightWidthPx(clamped);
      document.body.style.cursor = getResizeCursor(clamped);
    },
    [getResizeCursor],
  );

  const handlePointerUp = useCallback(() => {
    if (!isDraggingRef.current) return;
    isDraggingRef.current = false;
    if (
      resizeHandleRef.current &&
      pointerIdRef.current >= 0 &&
      resizeHandleRef.current.hasPointerCapture(pointerIdRef.current)
    ) {
      resizeHandleRef.current.releasePointerCapture(pointerIdRef.current);
    }
    const final = rightWidthPxRef.current;
    setRightWidthPx(final);
    saveRightSidebarWidthPx(final);
    if (rightColumnRef.current) {
      rightColumnRef.current.style.transition = '';
    }
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    document.body.style.touchAction = '';
    document.removeEventListener('pointermove', handlePointerMove);
    document.removeEventListener('pointerup', handlePointerUp);
    document.removeEventListener('pointercancel', handlePointerUp);
  }, [handlePointerMove]);

  const handleResizePointerDown = useCallback(
    (e: React.PointerEvent) => {
      e.preventDefault();
      if (isPanelHidden) return;
      const handle = e.currentTarget as HTMLDivElement;
      isDraggingRef.current = true;
      resizeHandleRef.current = handle;
      pointerIdRef.current = e.pointerId;
      if (rightColumnRef.current) {
        rightColumnRef.current.style.transition = 'none';
      }
      handle.setPointerCapture(e.pointerId);
      document.body.style.cursor = getResizeCursor(rightWidthPxRef.current);
      document.body.style.userSelect = 'none';
      document.body.style.touchAction = 'none';
      document.addEventListener('pointermove', handlePointerMove);
      document.addEventListener('pointerup', handlePointerUp);
      document.addEventListener('pointercancel', handlePointerUp);
    },
    [isPanelHidden, getResizeCursor, handlePointerMove, handlePointerUp],
  );

  useEffect(() => {
    return () => {
      document.removeEventListener('pointermove', handlePointerMove);
      document.removeEventListener('pointerup', handlePointerUp);
      document.removeEventListener('pointercancel', handlePointerUp);
      if (isDraggingRef.current) {
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        document.body.style.touchAction = '';
      }
    };
  }, [handlePointerMove, handlePointerUp]);

  const effectiveRightWidth = isPanelHidden ? 0 : rightWidthPx;
  const showHandle = !isPanelHidden;

  const resizeCursor = getResizeCursor(rightWidthPx);

  return (
    <div ref={layoutRef} className={styles.layout}>
      <div className={styles.center}>
        <Editor context={context} />
      </div>
      {showHandle && (
        <div
          ref={resizeHandleRef}
          className={styles.resizeHandle}
          style={{ cursor: resizeCursor }}
          onPointerDown={handleResizePointerDown}
          role="separator"
          aria-orientation="vertical"
          aria-valuenow={rightWidthPx}
          aria-valuemin={SIDEBAR_MIN_PX}
          aria-valuemax={SIDEBAR_MAX_PX}
        />
      )}
      <div
        ref={rightColumnRef}
        className={clsx(styles.rightColumn, {
          [styles.rightColumnHidden]: isPanelHidden,
        })}
        style={{ width: effectiveRightWidth }}
      >
        <ContextualPanel />
      </div>
    </div>
  );
};

export default EditorLayout;
