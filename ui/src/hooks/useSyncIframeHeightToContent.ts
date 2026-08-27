import { useCallback, useLayoutEffect, useRef } from 'react';
import {
  collectElementsUnderRoots,
  isVhMeasurementCandidate,
  measureNaturalDocumentHeight,
  STABLE_HEIGHT_ATTRIBUTE,
  StableHeightReader,
} from '@drupal-canvas/height-reader';

/** Multipliers large enough that component content is dominated by vh-driven height. */
const MULTIPLIERS = [3, 8];

function collectMutationRoots(mutations: MutationRecord[]): HTMLElement[] {
  const roots: HTMLElement[] = [];
  for (const m of mutations) {
    if (m.type === 'attributes' && m.target.nodeType === Node.ELEMENT_NODE) {
      roots.push(m.target as HTMLElement);
      continue;
    }
    if (m.type !== 'childList') {
      continue;
    }
    for (const n of Array.from(m.addedNodes)) {
      if (n.nodeType === Node.ELEMENT_NODE) {
        roots.push(n as HTMLElement);
      }
    }
  }
  return roots;
}

/**
 * This hook takes preview iFrame and ensures that the height of the iFrame html element matches the height of the
 * content being rendered in the iFrame. It uses a mutation observer to keep it in sync
 */
