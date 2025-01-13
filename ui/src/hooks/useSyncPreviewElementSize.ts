import { useEffect, useState, useCallback, useRef, useMemo } from 'react';

/**
 * This hook takes an HTML element or array of HTML elements and returns a state containing the elements dimensions and position.
 * It uses a mutation observer and resize observer to ensure that even if the element changes size or position at any point
 * the returned values are updated.
 *
 * TODO reinstate the mutation observer if I can find a need for it?
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

  const elementsRef = useRef<HTMLElement[] | null>(null);

  const recalculateBorder = useCallback(() => {
    const tops: number[] = [];
    const lefts: number[] = [];
    const rights: number[] = [];
    const bottoms: number[] = [];

    elementsRef.current?.forEach(function (el, i) {
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

    if (elementsRef.current) {
      setElementRect({
        top: minTop,
        left: minLeft,
        width: getMaxOfArray(rights) - minLeft,
        height: getMaxOfArray(bottoms) - minTop,
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

    resizeObserverRef.current = new ResizeObserver((entries) => {
      entries.forEach(() => {
        recalculateBorder();
      });
    });
    elementsRef.current?.forEach((element) => {
      resizeObserverRef.current?.observe(element);
    });
  }, [recalculateBorder]);

  useEffect(() => {
    if (elements?.length) {
      init();
    }

    return () => {
      // Cleanup observers
      resizeObserverRef.current?.disconnect();
    };
  }, [init, elements]);

  return elementRect;
}

export default useSyncPreviewElementSize;
