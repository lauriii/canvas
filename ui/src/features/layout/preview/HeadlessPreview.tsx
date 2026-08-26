import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { usePreviewGeometryUpdater } from '@/features/layout/preview/PreviewGeometryContext';
import PreviewProgress from '@/features/layout/preview/PreviewProgress';
import { useHeadlessDraftSession } from '@/features/layout/preview/useHeadlessDraftSession';
import ViewportOverlay from '@/features/layout/previewOverlay/ViewportOverlay';
import {
  EditorFrameMode,
  selectEditorFrameMode,
  selectViewportMinHeight,
  selectViewportWidth,
  setFirstLoadComplete,
  unsetUpdatingComponent,
} from '@/features/ui/uiSlice';

import type { HeadlessSettings } from '@drupal-canvas/types';
import type { AutoSavesHashRecord } from '@/types/AutoSaves';

import styles from './Preview.module.css';

interface HeadlessPreviewProps {
  settings: HeadlessSettings;
  autoSavesHash: AutoSavesHashRecord;
}

interface PreviewFrameDescriptor {
  frameKey: string;
  entityType: string;
  entityId: string;
  viewMode?: string;
  autoSavesHash: AutoSavesHashRecord;
}

interface PreviewFrameState {
  active: PreviewFrameDescriptor | null;
  pending: PreviewFrameDescriptor | null;
}

interface HeadlessPreviewFrameProps extends PreviewFrameDescriptor {
  settings: HeadlessSettings;
  viewportWidth: number;
  viewportMinHeight: number;
  active: boolean;
  onReady: (key: string) => void;
}

const HeadlessPreviewFrame: React.FC<HeadlessPreviewFrameProps> = ({
  settings,
  autoSavesHash,
  entityType,
  entityId,
  viewMode,
  viewportWidth,
  viewportMinHeight,
  active,
  onReady,
  frameKey,
}) => {
  const dispatch = useAppDispatch();
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const previewContainerRef = useRef<HTMLDivElement>(null);
  const { updateGeometry, clearGeometry } = usePreviewGeometryUpdater();
  const editorFrameMode = useAppSelector(selectEditorFrameMode);

  // For non-full view modes (e.g. teaser, card), use no min height so the
  // frame is sized to its content. A selected device-viewport preset only
  // floors the "full" view mode, mirroring the same-origin Viewport.
  const effectiveMinHeight =
    viewMode && viewMode !== 'full' ? 0 : viewportMinHeight;
  const { statusText, contentHeight, contentHeightReady, geometry } =
    useHeadlessDraftSession(
      iframeRef,
      settings,
      entityType,
      entityId,
      autoSavesHash,
      effectiveMinHeight,
      { viewMode },
    );

  // Floors at effectiveMinHeight (the selected device-viewport preset for the
  // "full" view mode, or 0 for content-sized view modes) so a shorter piece of
  // content never shrinks the frame below the simulated device height — the
  // same floor useSyncIframeHeightToContent keeps for the same-origin preview.
  const effectiveHeight = Math.max(contentHeight ?? 0, effectiveMinHeight);
  const iframeHeight = contentHeightReady
    ? effectiveHeight
    : effectiveMinHeight;

  useEffect(() => {
    if (contentHeightReady) {
      onReady(frameKey);
    }
  }, [contentHeightReady, frameKey, onReady]);

  useEffect(() => {
    if (active) {
      updateGeometry(geometry);
      // Standard preview clears this state when its iframe finishes updating.
      // A new app-side snapshot is the equivalent completion signal here.
      dispatch(unsetUpdatingComponent());
    }
  }, [active, dispatch, geometry, updateGeometry]);

  useEffect(() => {
    return () => {
      if (active) {
        clearGeometry();
      }
    };
  }, [active, clearGeometry]);

  return (
    <div
      data-testid={
        active
          ? 'canvas-headless-active-frame'
          : 'canvas-headless-pending-frame'
      }
      aria-hidden={!active}
      style={{
        width: `${viewportWidth}px`,
        minHeight: `${effectiveHeight}px`,
        background: '#fff',
        ...(active
          ? {}
          : {
              position: 'absolute',
              inset: 0,
              visibility: 'hidden',
              pointerEvents: 'none',
            }),
      }}
    >
      <p
        data-testid={
          active ? 'canvas-headless-status' : 'canvas-headless-pending-status'
        }
        aria-live={active ? 'polite' : 'off'}
        style={{
          margin: 0,
          padding: '4px 8px',
          fontSize: '12px',
          color: '#666',
          borderBottom: '1px solid #eee',
        }}
      >
        {statusText}
      </p>
      <div
        ref={previewContainerRef}
        data-testid={
          active
            ? 'canvas-headless-viewport'
            : 'canvas-headless-pending-viewport'
        }
        style={{
          height: `${effectiveHeight}px`,
          overflow: 'hidden',
          background: '#fff',
        }}
      >
        <iframe
          ref={iframeRef}
          title={active ? 'Headless preview' : 'Pending headless preview'}
          data-testid={
            active ? 'canvas-headless-iframe' : 'canvas-headless-pending-iframe'
          }
          tabIndex={
            active && editorFrameMode === EditorFrameMode.INTERACTIVE ? 0 : -1
          }
          // The editor frame centers its scroll position once the first load
          // completes; the srcdoc pipeline normally reports that.
          onLoad={() => dispatch(setFirstLoadComplete(true))}
          style={
            {
              '--canvas-headless-preview-height': `${iframeHeight}px`,
              display: 'block',
              width: '100%',
              // The host temporarily replaces height during viewport probes. A
              // stable declaration lets it restore this property without
              // overwriting a newer height committed by React during the probe.
              height: 'var(--canvas-headless-preview-height)',
              pointerEvents:
                active && editorFrameMode === EditorFrameMode.INTERACTIVE
                  ? 'auto'
                  : 'none',
              border: 'none',
            } as React.CSSProperties
          }
        ></iframe>
        {active && editorFrameMode === EditorFrameMode.EDIT && (
          <ViewportOverlay previewContainerRef={previewContainerRef} />
        )}
      </div>
    </div>
  );
};

