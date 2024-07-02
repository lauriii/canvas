import type React from 'react';
import { useEffect, useState, useCallback, useRef } from 'react';

/**
 * This hook takes preview iFrame and an elementID and returns a state containing the elements dimensions and position.
 * It uses a mutation observer and resize observer to ensure that even if the element changes size or position at any point
 * the returned values are updated.
 */

interface Rect {
  top: number;
  left: number;
  width: number;
  height: number;
}

function useSyncElementSize(
  iframeRef: React.RefObject<HTMLIFrameElement>,
  elementId: string | undefined,
) {
  const [elementRect, setElementRect] = useState<Rect>({
    top: 0,
    left: 0,
    width: 0,
    height: 0,
  });

  const elementIdRef = useRef(elementId);
  const mutationObserverRef = useRef<MutationObserver | null>(null);
  const resizeObserverRef = useRef<ResizeObserver | null>(null);

  const recalculateBorder = useCallback(() => {
    const element = iframeRef.current?.contentDocument?.querySelectorAll(
      `[data-xb-uuid="${elementIdRef.current}"]`,
    )[0] as HTMLElement | null;
    if (element && elementIdRef.current) {
      setElementRect(
        (({ left, top, width, height }) => ({ left, top, width, height }))(
          element.getBoundingClientRect(),
        ),
      );
    }
  }, [iframeRef]);

  useEffect(() => {
    elementIdRef.current = elementId;
  }, [elementId]);

  const handleLoad = useCallback(
    (event: Event) => {
      recalculateBorder();
      const iframe = event.currentTarget as HTMLIFrameElement | null;
      const iframeHTML = iframe?.contentDocument?.documentElement;

      if (!iframeHTML) {
        return;
      }

      iframeHTML.style.overflow = 'hidden';

      // Disconnect existing observers
      mutationObserverRef.current?.disconnect();
      resizeObserverRef.current?.disconnect();

      // Set up a new MutationObserver to watch for changes in the content of the iframe
      const observer = new MutationObserver(recalculateBorder);
      observer.observe(iframeHTML, {
        attributes: true,
        childList: true,
        subtree: true,
      });
      mutationObserverRef.current = observer;

      const element = iframeRef.current?.contentDocument?.querySelectorAll(
        `[data-xb-uuid="${elementId}"]`,
      )[0] as HTMLElement | null;

      if (!element) {
        return;
      }
      const resizeObserver = new ResizeObserver((entries) => {
        entries.forEach(() => {
          recalculateBorder();
        });
      });
      resizeObserver.observe(element);
      resizeObserverRef.current = resizeObserver;
    },
    [recalculateBorder, elementId, iframeRef],
  );

  useEffect(() => {
    recalculateBorder();
  }, [elementId, recalculateBorder]);

  useEffect(() => {
    const iframe = iframeRef.current;

    if (iframe) {
      iframe.addEventListener('load', handleLoad);

      return () => {
        if (iframe) {
          iframe.removeEventListener('load', handleLoad);
        }
        // Cleanup observers
        mutationObserverRef.current?.disconnect();
        resizeObserverRef.current?.disconnect();
      };
    }
  }, [iframeRef, handleLoad]);

  return elementRect;
}

export default useSyncElementSize;
