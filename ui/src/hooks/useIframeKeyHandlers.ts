import type React from 'react';
import { useCallback, useEffect } from 'react';
const modifierKey = 'Space';

/**
 * This hook takes preview iFrame and makes sure that if the iframe has focus, any key presses the user makes are
 * passed up to the parent window via post messages
 */
function useIframeKeyHandlers(iframeRef: React.RefObject<HTMLIFrameElement>) {
  function notifyParentDocument(event: KeyboardEvent) {
    if (event.type === 'keydown') {
      if (
        (event.metaKey && event.key === 'z' && !event.shiftKey) ||
        (event.ctrlKey && event.key === 'z')
      ) {
        window.parent.postMessage('dispatchUndo', '*');
        return;
      }
      if (
        (event.metaKey && event.shiftKey && event.key === 'z') ||
        (event.ctrlKey && event.key === 'y')
      ) {
        window.parent.postMessage('dispatchRedo', '*');
        return;
      }
      if (event.code === 'NumpadAdd' || event.code === 'Equal') {
        window.parent.postMessage('dispatchZoomIn', '*');
        return;
      }
      if (event.code === 'NumpadSubtract' || event.code === 'Minus') {
        window.parent.postMessage('dispatchZoomOut', '*');
        return;
      }
      if (event.code === modifierKey) {
        window.parent.postMessage('dispatchModifierKeyDown', '*');
        return;
      }
    }
    if (event.type === 'keyup') {
      if (event.code === modifierKey) {
        window.parent.postMessage('dispatchModifierKeyUp', '*');
        return;
      }
    }
  }
  const handleLoad = useCallback((event: Event) => {
    const iframe = event.currentTarget as HTMLIFrameElement | null;

    if (iframe) {
      const iframeContentDoc = iframe.contentDocument;

      if (iframeContentDoc) {
        // Add event listeners to the iframe's body for keyboard events
        iframeContentDoc.body.addEventListener('keydown', notifyParentDocument);
        iframeContentDoc.body.addEventListener('keyup', notifyParentDocument);
      }
    }
  }, []);

  useEffect(() => {
    const iframe = iframeRef.current;

    if (iframe) {
      iframe.addEventListener('load', handleLoad);

      return () => {
        if (iframe) {
          const iframeContentDoc = iframe.contentDocument;
          iframe.removeEventListener('load', handleLoad);
          if (iframeContentDoc) {
            iframeContentDoc.body.removeEventListener(
              'keydown',
              notifyParentDocument,
            );
            iframeContentDoc.body.removeEventListener(
              'keyup',
              notifyParentDocument,
            );
          }
        }
      };
    }
  }, [iframeRef, handleLoad]);
}

export default useIframeKeyHandlers;