/**
 * Embeds the configured frontend app in the editor frame.
 *
 * Replaces the Drupal-rendered srcdoc preview when the canvas_headless
 * module is enabled. The iframe is cross-origin, so draft state, height, and
 * shared preview geometry travel over the origin-checked postMessage protocol.
 * The app measures its own document; Canvas converts those rectangles and
 * renders its standard interactive overlays here.
 *
 * Page changes are double-buffered: the current iframe remains visible while
 * the next page activates and reports its height, then the new iframe replaces
 * it in one render. This avoids exposing navigation and height-probe states.
 */
const HeadlessPreview: React.FC<HeadlessPreviewProps> = ({
  settings,
  autoSavesHash,
}) => {
  const viewportWidth = useAppSelector(selectViewportWidth);
  const viewportMinHeight = useAppSelector(selectViewportMinHeight);
  const { entityId, entityType, previewEntityId, viewMode } = useParams();
  const contentEntityId = entityId ?? previewEntityId;
  const autoSavesHashRef = useRef(autoSavesHash);
  autoSavesHashRef.current = autoSavesHash;
  const currentFrame = useMemo<PreviewFrameDescriptor | null>(() => {
    if (!entityType || !contentEntityId) {
      return null;
    }
    return {
      frameKey: `${entityType}:${contentEntityId}:${viewMode ?? ''}`,
      entityType,
      entityId: contentEntityId,
      viewMode,
      autoSavesHash: autoSavesHashRef.current,
    };
  }, [contentEntityId, entityType, viewMode]);
  const currentFrameKeyRef = useRef(currentFrame?.frameKey);
  currentFrameKeyRef.current = currentFrame?.frameKey;
  const [frames, setFrames] = useState<PreviewFrameState>(() => ({
    active: currentFrame,
    pending: null,
  }));

  useEffect(() => {
    if (!currentFrame) {
      return;
    }
    setFrames((current) => {
      if (!current.active) {
        return { active: currentFrame, pending: null };
      }
      if (current.active.frameKey === currentFrame.frameKey) {
        return current.pending ? { ...current, pending: null } : current;
      }
      if (current.pending?.frameKey === currentFrame.frameKey) {
        return current;
      }
      return { ...current, pending: currentFrame };
    });
  }, [currentFrame]);

  const activateFrame = useCallback((frameKey: string) => {
    setFrames((current) => {
      // A pending frame can report readiness in the same render cycle as a
      // newer navigation. Never promote it after its route stopped being
      // current, or the already-ready target can be demoted indefinitely.
      if (
        frameKey !== currentFrameKeyRef.current ||
        current.pending?.frameKey !== frameKey
      ) {
        return current;
      }
      return { active: current.pending, pending: null };
    });
  }, []);

  const visibleFrames = [frames.active, frames.pending].filter(
    (frame): frame is PreviewFrameDescriptor => frame !== null,
  );

  return (
    <div
      className={styles.previewContainer}
      style={{ width: `${viewportWidth}px` }}
    >
      <PreviewProgress loading={frames.pending !== null} />
      {visibleFrames.map((frame) => {
        const isActive = frame.frameKey === frames.active?.frameKey;
        return (
          <HeadlessPreviewFrame
            {...frame}
            key={frame.frameKey}
            autoSavesHash={
              frame.frameKey === currentFrame?.frameKey
                ? autoSavesHash
                : frame.autoSavesHash
            }
            settings={settings}
            viewportWidth={viewportWidth}
            viewportMinHeight={viewportMinHeight}
            active={isActive}
            onReady={activateFrame}
          />
        );
      })}
    </div>
  );
};

export default HeadlessPreview;