function useSyncIframeHeightToContent(
  iframe: HTMLIFrameElement | null,
  previewContainer: HTMLDivElement | null,
  height: number,
) {
  const mutationObserverRef = useRef<MutationObserver | null>(null);
  const resizeObserverRef = useRef<ResizeObserver | null>(null);
  const isDetectingRef = useRef(false);
  const reTagRafIdRef = useRef<number | null>(null);
  const pendingMutationRecordsRef = useRef<MutationRecord[]>([]);
  const previousHeightRef = useRef(height);
  const selfTaggedElementsRef = useRef(new Set<HTMLElement>());
  const heightReaderRef = useRef(new StableHeightReader());

  const resizeIframe = useCallback(() => {
    if (iframe && iframe.contentDocument) {
      const iframeHTML = iframe.contentDocument.documentElement;
      const iframeBody = iframe.contentDocument.body;
      window.requestAnimationFrame(() => {
        if (previewContainer?.style && iframe.contentDocument) {
          // set the iFrame container height to the height of the content inside the iFrame.
          // A page styling html or body with height/block-size 100% makes
          // offsetHeight track the iframe height instead of the content, so
          // measure with those rules neutralized (#3544531).
          const contentHeight = measureNaturalDocumentHeight(
            iframe.contentDocument,
          );
          // The measurement briefly writes inline styles on html and body;
          // discard those records so the MutationObserver does not treat them
          // as content changes and re-enter this resize.
          mutationObserverRef.current?.takeRecords();
          if (contentHeight) {
            previewContainer.style.height = `${contentHeight}px`;
          }
        }
        if (iframeHTML?.style) {
          iframeHTML.style.minHeight = height + 'px';
        }
        if (iframeBody?.style) {
          iframeBody.style.minHeight = height + 'px';
        }
      });
    }
  }, [iframe, height, previewContainer]);

  const safeResizeIframe = useCallback(() => {
    if (isDetectingRef.current) {
      return;
    }
    resizeIframe();
  }, [resizeIframe]);

  const detectAndTagVhElements = useCallback(
    async (mutationRoots?: HTMLElement[]) => {
      if (isDetectingRef.current) {
        return;
      }
      if (!iframe?.contentDocument) {
        return;
      }
      const iframeHTML = iframe.contentDocument.documentElement;
      const iframeWin = iframe.contentWindow;
      const innerHeightAtRest = iframeWin?.innerHeight ?? height;
      selfTaggedElementsRef.current.clear();

      isDetectingRef.current = true;
      try {
        const result = await heightReaderRef.current.stabilize({
          roots:
            mutationRoots && mutationRoots.length > 0
              ? mutationRoots
              : [iframeHTML],
          effectiveViewportHeight: innerHeightAtRest,
          baseViewportHeight: height,
          probeMultipliers: MULTIPLIERS,
          shouldPinNewElements: () =>
            document.querySelector('iframe[data-canvas-swap-active="true"]') !==
            null,
          probeController: {
            setViewportHeight: (viewportHeight) => {
              iframe.style.height = `${viewportHeight}px`;
              iframe.style.overflow = 'visible';
              void iframe.offsetHeight;
            },
            restoreViewportHeight: () => {
              iframe.style.height = '';
              iframe.style.overflow = '';
              void iframe.offsetHeight;
            },
          },
        });

        selfTaggedElementsRef.current = result.pinnedElements;

        if (result.didProbe) {
          const previousRuns = iframe.dataset.canvasVhDetectionRuns;
          iframe.dataset.canvasVhDetectionRuns = String(
            (previousRuns ? parseInt(previousRuns, 10) : 0) + 1,
          );
        }
      } finally {
        isDetectingRef.current = false;
      }

      resizeIframe();
    },
    [iframe, height, resizeIframe],
  );

  const safeDetectAndTagVhElements = useCallback(() => {
    if (isDetectingRef.current) {
      return;
    }
    void detectAndTagVhElements();
  }, [detectAndTagVhElements]);

  const handleMutations = useCallback<MutationCallback>(
    (mutations) => {
      if (isDetectingRef.current) {
        return;
      }

      const needsLayoutResize = mutations.some((m) => !isSelfTagMutation(m));

      if (needsLayoutResize) {
        safeResizeIframe();
      }

      const needsReTag = mutations.some((m) => {
        const mutationInnerHeight =
          iframe?.contentWindow?.innerHeight ?? height;
        if (m.type === 'attributes') {
          return needsAttributeReTag(m, mutationInnerHeight);
        }
        if (m.type !== 'childList') {
          return false;
        }
        if ((m.target as Element).tagName === 'CANVAS-ISLAND') {
          return true;
        }
        for (const n of Array.from(m.removedNodes)) {
          if (
            n.nodeType === Node.ELEMENT_NODE &&
            (n as Element).hasAttribute(STABLE_HEIGHT_ATTRIBUTE)
          ) {
            return true;
          }
        }
        for (const n of Array.from(m.addedNodes)) {
          if (
            n.nodeType === Node.ELEMENT_NODE &&
            (n as Element).hasAttribute(STABLE_HEIGHT_ATTRIBUTE)
          ) {
            return true;
          }
          if (n.nodeType === Node.ELEMENT_NODE) {
            const addedElements = collectElementsUnderRoots([n as HTMLElement]);
            if (
              addedElements.some((el) =>
                isVhMeasurementCandidate(el, mutationInnerHeight),
              )
            ) {
              return true;
            }
          }
        }
        return false;
      });

      if (!needsReTag) {
        return;
      }

      pendingMutationRecordsRef.current.push(...mutations);

      if (reTagRafIdRef.current !== null) {
        return;
      }

      reTagRafIdRef.current = window.requestAnimationFrame(() => {
        reTagRafIdRef.current = null;
        const merged = pendingMutationRecordsRef.current;
        pendingMutationRecordsRef.current = [];
        const roots = collectMutationRoots(merged);
        void detectAndTagVhElements(roots.length > 0 ? roots : undefined);
      });
    },
    [detectAndTagVhElements, height, iframe, safeResizeIframe],
  );

  function isSelfTagMutation(m: MutationRecord): boolean {
    if (m.type !== 'attributes') {
      return false;
    }
    if (
      m.attributeName !== 'style' &&
      m.attributeName !== STABLE_HEIGHT_ATTRIBUTE
    ) {
      return false;
    }
    const target = m.target as HTMLElement;
    return (
      selfTaggedElementsRef.current.has(target) &&
      target.hasAttribute(STABLE_HEIGHT_ATTRIBUTE)
    );
  }

  function needsAttributeReTag(
    m: MutationRecord,
    innerHeight: number,
  ): boolean {
    if (
      m.attributeName !== 'class' &&
      m.attributeName !== 'style' &&
      m.attributeName !== STABLE_HEIGHT_ATTRIBUTE
    ) {
      return false;
    }
    const target = m.target as HTMLElement;
    const taggedHeight = target.getAttribute(STABLE_HEIGHT_ATTRIBUTE);
    if (selfTaggedElementsRef.current.has(target)) {
      if (!taggedHeight) {
        return true;
      }
      if (m.attributeName === 'style') {
        const pixelHeight = `${taggedHeight}px`;
        return (
          target.style.height !== pixelHeight &&
          target.style.maxHeight !== pixelHeight &&
          target.style.minHeight !== pixelHeight
        );
      }
      return m.attributeName === 'class';
    }
    return isVhMeasurementCandidate(target, innerHeight);
  }

  useLayoutEffect(() => {
    if (previousHeightRef.current !== height) {
      heightReaderRef.current.clear();
      previousHeightRef.current = height;
    }
  }, [height]);

  useLayoutEffect(() => {
    if (iframe) {
      const handleLoad = () => {
        const iframeContentDoc = iframe.contentDocument;

        if (iframeContentDoc) {
          const iframeHTML = iframeContentDoc.documentElement;

          // Initially set the iFrame height to the height passed in to the
          // hook. A height of 0 (used by view modes whose height is driven
          // entirely by their content) must clear the inline height instead:
          // an inline `height: 0px` beats the stylesheet's `height: 100%` and
          // collapses the iFrame, and nothing restores it when the vh probe in
          // detectAndTagVhElements() does not run.
          iframe.style.height = height > 0 ? `${height}px` : '';
          iframeHTML.style.overflow = 'hidden';
          // Set up a MutationObserver to watch for changes in the content of the iframe
          mutationObserverRef.current = new MutationObserver(handleMutations);
          mutationObserverRef.current.observe(iframeHTML, {
            attributes: true,
            childList: true,
            subtree: true,
          });
          resizeObserverRef.current = new ResizeObserver(
            safeDetectAndTagVhElements,
          );
          resizeObserverRef.current.observe(iframeHTML);

          void detectAndTagVhElements();
        }
      };

      // Assign the load event listener
      iframe.addEventListener('load', handleLoad);

      // Check if the iFrame is already loaded
      if (iframe.contentDocument?.readyState === 'complete') {
        handleLoad();
      }

      return () => {
        iframe.removeEventListener('load', handleLoad);
        mutationObserverRef.current?.disconnect();
        resizeObserverRef.current?.disconnect();
        if (reTagRafIdRef.current !== null) {
          window.cancelAnimationFrame(reTagRafIdRef.current);
          reTagRafIdRef.current = null;
        }
        pendingMutationRecordsRef.current = [];
      };
    }
  }, [
    iframe,
    height,
    detectAndTagVhElements,
    handleMutations,
    safeDetectAndTagVhElements,
    safeResizeIframe,
  ]);
}

export default useSyncIframeHeightToContent;
