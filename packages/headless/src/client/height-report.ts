/**
 * @file
 * The app's side of content-height reporting. It tells the editing host how
 * tall the embedded app's rendered content currently is, so the host can size
 * the preview iframe to fit.
 * The app reports final heights and uses a short request/acknowledgement
 * exchange when the shared reader needs temporary viewport heights.
 *
 * Both a ResizeObserver and a MutationObserver are used: viewport-relative
 * CSS (h-full, min-h-screen, vh units) can pin an element's rendered box to
 * whatever height the host last applied, so content growing past it changes
 * scrollHeight without firing ResizeObserver. MutationObserver on the
 * subtree catches that case.
 */

import { StableHeightReader } from '@drupal-canvas/height-reader';

import {
  HEADLESS_HEIGHT_MESSAGE,
  HEADLESS_HEIGHT_PROBE_MESSAGE,
  HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
  HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
} from '../constants';

const PROBE_TIMEOUT_MS = 2_000;

export interface HeightReporterOptions {
  /** The signed editor origin used as the postMessage peer. */
  editorOrigin: string | null;
  /**
   * Whether the document is embedded in an iframe. Standalone, there is no
   * host to report to, so the reporter no-ops.
   */
  embedded: boolean;
  /** The window height reports are posted to. Default: the parent. */
  hostWindow?: Pick<Window, 'postMessage'>;
}

export interface HeightReporter {
  /** Disconnects the observer. Safe to call more than once. */
  destroy(): void;
}

