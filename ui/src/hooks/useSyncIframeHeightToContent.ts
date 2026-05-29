import { useCallback, useLayoutEffect, useRef } from 'react';

/** Multipliers large enough that component content is dominated by vh-driven height. */
const MULTIPLIERS = [3, 8];

type VhSignatureCacheEntry = {
  maxHeight: number;
  shouldCapMaxHeight: boolean;
};

/**
 * HTML elements expose className as a string; SVG elements use SVGAnimatedString.
 */
function getClassNameString(element: Element): string {
  const cn = element.className;
  if (typeof cn === 'string') {
    return cn;
  }
  if (
    cn &&
    typeof cn === 'object' &&
    'baseVal' in cn &&
    typeof (cn as { baseVal: string }).baseVal === 'string'
  ) {
    return (cn as { baseVal: string }).baseVal;
  }
  return element.getAttribute('class') ?? '';
}

function getElementSignature(element: HTMLElement): string {
  return [
    element.tagName,
    element.id,
    element.getAttribute('data-div') ?? '',
    element.getAttribute('data-testid') ?? '',
    getClassNameString(element),
    element.getAttribute('style') ?? '',
  ].join('|');
}

function looksLikeVhClassOrInline(element: HTMLElement): boolean {
  const cls = getClassNameString(element);
  const styleAttr = element.getAttribute('style');
  return (
    cls.includes('screen') ||
    cls.includes('vh') ||
    cls.includes('min-h-') ||
    cls.includes('h-screen') ||
    (styleAttr != null && styleAttr.includes('vh'))
  );
}

function approximatelyEquals(a: number, b: number): boolean {
  return Math.abs(a - b) <= 2;
}

/**
 * Catches CSS-file vh rules (e.g. tests/modules/canvas_test_vh_preview) with no
 * Tailwind-style class markers.
 */
function cssMatchesViewportHeuristic(
  element: HTMLElement,
  innerHeight: number,
): boolean {
  const win = element.ownerDocument.defaultView;
  if (!win) {
    return false;
  }
  const cs = win.getComputedStyle(element);
  const minH = parseFloat(cs.minHeight);
  const heightVal = parseFloat(cs.height);
  const targets = [innerHeight, innerHeight / 2];
  for (const t of targets) {
    if (Number.isFinite(minH) && approximatelyEquals(minH, t)) {
      return true;
    }
    if (Number.isFinite(heightVal) && approximatelyEquals(heightVal, t)) {
      return true;
    }
  }
  return false;
}

function usesViewportHeightProperty(
  element: HTMLElement,
  innerHeight: number,
): boolean {
  const win = element.ownerDocument.defaultView;
  if (!win) {
    return false;
  }
  const heightVal = parseFloat(win.getComputedStyle(element).height);
  return [innerHeight, innerHeight / 2].some(
    (target) =>
      Number.isFinite(heightVal) && approximatelyEquals(heightVal, target),
  );
}

function isVhMeasurementCandidate(
  element: HTMLElement,
  innerHeight: number,
): boolean {
  if (['HTML', 'BODY'].includes(element.tagName)) {
    return false;
  }
  if (looksLikeVhClassOrInline(element)) {
    return true;
  }
  if (element.hasAttribute('data-canvas-preview-max-height')) {
    return true;
  }
  return cssMatchesViewportHeuristic(element, innerHeight);
}

function collectElementsUnderRoots(roots: HTMLElement[]): HTMLElement[] {
  const out: HTMLElement[] = [];
  const seen = new Set<HTMLElement>();
  for (const root of roots) {
    if (root.nodeType !== Node.ELEMENT_NODE) {
      continue;
    }
    if (!seen.has(root)) {
      seen.add(root);
      out.push(root);
    }
    root.querySelectorAll<HTMLElement>('*').forEach((el) => {
      if (!seen.has(el)) {
        seen.add(el);
        out.push(el);
      }
    });
  }
  return out;
}

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

