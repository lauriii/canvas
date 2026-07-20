import { useRef } from 'react';
import { useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useHeadlessDraftSession } from '@/features/layout/preview/useHeadlessDraftSession';
import {
  selectViewportMinHeight,
  selectViewportWidth,
  setFirstLoadComplete,
} from '@/features/ui/uiSlice';

import type { HeadlessSettings } from '@drupal-canvas/types';
import type { AutoSavesHashRecord } from '@/types/AutoSaves';

interface HeadlessPreviewProps {
  settings: HeadlessSettings;
  autoSavesHash: AutoSavesHashRecord;
}

/**
 * Embeds the configured frontend app in the editor frame.
 *
 * Replaces the Drupal-rendered srcdoc preview when the canvas_headless
 * module is enabled. The iframe is cross-origin, so none of the same-origin
 * preview behavior (overlays, height sync, in-place prop updates) applies;
 * the draft session is driven over postMessage instead — see
 * useHeadlessDraftSession for the protocol.
 */
const HeadlessPreview: React.FC<HeadlessPreviewProps> = ({
  settings,
  autoSavesHash,
}) => {
  const dispatch = useAppDispatch();
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const viewportWidth = useAppSelector(selectViewportWidth);
  const viewportMinHeight = useAppSelector(selectViewportMinHeight);
  const { entityId, entityType } = useParams();
  const { statusText } = useHeadlessDraftSession(
    iframeRef,
    settings,
    entityType,
    entityId,
    autoSavesHash,
  );

  return (
    <div
      style={{
        width: `${viewportWidth}px`,
        minHeight: `${viewportMinHeight}px`,
        background: '#fff',
      }}
    >
      <p
        data-testid="canvas-headless-status"
        aria-live="polite"
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
      <iframe
        ref={iframeRef}
        title="Headless preview"
        data-testid="canvas-headless-iframe"
        // The editor frame centers its scroll position once the first load
        // completes; the srcdoc pipeline normally reports that.
        onLoad={() => dispatch(setFirstLoadComplete(true))}
        style={{
          display: 'block',
          width: '100%',
          height: `${viewportMinHeight}px`,
          border: 'none',
        }}
      ></iframe>
    </div>
  );
};

export default HeadlessPreview;
