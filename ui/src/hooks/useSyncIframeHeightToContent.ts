import { useCallback, useRef } from 'react';
import { useEffect } from 'react';

/**
 * This hook takes preview iFrame and ensures that the height of the iFrame html element matches the height of the
 * content being rendered in the iFrame. It uses a mutation observer to keep it in sync
 */
function useSyncIframeHeightToContent(
  iframe: HTMLIFrameElement | null,
  previewContainer: HTMLDivElement | null,
  height: number,
  width: number,
) {
  const mutationObserverRef = useRef<MutationObserver | null>(null);
  const resizeObserverRef = useRef<ResizeObserver | null>(null);

  const resizeIframe = useCallback(() => {
    if (iframe && iframe.contentDocument) {
      const iframeHTML = iframe.contentDocument.documentElement;
      const iframeBody = iframe.contentDocument.body;
      window.requestAnimationFrame(() => {
        if (previewContainer?.style) {
          // set the iFrame container height to the height of the content inside the iFrame.
          previewContainer.style.height = iframeHTML?.offsetHeight
            ? `${iframeHTML.offsetHeight}px`
            : 'auto';
          previewContainer.style.width = width + 'px';
          previewContainer.style.minHeight = height + 'px';
        }
        if (iframeHTML?.style) {
          iframeHTML.style.minHeight = height + 'px';
        }
        if (iframeBody?.style) {
          iframeBody.style.minHeight = height + 'px';
        }
      });
    }
  }, [iframe, height, width, previewContainer]);

  useEffect(() => {
    if (iframe) {
      const iframeContentDoc = iframe.contentDocument;

      if (iframeContentDoc) {
        const iframeHTML = iframeContentDoc.documentElement;

        iframeHTML.style.overflow = 'hidden';
        // Set up a MutationObserver to watch for changes in the content of the iframe
        mutationObserverRef.current = new MutationObserver(resizeIframe);
        mutationObserverRef.current.observe(iframeHTML, {
          attributes: true,
          childList: true,
          subtree: true,
        });
        resizeObserverRef.current = new ResizeObserver(resizeIframe);
        resizeObserverRef.current.observe(iframeHTML);

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
    return () => {
      mutationObserverRef.current?.disconnect();
      resizeObserverRef.current?.disconnect();
    };
  }, [iframe, height, resizeIframe]);
}

export default useSyncIframeHeightToContent;
