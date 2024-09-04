import type React from 'react';
import { useCallback } from 'react';
import { useEffect } from 'react';

/**
 * This hook takes preview iFrame and ensures that the height of the iFrame html element matches the height of the
 * content being rendered in the iFrame. It uses a mutation observer to keep it in sync
 */
function useSyncIframeHeightToContent(
  iframeRef: React.RefObject<HTMLIFrameElement>,
  height: number,
  width: number,
) {
  const resizeIframe = useCallback(() => {
    const iframe = iframeRef.current;
    if (iframe && iframe.contentDocument) {
      const iframeHTML = iframe.contentDocument.documentElement;
      const iframeBody = iframe.contentDocument.body;
      window.requestAnimationFrame(() => {
        iframe.style.height = iframeHTML?.offsetHeight
          ? `${iframeHTML.offsetHeight}px`
          : 'auto';
        iframe.style.width = width + 'px';
        iframe.style.minHeight = height + 'px';
        iframeHTML.style.minHeight = height + 'px';
        iframeBody.style.minHeight = height + 'px';
      });
    }
  }, [iframeRef, height, width]);

  const handleLoad = useCallback(
    (event: Event) => {
      const iframe = event.currentTarget as HTMLIFrameElement | null;

      if (iframe) {
        const iframeContentDoc = iframe.contentDocument;

        if (iframeContentDoc) {
          const iframeHTML = iframeContentDoc.documentElement;

          iframeHTML.style.overflow = 'hidden';
          // Set up a MutationObserver to watch for changes in the content of the iframe
          const observer = new MutationObserver(resizeIframe);
          observer.observe(iframeHTML, {
            attributes: true,
            childList: true,
            subtree: true,
          });

          // Apply a max-height to elements with vh units in their height - otherwise an infinite loop can occur where a component's
          // height is based on the height of the iFrame and the iFrame's height is based on that component leading
          // to an ever-increasing iFrame height!
          const elements: NodeListOf<HTMLElement> =
            iframeHTML.querySelectorAll('*');
          elements.forEach((element: HTMLElement) => {
            if (element.style.height.endsWith('vh')) {
              const vhValue = parseFloat(element.style.height);
              const newHeight = (vhValue / 100) * height;
              element.style.maxHeight = newHeight + 'px';
              resizeIframe();
            }
          });

          resizeIframe();
        }
      }
    },
    [resizeIframe, height],
  );

  useEffect(() => {
    const iframe = iframeRef.current;

    if (iframe) {
      iframe.addEventListener('load', handleLoad);

      return () => {
        if (iframe) {
          iframe.removeEventListener('load', handleLoad);
        }
      };
    }
  }, [iframeRef, handleLoad]);
}

export default useSyncIframeHeightToContent;
