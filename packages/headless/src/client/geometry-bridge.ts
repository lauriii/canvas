/**
 * App-side bridge between shared Canvas geometry measurement and its editor
 * host. Geometry remains in iframe viewport CSS pixels; the host owns all
 * coordinate conversion.
 */
import { createCanvasGeometryObserver } from '@drupal-canvas/preview-geometry';

import {
  HEADLESS_GEOMETRY_MESSAGE,
  HEADLESS_GEOMETRY_REQUEST_MESSAGE,
} from '../constants';

import type { CanvasGeometryObserver } from '@drupal-canvas/preview-geometry';

export interface CanvasGeometryBridgeOptions {
  editorOrigin: string;
  root?: Document;
  hostWindow?: Pick<Window, 'postMessage'>;
  listenerTarget?: Pick<Window, 'addEventListener' | 'removeEventListener'>;
}

export interface CanvasGeometryBridge {
  destroy(): void;
}

/** Starts measurement only after an origin- and source-checked host request. */
export function createCanvasGeometryBridge(
  options: CanvasGeometryBridgeOptions,
): CanvasGeometryBridge {
  const {
    editorOrigin,
    root = document,
    hostWindow = window.parent,
    listenerTarget = window,
  } = options;
  let observer: CanvasGeometryObserver | null = null;
  let hostSessionId: string | null = null;
  let destroyed = false;

  const postGeometry = (
    geometry: ReturnType<CanvasGeometryObserver['measure']>,
  ) => {
    if (!destroyed) {
      hostWindow.postMessage(
        {
          type: HEADLESS_GEOMETRY_MESSAGE,
          geometry,
          hostSessionId,
        },
        editorOrigin,
      );
    }
  };

  const onMessage = (event: MessageEvent) => {
    if (
      destroyed ||
      event.source !== hostWindow ||
      event.origin !== editorOrigin ||
      !event.data
    ) {
      return;
    }

    if (
      event.data.type !== HEADLESS_GEOMETRY_REQUEST_MESSAGE ||
      typeof event.data.hostSessionId !== 'string' ||
      event.data.hostSessionId === ''
    ) {
      return;
    }
    hostSessionId = event.data.hostSessionId;

    if (observer) {
      // Always reply with the current snapshot. A replacement host may not
      // have the observer's last result, and onChange ignores unchanged data.
      postGeometry(observer.measure());
    } else {
      observer = createCanvasGeometryObserver({
        root,
        onChange: postGeometry,
      });
    }
  };

  listenerTarget.addEventListener('message', onMessage as EventListener);
  return {
    destroy: () => {
      destroyed = true;
      observer?.disconnect();
      listenerTarget.removeEventListener('message', onMessage as EventListener);
    },
  };
}
