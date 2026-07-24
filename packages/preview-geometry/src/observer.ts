import { discoverCanvasBoundaries } from './markers';
import { measureCanvasGeometry } from './measure';

// cspell:ignore loadingdone

import type {
  CanvasBoundary,
  CanvasGeometryObserver,
  CanvasGeometryObserverOptions,
  CanvasGeometryRoot,
} from './types';

/**
 * Observes one preview document and emits batched geometry snapshots when its
 * layout can have changed.
 */
export function createCanvasGeometryObserver(
  options: CanvasGeometryObserverOptions,
): CanvasGeometryObserver {
  const { root, onChange, ...measurementOptions } = options;
  const document = getOwnerDocument(root);
  const view = document.defaultView;
  if (!view) {
    throw new Error('Canvas geometry observation requires a browser window.');
  }

  let disconnected = false;
  let scheduledFrame: number | null = null;
  let previousSnapshot: string | null = null;
  let forceNextSnapshot = false;

  const measure = () => measureCanvasGeometry(root, measurementOptions);
  const emit = () => {
    scheduledFrame = null;
    if (disconnected) {
      return;
    }
    const forceSnapshot = forceNextSnapshot;
    forceNextSnapshot = false;
    const geometry = measure();
    const snapshot = JSON.stringify(geometry);
    if (forceSnapshot || snapshot !== previousSnapshot) {
      previousSnapshot = snapshot;
      onChange(geometry);
    }
  };
  const scheduleRefresh = (forceSnapshot = false) => {
    if (disconnected) {
      return;
    }
    forceNextSnapshot ||= forceSnapshot;
    if (scheduledFrame !== null) {
      return;
    }
    scheduledFrame = view.requestAnimationFrame(emit);
  };
  const refresh = () => scheduleRefresh();

  const resizeObserver =
    typeof view.ResizeObserver === 'function'
      ? new view.ResizeObserver(refresh)
      : null;
  const refreshResizeTargets = () => {
    resizeObserver?.disconnect();
    const boundaries = discoverCanvasBoundaries(root, measurementOptions);
    collectResizeTargets(root, boundaries).forEach((element) => {
      resizeObserver?.observe(element);
    });
  };
  refreshResizeTargets();

  const mutationObserver = new view.MutationObserver((mutations) => {
    if (mutations.some((mutation) => mutation.type === 'childList')) {
      refreshResizeTargets();
    }
    // A content refresh can leave every rectangle unchanged. Emit a fresh
    // snapshot so consumers can still treat the mutation as completion.
    scheduleRefresh(true);
  });
  mutationObserver.observe(root, {
    attributes: true,
    characterData: true,
    childList: true,
    subtree: true,
  });

  document.addEventListener('scroll', refresh, true);
  document.addEventListener('animationend', refresh, true);
  document.addEventListener('transitionend', refresh, true);
  view.addEventListener('resize', refresh);
  document.fonts?.addEventListener('loadingdone', refresh);

  emit();

  return {
    measure,
    refresh,
    disconnect: () => {
      if (disconnected) {
        return;
      }
      disconnected = true;
      mutationObserver.disconnect();
      resizeObserver?.disconnect();
      document.removeEventListener('scroll', refresh, true);
      document.removeEventListener('animationend', refresh, true);
      document.removeEventListener('transitionend', refresh, true);
      view.removeEventListener('resize', refresh);
      document.fonts?.removeEventListener('loadingdone', refresh);
      if (scheduledFrame !== null) {
        view.cancelAnimationFrame(scheduledFrame);
        scheduledFrame = null;
      }
    },
  };
}

function getOwnerDocument(root: CanvasGeometryRoot): Document {
  if (root.nodeType === Node.DOCUMENT_NODE) {
    return root as Document;
  }
  if (!root.ownerDocument) {
    throw new Error('Canvas geometry root must belong to a document.');
  }
  return root.ownerDocument;
}

function collectResizeTargets(
  root: CanvasGeometryRoot,
  boundaries: CanvasBoundary[],
): Element[] {
  const targets = new Set<Element>();
  if (root.nodeType === Node.ELEMENT_NODE) {
    addObservableResizeTarget(targets, root as Element);
  } else if (root.nodeType === Node.DOCUMENT_NODE) {
    const rootDocument = root as Document;
    targets.add(rootDocument.documentElement);
    if (rootDocument.body) {
      targets.add(rootDocument.body);
    }
  }

  boundaries.forEach((boundary) => {
    const parent = boundary.start.parentElement;
    if (parent && parent === boundary.end.parentElement) {
      addObservableResizeTarget(targets, parent);
      let node = boundary.start.nextSibling;
      while (node && node !== boundary.end) {
        if (node.nodeType === Node.ELEMENT_NODE) {
          addObservableResizeTarget(targets, node as Element);
        }
        node = node.nextSibling;
      }
    }
  });

  return Array.from(targets);
}

/** Adds the first observable resize target at or above a rendered element. */
function addObservableResizeTarget(
  targets: Set<Element>,
  element: Element,
): void {
  let target: Element | null = element;
  while (target && !isElementObservable(target)) {
    target = target.parentElement;
  }
  if (target) {
    targets.add(target);
  }
}

function isElementObservable(element: Element): boolean {
  const view = element.ownerDocument.defaultView;
  if (!view || view.getComputedStyle(element).display === 'contents') {
    return false;
  }

  const offsetWidth =
    'offsetWidth' in element && typeof element.offsetWidth === 'number'
      ? element.offsetWidth
      : 0;
  const offsetHeight =
    'offsetHeight' in element && typeof element.offsetHeight === 'number'
      ? element.offsetHeight
      : 0;
  return Boolean(
    offsetWidth || offsetHeight || element.getClientRects().length,
  );
}