function applyTagFromCache(
  element: HTMLElement,
  entry: VhSignatureCacheEntry,
): void {
  const pixelHeight = `${entry.maxHeight}px`;
  element.style.setProperty('min-height', pixelHeight, 'important');
  const naturalH = element.clientHeight;
  if (entry.shouldCapMaxHeight) {
    element.style.setProperty('height', pixelHeight, 'important');
    element.style.setProperty('max-height', pixelHeight, 'important');
  } else {
    element.style.removeProperty('height');
    if (naturalH > entry.maxHeight + 2) {
      element.style.removeProperty('max-height');
    } else {
      element.style.setProperty('max-height', pixelHeight, 'important');
    }
  }
  element.setAttribute('data-canvas-preview-max-height', `${entry.maxHeight}`);
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
  const signatureCacheRef = useRef(new Map<string, VhSignatureCacheEntry>());
  const previousHeightRef = useRef(height);
  const selfTaggedElementsRef = useRef(new Set<HTMLElement>());

  const resizeIframe = useCallback(() => {
    if (iframe && iframe.contentDocument) {
      const iframeHTML = iframe.contentDocument.documentElement;
      const iframeBody = iframe.contentDocument.body;
      window.requestAnimationFrame(() => {
        if (previewContainer?.style) {
          // set the iFrame container height to the height of the content inside the iFrame.
          if (iframeHTML?.offsetHeight) {
            previewContainer.style.height = `${iframeHTML.offsetHeight}px`;
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
    (mutationRoots?: HTMLElement[]) => {
      if (isDetectingRef.current) {
        return;
      }
      if (!iframe?.contentDocument) {
        return;
      }
      const iframeHTML = iframe.contentDocument.documentElement;
      const iframeWin = iframe.contentWindow;
      const innerHeightAtRest = iframeWin?.innerHeight ?? height;

      const previewIframe = document.querySelector(
        'iframe[data-canvas-swap-active="true"]',
      ) as HTMLIFrameElement | null;

      selfTaggedElementsRef.current.clear();

      let elementsToMeasure: HTMLElement[];

      if (mutationRoots && mutationRoots.length > 0) {
        const underRoots = collectElementsUnderRoots(mutationRoots);
        const uncached: HTMLElement[] = [];
        for (const el of underRoots) {
          const sig = getElementSignature(el);
          const cached = signatureCacheRef.current.get(sig);
          if (cached) {
            applyTagFromCache(el, cached);
            selfTaggedElementsRef.current.add(el);
          } else {
            uncached.push(el);
          }
        }
        if (uncached.length === 0) {
          resizeIframe();
          return;
        }
        elementsToMeasure = uncached.filter((el) =>
          isVhMeasurementCandidate(el, innerHeightAtRest),
        );
        if (elementsToMeasure.length === 0) {
          resizeIframe();
          return;
        }
      } else {
        elementsToMeasure = collectElementsUnderRoots([iframeHTML]).filter(
          (el) => isVhMeasurementCandidate(el, innerHeightAtRest),
        );
      }

      const heightRatios = new WeakMap<HTMLElement, number[]>();

      isDetectingRef.current = true;
      try {
        const prevRuns = iframe.dataset.canvasVhDetectionRuns;
        iframe.dataset.canvasVhDetectionRuns = String(
          (prevRuns ? parseInt(prevRuns, 10) : 0) + 1,
        );

        MULTIPLIERS.forEach((multi) => {
          iframe.style.height = height * multi + 'px';
          iframe.style.overflow = 'visible';
          void iframe.offsetHeight;
          elementsToMeasure.forEach((element) => {
            const ratios: number[] = heightRatios.get(element) || [];
            if (element.clientHeight > 10) {
              ratios.push(Math.floor(element.clientHeight / multi));
              heightRatios.set(element, ratios);
            }
          });
        });
        iframe.style.height = '';
        iframe.style.overflow = '';

        elementsToMeasure.forEach((element) => {
          if (previewIframe) {
            const ratios: number[] = heightRatios.get(element) || [];

            const ratiosMatchAtHighMultipliers =
              ratios.length === MULTIPLIERS.length &&
              ratios[MULTIPLIERS.length - 1] === ratios[MULTIPLIERS.length - 2];
            if (
              !['HTML', 'BODY'].includes(element.tagName) &&
              ratiosMatchAtHighMultipliers
            ) {
              const maxHeight = ratios[MULTIPLIERS.length - 1];
              if (maxHeight) {
                const pixelHeight = `${maxHeight}px`;
                const shouldCapMaxHeight = usesViewportHeightProperty(
                  element,
                  innerHeightAtRest,
                );
                element.style.setProperty(
                  'min-height',
                  pixelHeight,
                  'important',
                );
                const naturalH = element.clientHeight;
                if (shouldCapMaxHeight) {
                  element.style.setProperty('height', pixelHeight, 'important');
                  element.style.setProperty(
                    'max-height',
                    pixelHeight,
                    'important',
                  );
                } else {
                  element.style.removeProperty('height');
                  if (naturalH > maxHeight + 2) {
                    element.style.removeProperty('max-height');
                  } else {
                    element.style.setProperty(
                      'max-height',
                      pixelHeight,
                      'important',
                    );
                  }
                }
                element.setAttribute(
                  'data-canvas-preview-max-height',
                  `${maxHeight}`,
                );
                selfTaggedElementsRef.current.add(element);

                const sig = getElementSignature(element);
                signatureCacheRef.current.set(sig, {
                  maxHeight,
                  shouldCapMaxHeight,
                });
              } else {
                element.style.maxHeight = '';
                element.style.minHeight = '';
                element.setAttribute(
                  'data-canvas-preview-max-height',
                  `${maxHeight}`,
                );
              }
            }
          }
        });
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
    detectAndTagVhElements();
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
            (n as Element).hasAttribute('data-canvas-preview-max-height')
          ) {
            return true;
          }
        }
        for (const n of Array.from(m.addedNodes)) {
          if (
            n.nodeType === Node.ELEMENT_NODE &&
            (n as Element).hasAttribute('data-canvas-preview-max-height')
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
        detectAndTagVhElements(roots.length > 0 ? roots : undefined);
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
      m.attributeName !== 'data-canvas-preview-max-height'
    ) {
      return false;
    }
    const target = m.target as HTMLElement;
    return (
      selfTaggedElementsRef.current.has(target) &&
      target.hasAttribute('data-canvas-preview-max-height')
    );
  }

  function needsAttributeReTag(
    m: MutationRecord,
    innerHeight: number,
  ): boolean {
    if (
      m.attributeName !== 'class' &&
      m.attributeName !== 'style' &&
      m.attributeName !== 'data-canvas-preview-max-height'
    ) {
      return false;
    }
    const target = m.target as HTMLElement;
    const taggedHeight = target.getAttribute('data-canvas-preview-max-height');
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
      signatureCacheRef.current.clear();
      previousHeightRef.current = height;
    }
  }, [height]);

  useLayoutEffect(() => {
    if (iframe) {
      const handleLoad = () => {
        const iframeContentDoc = iframe.contentDocument;

        if (iframeContentDoc) {
          const iframeHTML = iframeContentDoc.documentElement;

          // initially set the iFrame height to the height passed in to the hook
          iframe.style.height = height + 'px';
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

          detectAndTagVhElements();
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
