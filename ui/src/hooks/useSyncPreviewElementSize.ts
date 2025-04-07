import { useEffect, useState, useCallback, useRef, useMemo } from 'react';

/**
 * This hook takes an HTML element or array of HTML elements and returns a state containing the elements' dimensions and position.
 * It uses a mutation observer and resize observer to ensure that even if the element changes size or position at any point
 * the returned values are updated.
 */

interface Rect {
  top: number;
  left: number;
  width: number;
  height: number;
}

function elemIsVisible(elem: HTMLElement) {
  return !!(
    elem.offsetWidth ||
    elem.offsetHeight ||
    elem.getClientRects().length
  );
}

function findParentBody(element: HTMLElement) {
  let currentElement = element;

  while (currentElement) {
    if (currentElement.nodeName.toLowerCase() === 'body') {
      return currentElement; // Found the body element
    }
    const parent = currentElement.parentElement;
    if (!parent) break;
    currentElement = parent;
  }

  return null; // Return null if no <body> is found
}

function isElementObservable(element: HTMLElement) {
  const style = window.getComputedStyle(element);

  // Check if the element is inline - inline elements to not fire resize events.
  if (style.display === 'inline') {
    return false;
  }

  return elemIsVisible(element);
}

function getMaxOfArray(numArray: number[]) {
  return Math.max.apply(null, numArray);
}

function getMinOfArray(numArray: number[]) {
  return Math.min.apply(null, numArray);
}

function useSyncPreviewElementSize(input: HTMLElement[] | HTMLElement | null) {
  // Normalize the input to always be an array
  const elements = useMemo(() => {
    if (!input) {
      return null;
    }
    return Array.isArray(input) ? input : [input];
  }, [input]);

  const [elementRect, setElementRect] = useState<Rect>({
    top: 0,
    left: 0,
    width: 0,
    height: 0,
  });

  const resizeObserverRef = useRef<ResizeObserver | null>(null);
  const mutationObserverRef = useRef<MutationObserver | null>(null);
  const elementsRef = useRef<HTMLElement[] | null>(null);

  const recalculateBorder = useCallback(() => {
    const tops: number[] = [];
    const lefts: number[] = [];
    const rights: number[] = [];
    const bottoms: number[] = [];

    elementsRef.current?.forEach((el) => {
      if (!el) {
        return;
      }
      const rect = el.getBoundingClientRect();

      // check the element is actually visible on the page - otherwise we end up incorrectly setting the minTop & minLeft to be 0 for hidden elements.
      if (elemIsVisible(el)) {
        tops.push(rect.top);
        lefts.push(rect.left);
        rights.push(rect.left + rect.width);
        bottoms.push(rect.top + rect.height);
      }
    });

    const minTop = getMinOfArray(tops);
    const minLeft = getMinOfArray(lefts);
    const newRect = {
      top: minTop,
      left: minLeft,
      width: getMaxOfArray(rights) - minLeft,
      height: getMaxOfArray(bottoms) - minTop,
    };

    if (elementsRef.current) {
      requestAnimationFrame(() => {
        setElementRect((prevRect) => {
          // Only update if the values have changed so the hook returns the same object preventing components that use
          // it from re-rendering
          if (
            prevRect.top !== newRect.top ||
            prevRect.left !== newRect.left ||
            prevRect.width !== newRect.width ||
            prevRect.height !== newRect.height
          ) {
            return newRect;
          }
          return prevRect;
        });
      });
    }
  }, []);

  useEffect(() => {
    elementsRef.current = elements;
    recalculateBorder();
  }, [elements, recalculateBorder]);

  const init = useCallback(() => {
    // Disconnect existing observers
    resizeObserverRef.current?.disconnect();
    mutationObserverRef.current?.disconnect();

    resizeObserverRef.current = new ResizeObserver((entries) => {
      entries.forEach(() => {
        recalculateBorder();
      });
    });

    mutationObserverRef.current = new MutationObserver((mutationsList) => {
      mutationsList.forEach((mutation) => {
        // Calculate the borders immediately
        recalculateBorder();
        if (
          mutation.type === 'attributes' &&
          mutation.attributeName === 'style'
        ) {
          const target = mutation.target;
          // Calculate borders again after transitionEnd event to take into account the final result/position of css animations
          target.addEventListener('transitionend', recalculateBorder, {
            once: true,
          });
        }
      });
    });

    elementsRef.current?.forEach((element) => {
      /**
       * <astro-island> elements (XB Code Components) are display: inline; and that means you can't observe them with
       * resizeObserver. Here, if the element we're syncing with can't be observed we traverse up the DOM to find the
       * first parent that can be and watch that instead
       */
      if (isElementObservable(element)) {
        resizeObserverRef.current?.observe(element);
      } else {
        // Traverse up to find an observable parent
        let parent = element.parentElement;
        while (parent && !isElementObservable(parent)) {
          parent = parent.parentElement;
        }

        if (parent) {
          resizeObserverRef.current?.observe(parent);
        } else {
          console.warn(
            'Element size cannot be observed because it does not have a valid/observable content rect.',
          );
        }
      }

      // Observe mutations on the body to account for other element's updating that may affect the position of this one
      const parentBody = findParentBody(element);
      if (parentBody) {
        mutationObserverRef.current?.observe(parentBody, {
          attributes: true,
          childList: true,
          subtree: true,
        });
      }
    });
  }, [recalculateBorder]);

  useEffect(() => {
    if (elements?.length) {
      init();
    }

    return () => {
      // Cleanup observers
      resizeObserverRef.current?.disconnect();
      mutationObserverRef.current?.disconnect();
    };
  }, [init, elements]);

  // Use useMemo to return a stable reference
  return useMemo(() => elementRect, [elementRect]);
}

export default useSyncPreviewElementSize;