/** Creates the app side of document-height reporting. */
export function createHeightReporter(
  options: HeightReporterOptions,
): HeightReporter {
  const {
    editorOrigin,
    embedded,
    hostWindow = typeof window === 'undefined' ? undefined : window.parent,
  } = options;

  const target =
    typeof document === 'undefined' ? undefined : document.documentElement;

  if (!embedded || !target || typeof ResizeObserver === 'undefined') {
    return { destroy: () => {} };
  }
  const resolvedTarget = target;
  const resolvedDocument = resolvedTarget.ownerDocument;
  const resolvedWindow = resolvedDocument.defaultView;
  const stableHeightReader = new StableHeightReader();
  let baseViewportHeight =
    resolvedWindow?.innerHeight ?? resolvedTarget.clientHeight;
  let destroyed = false;
  let measuring = false;
  let measureAgain = false;
  let probeActive = false;
  let probeReleaseFrame: number | null = null;
  let nextProbeId = 0;
  const pendingProbes = new Map<
    string,
    {
      resolve: () => void;
      reject: (reason: Error) => void;
      restoresViewport: boolean;
      timeout: number;
    }
  >();

  const mutationOptions: MutationObserverInit = {
    childList: true,
    subtree: true,
    attributes: true,
    characterData: true,
  };
  const mutationObserver = new MutationObserver(scheduleHeight);

  const resizeObserver = new ResizeObserver(() => {
    // Host-assisted probes intentionally resize the observed document root.
    // Those changes are part of the current measurement, not new content
    // changes that should queue another probe pass.
    if (!probeActive) {
      scheduleHeight();
    }
  });

  function releaseProbeAfterLayout() {
    if (
      !resolvedWindow ||
      typeof resolvedWindow.requestAnimationFrame !== 'function'
    ) {
      probeActive = false;
      return;
    }
    if (probeReleaseFrame !== null) {
      resolvedWindow.cancelAnimationFrame(probeReleaseFrame);
    }
    probeReleaseFrame = resolvedWindow.requestAnimationFrame(() => {
      probeReleaseFrame = null;
      probeActive = false;
    });
  }

  function requestProbeHeight(height: number | null): Promise<void> {
    return new Promise((resolve, reject) => {
      if (!editorOrigin || !hostWindow || destroyed) {
        reject(new Error('The height probe host is unavailable.'));
        return;
      }

      const id = `height-probe-${++nextProbeId}`;
      const timeout = resolvedWindow?.setTimeout(() => {
        pendingProbes.delete(id);
        if (height === null) {
          releaseProbeAfterLayout();
        }
        reject(new Error('The height probe host did not respond.'));
      }, PROBE_TIMEOUT_MS);

      if (timeout === undefined) {
        reject(new Error('The height probe window is unavailable.'));
        return;
      }

      if (height !== null) {
        if (probeReleaseFrame !== null) {
          resolvedWindow?.cancelAnimationFrame(probeReleaseFrame);
          probeReleaseFrame = null;
        }
        probeActive = true;
      }
      pendingProbes.set(id, {
        resolve,
        reject,
        restoresViewport: height === null,
        timeout,
      });
      hostWindow.postMessage(
        { type: HEADLESS_HEIGHT_PROBE_MESSAGE, id, height },
        editorOrigin,
      );
    });
  }

  function handleHostMessage(event: MessageEvent) {
    if (
      event.origin !== editorOrigin ||
      event.source !== (hostWindow as unknown as MessageEventSource)
    ) {
      return;
    }

    if (event.data?.type === HEADLESS_VIEWPORT_HEIGHT_MESSAGE) {
      const { height } = event.data;
      if (
        typeof height === 'number' &&
        Number.isFinite(height) &&
        height > 0 &&
        height !== baseViewportHeight
      ) {
        baseViewportHeight = height;
        stableHeightReader.clear();
        scheduleHeight();
      }
      return;
    }

    if (
      event.data?.type !== HEADLESS_HEIGHT_PROBE_READY_MESSAGE ||
      typeof event.data.id !== 'string'
    ) {
      return;
    }

    const pending = pendingProbes.get(event.data.id);
    if (!pending) {
      return;
    }
    pendingProbes.delete(event.data.id);
    resolvedWindow?.clearTimeout(pending.timeout);
    if (pending.restoresViewport) {
      // Keep probe resizes suppressed through the next layout. Depending on
      // frame scheduling, the ResizeObserver notification for restoration may
      // arrive after this acknowledgement message.
      releaseProbeAfterLayout();
    }
    pending.resolve();
  }

  async function measureAndPostHeight() {
    if (!editorOrigin || destroyed) {
      return;
    }
    if (measuring) {
      measureAgain = true;
      return;
    }

    measuring = true;
    try {
      do {
        measureAgain = false;
        // Pinning writes style attributes inside the watched subtree. Pause
        // mutation observation so those writes are not treated as app changes.
        mutationObserver.disconnect();
        try {
          const height = await stableHeightReader.measureDocumentHeight(
            resolvedDocument,
            {
              baseViewportHeight,
              probeController: {
                setViewportHeight: requestProbeHeight,
                restoreViewportHeight: () => requestProbeHeight(null),
              },
            },
          );
          if (!destroyed) {
            hostWindow?.postMessage(
              { type: HEADLESS_HEIGHT_MESSAGE, height },
              editorOrigin,
            );
          }
        } catch {
          // A mismatched or older host may not support probing. Continue to
          // report the unpinned document height rather than stopping sync.
          const height =
            await stableHeightReader.measureDocumentHeight(resolvedDocument);
          if (!destroyed) {
            hostWindow?.postMessage(
              { type: HEADLESS_HEIGHT_MESSAGE, height },
              editorOrigin,
            );
          }
        } finally {
          if (!destroyed) {
            mutationObserver.observe(resolvedTarget, mutationOptions);
          }
        }
      } while (measureAgain && !destroyed);
    } finally {
      measuring = false;
    }
  }

  function scheduleHeight() {
    void measureAndPostHeight();
  }

  resolvedWindow?.addEventListener('message', handleHostMessage);
  resizeObserver.observe(target);
  mutationObserver.observe(target, mutationOptions);

  scheduleHeight();

  return {
    destroy: () => {
      if (destroyed) {
        return;
      }
      if (probeActive && editorOrigin) {
        hostWindow?.postMessage(
          {
            type: HEADLESS_HEIGHT_PROBE_MESSAGE,
            id: `height-probe-${++nextProbeId}`,
            height: null,
          },
          editorOrigin,
        );
      }
      destroyed = true;
      probeActive = false;
      if (probeReleaseFrame !== null) {
        resolvedWindow?.cancelAnimationFrame(probeReleaseFrame);
        probeReleaseFrame = null;
      }
      resizeObserver.disconnect();
      mutationObserver.disconnect();
      resolvedWindow?.removeEventListener('message', handleHostMessage);
      for (const pending of pendingProbes.values()) {
        resolvedWindow?.clearTimeout(pending.timeout);
        pending.reject(new Error('The height reporter was destroyed.'));
      }
      pendingProbes.clear();
      stableHeightReader.clear();
    },
  };
}
