import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
} from 'react';
import clsx from 'clsx';
import { useHotkeys } from 'react-hotkeys-hook';
import { useParams } from 'react-router';
import { useDebouncedCallback } from 'use-debounce';
import { useGesture } from '@use-gesture/react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import Preview from '@/features/layout/preview/Preview';
import ViewportToolbar from '@/features/layout/preview/ViewportToolbar';
import PreviewOverlay from '@/features/layout/previewOverlay/PreviewOverlay';
import {
  editorViewPortZoomIn,
  editorViewPortZoomOut,
  scaleValues,
  selectDragging,
  selectEditorViewPort,
  selectFirstLoadComplete,
  selectPanning,
  setEditorFrameModeEditing,
  setEditorFrameModeInteractive,
  setEditorFrameViewPort,
  setIsPanning,
  setIsZooming,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useCopyPasteComponents from '@/hooks/useCopyPasteComponents';
import useLayoutWatcher from '@/hooks/useLayoutWatcher';
import useResizeObserver from '@/hooks/useResizeObserver';
import useSyncParamsToState from '@/hooks/useSyncParamsToState';
import { useUndoRedo } from '@/hooks/useUndoRedo';
import { getHalfwayScrollPosition } from '@/utils/function-utils';

import { deleteNode } from '../layout/layoutModelSlice';

import type React from 'react';

import styles from './EditorFrame.module.css';

// Zoom sensitivity settings - picked based on how they feel during testing
const WHEEL_ZOOM_SENSITIVITY = 0.001; // Using a mouse wheel (or trackpad two finger up/down while holding ctrl/cmd)
const PINCH_ZOOM_SENSITIVITY = 0.01; // Using a trackpad pinch gesture

// While dragging a component, holding the pointer near the top or bottom edge of
// the editor pane auto-scrolls the canvas vertically, so you can place a component
// above or below the fold on a long page. It is deliberately vertical-only and
// "reluctant": components stack vertically, so horizontal auto-scroll on a
// centered canvas is rarely wanted (and was the original #3534972 bug), and on a
// canvas you freely pan and zoom an eager auto-scroll feels like it runs away.
//
// Height, in pixels, of the strip at the top/bottom edge that triggers it.
const DRAG_AUTOSCROLL_EDGE_PX = 60;
// The pointer must dwell in the strip this long before scrolling starts, so
// sweeping past the edge does nothing — only parking there engages it.
const DRAG_AUTOSCROLL_DWELL_MS = 300;
// After the dwell, the speed eases in from zero to full over this long, so it
// starts gently instead of jumping.
const DRAG_AUTOSCROLL_RAMP_MS = 250;
// Maximum auto-scroll speed, in pixels per animation frame (~480px/s at 60fps),
// reached at the very edge; it tapers to zero at the strip's inner boundary.
const DRAG_AUTOSCROLL_MAX_SPEED_PX = 8;

const EditorFrame: React.FC = () => {
  const dispatch = useAppDispatch();
  useSyncParamsToState();
  useLayoutWatcher();
  const editorFrameRef = useRef<HTMLDivElement | null>(null);
  const editorPaneRef = useRef<HTMLDivElement | null>(null);
  const animFrameScrollRef = useRef<number | null>(null);
  const scalingContainerRef = useRef<HTMLDivElement | null>(null);
  // Use a ref for panningStart to ensure immediate access in event handlers
  const panningStartRef = useRef<{
    mouseX: number;
    mouseY: number;
    scrollLeft: number;
    scrollTop: number;
  } | null>(null);
  const editorViewPort = useAppSelector(selectEditorViewPort);
  const firstLoadComplete = useAppSelector(selectFirstLoadComplete);
  const [isVisible, setIsVisible] = useState(false);
  const [panningMode, setPanningMode] = useState(false);
  const isPanning = useAppSelector(selectPanning);
  const [zoomModifierKeyPressed, setZoomModifierKeyPressed] = useState(false);
  const zoomModifierKeyPressedRef = useRef(false);
  const [spaceKeyPressed, setSpaceKeyPressed] = useState(false);
  const spaceKeyPressedRef = useRef(false);
  const { componentId: selectedComponent } = useParams();
  const { unsetSelectedComponent } = useComponentSelection();
  const panningModeRef = useRef(panningMode);
  const { copySelectedComponent, pasteAfterSelectedComponent } =
    useCopyPasteComponents();
  const { isUndoable, dispatchUndo } = useUndoRedo();
  const { isDragging, previewDragging, listDragging } =
    useAppSelector(selectDragging);
  // The custom pane auto-scroll only applies to drags that target the canvas:
  // placing a component from the library (list) or moving one in the preview.
  // Layers-tree and code-panel drags scroll their own containers, not the canvas.
  const isCanvasDrag = previewDragging || listDragging;
  // Read the latest dragging state from event handlers without recreating them.
  const isDraggingRef = useRef(isDragging);

  useHotkeys(['NumpadAdd', 'Equal'], () => dispatch(editorViewPortZoomIn()));
  useHotkeys(['Minus', 'NumpadSubtract'], () =>
    dispatch(editorViewPortZoomOut()),
  );
  useHotkeys('ctrl', () => setZoomModifierKeyPressed(true), {
    keydown: true,
    keyup: false,
  });
  useHotkeys('ctrl', () => setZoomModifierKeyPressed(false), {
    keydown: false,
    keyup: true,
  });

  useHotkeys(
    'space',
    (event) => {
      // Canvas AI's input is in the shadowDom and thus is not recognized as a form
      // element or content editable which are normally ignored automatically by useHotKeys.
      // We need to manually check the target to avoid interfering with typing spaces in there.
      if ((event.target as HTMLElement)?.tagName === 'DEEP-CHAT') {
        return;
      }
      event.preventDefault();
      if (!event.repeat && !spaceKeyPressedRef.current) {
        setSpaceKeyPressed(true);
      }
    },
    {
      keydown: true,
      keyup: false,
      eventListenerOptions: { capture: true }, // ensure we capture the space key before other handlers (like selecting the current focused component)
    },
  );
  useHotkeys('space', () => setSpaceKeyPressed(false), {
    keydown: false,
    keyup: true,
    preventDefault: true,
  });

  // TODO This should have a better keyboard shortcut, but as the Interactive mode is still
  // in development/buggy, leaving it as something obscure for now.
  useHotkeys('v', () => dispatch(setEditorFrameModeInteractive()), {
    keydown: true,
    keyup: false,
  });
  useHotkeys('v', () => dispatch(setEditorFrameModeEditing()), {
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

  // Update the width/height of the editorFrame to accommodate the scaled viewport.
  // CSS transforms don't affect layout, so we manually sync the parent's dimensions.
  const updateEditorFrameSize = useCallback(() => {
    if (!scalingContainerRef.current || !editorFrameRef.current) {
      return;
    }

    const rect = scalingContainerRef.current.getBoundingClientRect();
    editorFrameRef.current.style.width = rect.width ? `${rect.width}px` : '';
    editorFrameRef.current.style.height = rect.height ? `${rect.height}px` : '';
  }, []);

  useResizeObserver(scalingContainerRef, updateEditorFrameSize);

  const debouncedScrollPosUpdate = useDebouncedCallback(() => {
    if (!isDragging) {
      dispatch(
        setEditorFrameViewPort({
          x: editorPaneRef.current?.scrollLeft,
          y: editorPaneRef.current?.scrollTop,
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
    panningModeRef.current = panningMode;
  }, [panningMode]);

  useEffect(() => {
    isDraggingRef.current = isDragging;
  }, [isDragging]);

  // Vertically auto-scroll the editor pane while a component is being dragged
  // onto/around the canvas, when the pointer is parked near the top or bottom
  // edge. This is intentionally not dnd-kit's built-in auto-scroll: that only
  // fires while the pointer is over a drop target (so it does nothing near the
  // bottom edge over empty space) and only after the drag has moved in the scroll
  // direction, which made scrolling to place a component above or below the fold
  // feel inconsistent (#3534972). See the DRAG_AUTOSCROLL_* constants above for
  // why it is vertical-only and reluctant.
  useEffect(() => {
    if (!isCanvasDrag) {
      return;
    }
    const pane = editorPaneRef.current;
    if (!pane) {
      return;
    }

    // Latest pointer position, in viewport coordinates. The pointer moves
    // continuously during a drag, so a listener keeps this current.
    let pointer: { x: number; y: number } | null = null;
    // The top/bottom edge each arm only once the pointer has been more than a
    // strip's height away from it, so picking a component up already near an edge
    // and holding still doesn't scroll.
    let armedTop = false;
    let armedBottom = false;
    // Timestamp the pointer entered the top/bottom strip; reset when it leaves.
    // The dwell before scrolling is measured from here.
    let dwellStart: number | null = null;
    let frame: number | null = null;

    const onPointerMove = (event: PointerEvent) => {
      pointer = { x: event.clientX, y: event.clientY };
    };

    const tick = (time: number) => {
      if (pointer) {
        const rect = pane.getBoundingClientRect();
        const edge = DRAG_AUTOSCROLL_EDGE_PX;
        if (pointer.y > rect.top + edge) armedTop = true;
        if (pointer.y < rect.bottom - edge) armedBottom = true;

        const insidePane =
          pointer.x >= rect.left &&
          pointer.x <= rect.right &&
          pointer.y >= rect.top &&
          pointer.y <= rect.bottom;

        // Direction toward the near edge (-1 up, +1 down) and how far into the
        // strip the pointer is, but only for an armed edge and over the pane.
        let direction = 0;
        let penetration = 0;
        if (insidePane && armedTop && pointer.y < rect.top + edge) {
          direction = -1;
          penetration = rect.top + edge - pointer.y;
        } else if (
          insidePane &&
          armedBottom &&
          pointer.y > rect.bottom - edge
        ) {
          direction = 1;
          penetration = pointer.y - (rect.bottom - edge);
        }

        if (direction !== 0) {
          if (dwellStart === null) {
            dwellStart = time;
          }
          const held = time - dwellStart;
          if (held >= DRAG_AUTOSCROLL_DWELL_MS) {
            // Ease speed in over the ramp window after the dwell, and taper it by
            // how deep into the strip the pointer is.
            const rampFactor = Math.min(
              1,
              (held - DRAG_AUTOSCROLL_DWELL_MS) / DRAG_AUTOSCROLL_RAMP_MS,
            );
            const depthFactor = Math.min(1, penetration / edge);
            const speed =
              DRAG_AUTOSCROLL_MAX_SPEED_PX * depthFactor * rampFactor;
            if (speed > 0) {
              pane.scrollBy(0, direction * speed);
            }
          }
        } else {
          dwellStart = null;
        }
      }
      frame = requestAnimationFrame(tick);
    };

    window.addEventListener('pointermove', onPointerMove, true);
    frame = requestAnimationFrame(tick);

    return () => {
      window.removeEventListener('pointermove', onPointerMove, true);
      if (frame !== null) {
        cancelAnimationFrame(frame);
      }
    };
  }, [isCanvasDrag]);

  useEffect(() => {
    zoomModifierKeyPressedRef.current = zoomModifierKeyPressed;
  }, [zoomModifierKeyPressed]);

  useEffect(() => {
    spaceKeyPressedRef.current = spaceKeyPressed;
  }, [spaceKeyPressed]);

  useEffect(() => {
    if (!firstLoadComplete) {
      return;
    }
    if (scalingContainerRef.current && editorPaneRef.current) {
      // hardcoded value of 68 to account for the height of the UI (top bar 48px) + 20px gap.
      const previewHeight =
        scalingContainerRef.current.getBoundingClientRect().height;

      // Calculate the center offset inside the editorFrame.
      const editorFrameHeight = editorPaneRef.current.scrollHeight;
      const centerOffset = (editorFrameHeight - previewHeight) / 2;

      const y = centerOffset - 50;
      const x = getHalfwayScrollPosition(editorPaneRef.current);

      // Scroll the preview to the middle top.
      dispatch(setEditorFrameViewPort({ x: x, y: y }));
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
      if (!event.currentTarget) {
        return;
      }
      // dnd-kit auto-scroll fires this handler while a component is being
      // dragged. Do not enter panning mode then: `.isPanning` sets
      // pointer-events: none on the preview overlay, which drops the drag's drop
      // target and stalls the auto-scroll before it can reveal the off-screen
      // slots the user is dragging toward (top or bottom edge). Panning proper
      // (space/middle-mouse drag) is unaffected — it never runs mid-drag.
      if (!isDraggingRef.current) {
        dispatch(setIsPanning(true));
        debouncedIsPanningUpdate();
      }
      debouncedScrollPosUpdate();
    },
    [debouncedIsPanningUpdate, debouncedScrollPosUpdate, dispatch],
  );

  const handleMouseDown = (e: React.MouseEvent<HTMLDivElement>) => {
    // Reset zoomModifierKeyPressed to false if left button is clicked along with ctrl key press
    if (e.ctrlKey && e.button === 0) {
      setZoomModifierKeyPressed(false);
      e.preventDefault();
      return;
    }

    // Space + mouse left button || middle mouse button
    if ((spaceKeyPressedRef.current && e.button === 0) || e.button === 1) {
      setPanningMode(true);
      dispatch(setIsPanning(true));
      if (editorPaneRef.current) {
        panningStartRef.current = {
          mouseX: e.clientX,
          mouseY: e.clientY,
          scrollLeft: editorPaneRef.current.scrollLeft,
          scrollTop: editorPaneRef.current.scrollTop,
        };
      }
      document.addEventListener('mousemove', handleDocumentMouseMove);
      document.addEventListener('mouseup', handleDocumentMouseUp);
      e.preventDefault();
    }
  };

  const handleDocumentMouseMove = useCallback(
    (e: MouseEvent) => {
      if (
        panningModeRef.current &&
        panningStartRef.current &&
        editorPaneRef.current
      ) {
        const deltaX = e.clientX - panningStartRef.current.mouseX;
        const deltaY = e.clientY - panningStartRef.current.mouseY;
        const newScrollLeft = panningStartRef.current.scrollLeft - deltaX;
        const newScrollTop = panningStartRef.current.scrollTop - deltaY;

        if (animFrameScrollRef.current) {
          cancelAnimationFrame(animFrameScrollRef.current);
        }

        animFrameScrollRef.current = requestAnimationFrame(() => {
          if (editorPaneRef.current) {
            editorPaneRef.current.scrollLeft = newScrollLeft;
            editorPaneRef.current.scrollTop = newScrollTop;
          }
          debouncedScrollPosUpdate();
        });
      }
    },
    [debouncedScrollPosUpdate],
  );

  const handleDocumentMouseUp = useCallback(() => {
    setPanningMode(false);
    panningStartRef.current = null;
    debouncedIsPanningUpdate();
    document.removeEventListener('mousemove', handleDocumentMouseMove);
    document.removeEventListener('mouseup', handleDocumentMouseUp);
  }, [debouncedIsPanningUpdate, handleDocumentMouseMove]);

  const minScale = scaleValues[0].scale;
  const maxScale = scaleValues[scaleValues.length - 1].scale;

  const clampScale = useCallback(
    (scale: number) => Math.max(minScale, Math.min(maxScale, scale)),
    [minScale, maxScale],
  );

  // Pinch gesture tracking refs
  const isPinchingRef = useRef(false); // True during any pinch gesture (used for zoom sensitivity in onWheel)
  const isSafariGestureActiveRef = useRef(false); // True during Safari GestureEvents (blocks onWheel)
  const pinchStartScaleRef = useRef(1); // Scale at gesture start (Safari only)
  const pinchMovementBaselineRef = useRef(0); // Initial movement[0] value to normalize Safari gesture deltas

  /**
   * Configure gesture handling for smooth free-form panning and precise zooming.
   * - Track pad scroll: Free X/Y panning
   * - Cmd/Ctrl + wheel: Continuous zoom based on scroll delta
   * - Pinch: Multi-touch zoom gesture
   *
   * Browser behavior note:
   * - Chrome/Firefox: trackpad pinch fires wheel events with ctrlKey=true and large dy values.
   *   onWheel handles zoom for these browsers. onPinch fires too but is ignored via the
   *   event.type guard below to avoid double-zooming.
   * - Safari: pinch fires native GestureEvents (gesturestart/gesturechange/gestureend) which
   *   @use-gesture routes through onPinch. Safari does NOT fire wheel events during the pinch,
   *   so onWheel never runs. The zoom logic in onPinch handles Safari exclusively, guarded by
   *   checking that the event type starts with "gesture".
   */
  useGesture(
    {
      onWheel: ({ event, delta: [dx, dy], ctrlKey, metaKey }) => {
        event.preventDefault();

        if (ctrlKey || metaKey) {
          // Skip if Safari is handling pinch via GestureEvents
          if (isSafariGestureActiveRef.current) return;

          dispatch(setIsZooming(true));

          // Use higher sensitivity for trackpad pinch (isPinchingRef) vs mouse wheel.
          // Scale proportionally to current scale for a logarithmic zoom feel.
          const sensitivity = isPinchingRef.current
            ? PINCH_ZOOM_SENSITIVITY
            : WHEEL_ZOOM_SENSITIVITY;
          const scaleDelta = -dy * sensitivity * editorViewPort.scale;
          const newScale = clampScale(editorViewPort.scale + scaleDelta);

          dispatch(setEditorFrameViewPort({ scale: newScale }));
          debouncedIsZoomingUpdate();
        } else {
          // Free-form panning mode
          if (editorPaneRef.current) {
            dispatch(setIsPanning(true));
            editorPaneRef.current.scrollLeft += dx;
            editorPaneRef.current.scrollTop += dy;
            debouncedScrollPosUpdate();
            debouncedIsPanningUpdate();
          }
        }
      },
      onPinch: ({ first, last, movement, event }) => {
        const eventType = (event as Event)?.type ?? '';
        const isSafariGesture = eventType.startsWith('gesture');

        // Track pinch state for all browsers (used by onWheel for sensitivity selection)
        if (first) {
          isPinchingRef.current = true;
        }
        if (last) {
          isPinchingRef.current = false;
        }

        // Chrome/Firefox handle pinch via onWheel; only Safari uses GestureEvents here
        if (!isSafariGesture) {
          return;
        }

        if (first) {
          isSafariGestureActiveRef.current = true;
          pinchStartScaleRef.current = editorViewPort.scale;
          // Safari's gesturestart fires with movement[0] ~= 1.0, not 0. Capture as
          // a baseline so all subsequent deltas are relative to the true gesture start.
          pinchMovementBaselineRef.current = movement[0];
          dispatch(setIsZooming(true));
        }

        const relativeDelta = movement[0] - pinchMovementBaselineRef.current;
        const newScale = clampScale(
          pinchStartScaleRef.current * (1 + relativeDelta),
        );

        // Write scale directly to DOM for smooth 60fps updates, bypassing Redux
        // until gesture ends. Also update frame size since ResizeObserver doesn't
        // fire for transform changes.
        if (scalingContainerRef.current) {
          scalingContainerRef.current.style.transform = `scale(${newScale})`;
          updateEditorFrameSize();
        }

        if (last) {
          // Sync final scale to Redux
          if (scalingContainerRef.current) {
            scalingContainerRef.current.style.transform = `scale(${newScale})`;
          }
          dispatch(setEditorFrameViewPort({ scale: newScale }));

          // Clear Safari gesture flag after dispatching
          isSafariGestureActiveRef.current = false;

          debouncedIsZoomingUpdate();
        }
      },
    },
    {
      target: editorPaneRef,
      eventOptions: { passive: false },
    },
  );

  // Sync scroll position from Redux to DOM
  useEffect(() => {
    if (animFrameScrollRef.current) {
      cancelAnimationFrame(animFrameScrollRef.current);
    }

    animFrameScrollRef.current = requestAnimationFrame(() => {
      if (editorPaneRef.current) {
        editorPaneRef.current.scrollLeft = editorViewPort.x;
        editorPaneRef.current.scrollTop = editorViewPort.y;
      }
    });
  }, [editorViewPort.x, editorViewPort.y]);

  // Sync scale from Redux to DOM. Skip during Safari pinch gestures since
  // onPinch owns the DOM transform during the gesture for smooth 60fps updates.
  useLayoutEffect(() => {
    if (isSafariGestureActiveRef.current) return;

    if (scalingContainerRef.current) {
      scalingContainerRef.current.style.transform = `scale(${editorViewPort.scale})`;
    }
  }, [editorViewPort.scale]);

  useEffect(() => {
    return () => {
      // Cleanup on unmount in case any listeners are still attached.
      document.removeEventListener('mousemove', handleDocumentMouseMove);
      document.removeEventListener('mouseup', handleDocumentMouseUp);
    };
  }, [handleDocumentMouseMove, handleDocumentMouseUp]);

  // Update the editorFrame size when the scale changes or on initial render.
  useLayoutEffect(() => {
    updateEditorFrameSize();
  }, [editorViewPort.scale, updateEditorFrameSize]);

  return (
    <div className={styles.editorFrameContainer}>
      <div
        className={clsx(styles.editorPane, {
          [styles.spaceKeyPressed]: spaceKeyPressed,
          [styles.zoomModifierKeyPressed]: zoomModifierKeyPressed,
          [styles.isPanning]: isPanning,
          [styles.panningMode]: panningMode,
        })}
        onMouseDown={handleMouseDown}
        onScroll={handlePaneScroll}
        ref={editorPaneRef}
        // Marks this element as the editor pane so the app can exclude it from
        // dnd-kit's built-in auto-scroll (it auto-scrolls itself, see above).
        data-canvas-editor-pane="true"
      >
        <div
          className={clsx(styles.editorFrame, {
            [styles.visible]: isVisible,
          })}
          // @ts-ignore
          style={{ '--editor-frame-scale': editorViewPort.scale }}
          ref={editorFrameRef}
          data-testid="canvas-editor-frame"
        >
          <div style={{ position: 'relative' }} id="positionAnchor">
            <div
              className={clsx(
                'canvasEditorFrameScalingContainer',
                styles.canvasEditorFrameScalingContainer,
              )}
              data-testid="canvas-editor-frame-scaling"
              ref={scalingContainerRef}
            >
              <ErrorBoundary
                title={Drupal.t(
                  'An unexpected error has occurred while rendering preview.',
                )}
                variant="alert"
                onReset={isUndoable ? dispatchUndo : undefined}
                resetButtonText={
                  isUndoable ? Drupal.t('Undo last action') : undefined
                }
              >
                <Preview />
              </ErrorBoundary>
            </div>

            <PreviewOverlay />
          </div>
        </div>
      </div>
      <ViewportToolbar
        editorPaneRef={editorPaneRef}
        scalingContainerRef={scalingContainerRef}
      />
    </div>
  );
};

export default EditorFrame;
