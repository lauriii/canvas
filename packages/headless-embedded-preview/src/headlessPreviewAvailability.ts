/**
 * @file
 * Detects whether an embedded headless frontend answers the Canvas handshake.
 */

import {
  HEADLESS_NAVIGATION_READY_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
} from '@drupal-canvas/headless-host';

export interface HeadlessPreviewAvailabilityOptions {
  iframe: HTMLIFrameElement;
  frontendOrigin: string;
  onAvailable: () => void;
  onUnavailable: () => void;
  timeout?: number;
  windowObject?: Pick<
    Window,
    'addEventListener' | 'clearTimeout' | 'removeEventListener' | 'setTimeout'
  >;
}

export const HEADLESS_PREVIEW_AVAILABILITY_TIMEOUT = 10_000;

/**
 * Reports when the frontend starts its navigation bridge or answers the draft
 * handshake.
 */
export function observeHeadlessPreviewAvailability({
  iframe,
  frontendOrigin,
  onAvailable,
  onUnavailable,
  timeout = HEADLESS_PREVIEW_AVAILABILITY_TIMEOUT,
  windowObject = window,
}: HeadlessPreviewAvailabilityOptions): () => void {
  let available = false;
  const timer = windowObject.setTimeout(onUnavailable, timeout);
  const onMessage = (event: MessageEvent) => {
    if (
      available ||
      event.origin !== frontendOrigin ||
      event.source !== iframe.contentWindow ||
      (event.data?.type !== HEADLESS_STATUS_MESSAGE &&
        event.data?.type !== HEADLESS_NAVIGATION_READY_MESSAGE)
    ) {
      return;
    }
    available = true;
    windowObject.clearTimeout(timer);
    onAvailable();
  };
  windowObject.addEventListener('message', onMessage);

  return () => {
    windowObject.clearTimeout(timer);
    windowObject.removeEventListener('message', onMessage);
  };
}
