interface CanvasData {
  previewHtml: string;
  selectedComponentUuid: string | undefined;
}

interface CanvasMessage<T = unknown> {
  type: string;
  payload: T;
}

type UnsubscribeFn = () => void;

/**
 * Get data from Canvas for a specific type (one-time request).
 */
function get<K extends keyof CanvasData>(type: K): Promise<CanvasData[K]> {
  return new Promise((resolve) => {
    const handler = (event: MessageEvent<CanvasMessage<CanvasData[K]>>) => {
      if (event.data.type === `canvas:data:get:${type}`) {
        resolve(event.data.payload);
        window.removeEventListener('message', handler);
      }
    };

    window.addEventListener('message', handler);

    window.parent.postMessage(
      { type: `canvas:data:get:${type}` },
      window.location.origin,
    );
  });
}

/**
 * Subscribe to data changes from Canvas.
 */
function subscribe<K extends keyof CanvasData>(
  type: K,
  callback: (data: CanvasData[K]) => void,
): UnsubscribeFn {
  const handler = (event: MessageEvent<CanvasMessage<CanvasData[K]>>) => {
    if (event.data.type === `canvas:data:subscribe:${type}`) {
      callback(event.data.payload);
    }
  };

  window.addEventListener('message', handler);

  window.parent.postMessage(
    { type: `canvas:data:subscribe:${type}` },
    window.location.origin,
  );

  // Return unsubscribe function.
  return () => {
    window.parent.postMessage(
      { type: `canvas:data:unsubscribe:${type}` },
      window.location.origin,
    );
    window.removeEventListener('message', handler);
  };
}

// Convenience wrappers for specific data types.

/**
 * Get preview HTML.
 */
export function getPreviewHtml(): Promise<string> {
  return get('previewHtml');
}

/**
 * Subscribe to preview HTML changes.
 */
export function subscribeToPreviewHtml(
  callback: (html: string) => void,
): UnsubscribeFn {
  return subscribe('previewHtml', callback);
}

/**
 * Get currently selected component UUID.
 */
export function getSelectedComponentUuid(): Promise<string | undefined> {
  return get('selectedComponentUuid');
}

/**
 * Subscribe to currently selected component UUID changes.
 */
export function subscribeToSelectedComponentUuid(
  callback: (uuid: string | undefined) => void,
): UnsubscribeFn {
  return subscribe('selectedComponentUuid', callback);
}
