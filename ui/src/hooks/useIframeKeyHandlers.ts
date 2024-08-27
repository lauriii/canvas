import type React from 'react';
import { useCallback, useEffect } from 'react';
const modifierKey = 'Control';

/**
 * This hook takes preview iFrame and makes sure that if the iframe has focus, any key presses the user makes are
 * passed up to the parent window via post messages
 */
function useIframeKeyHandlers(iframeRef: React.RefObject<HTMLIFrameElement>) {
  function notifyParentDocumentKey(event: KeyboardEvent) {
    const keyCombinations = {
      dispatchUndo:
        (event.type === 'keydown' &&
          event.metaKey &&
          event.key === 'z' &&
          !event.shiftKey) ||
        (event.ctrlKey && event.key === 'z'),
      dispatchRedo:
        (event.type === 'keydown' &&
          event.metaKey &&
          event.shiftKey &&
          event.key === 'z') ||
        (event.ctrlKey && event.key === 'y'),
      dispatchZoomIn:
        (event.type === 'keydown' && event.code === 'NumpadAdd') ||
        event.code === 'Equal',
      dispatchZoomOut:
        (event.type === 'keydown' && event.code === 'NumpadSubtract') ||
        event.code === 'Minus',
      dispatchModifierKeyDown:
        event.type === 'keydown' && event.key === modifierKey,
      dispatchModifierKeyUp:
        event.type === 'keyup' && event.key === modifierKey,
    };

    Object.entries(keyCombinations).some(([message, shouldDispatch]) => {
      if (shouldDispatch) {
        window.parent.postMessage(message, '*');
        return true;
      }
      return false;
    });
  }

  function notifyParentDocumentWheel(event: WheelEvent) {
    if (event.ctrlKey) {
      event.preventDefault();
      event.stopPropagation();
      window.parent.postMessage(
        {
          type: 'dispatchZoomDelta',
          delta: event.deltaY,
        },
        '*',
      );
    }
  }
  function notifyParentDocumentMouse(event: MouseEvent) {
    switch (event.type) {
      case 'mousemove':
        window.parent.postMessage(
          {
            type: 'dispatchMouseMove',
            coordinates: { x: event.clientX, y: event.clientY },
          },
          '*',
        );
        break;
      case 'mousedown':
        if (event.button === 1) {
          window.parent.postMessage(
            {
              type: 'dispatchMiddleMouseDown',
              coordinates: { x: event.clientX, y: event.clientY },
            },
            '*',
          );
          event.preventDefault();
        }
        break;
      case 'mouseup':
        if (event.button === 1) {
          window.parent.postMessage('dispatchMiddleMouseUp', '*');
        }
        break;
    }
  }

  const handleLoad = useCallback((event: Event) => {
    const iframe = event.currentTarget as HTMLIFrameElement | null;

    if (!iframe) {
      return;
    }

    const iframeContentDoc = iframe.contentDocument;

    (['keydown', 'keyup'] as Array<keyof HTMLElementEventMap>).forEach(
      (eventType) => {
        iframeContentDoc?.body.addEventListener(
          eventType,
          notifyParentDocumentKey as EventListener,
        );
      },
    );

    (
      ['mousedown', 'mouseup', 'mousemove'] as Array<keyof HTMLElementEventMap>
    ).forEach((eventType) => {
      iframeContentDoc?.body.addEventListener(
        eventType,
        notifyParentDocumentMouse as EventListener,
      );
    });

    iframeContentDoc?.body.addEventListener(
      'wheel',
      notifyParentDocumentWheel as EventListener,
      { passive: false },
    );
  }, []);

  useEffect(() => {
    const iframe = iframeRef.current;

    iframe?.addEventListener('load', handleLoad);

    return () => {
      if (!iframe) {
        return;
      }

      const iframeContentDoc = iframe.contentDocument;
      iframe.removeEventListener('load', handleLoad);

      (['keydown', 'keyup'] as Array<keyof HTMLElementEventMap>).forEach(
        (eventType) => {
          iframeContentDoc?.body.removeEventListener(
            eventType,
            notifyParentDocumentKey as EventListener,
          );
        },
      );

      (['mousedown', 'mouseup'] as Array<keyof HTMLElementEventMap>).forEach(
        (eventType) => {
          iframeContentDoc?.body.removeEventListener(
            eventType,
            notifyParentDocumentMouse as EventListener,
          );
        },
      );
    };
  }, [iframeRef, handleLoad]);
}

export default useIframeKeyHandlers;
