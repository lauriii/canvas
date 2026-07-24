// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  HEADLESS_HEIGHT_MESSAGE,
  HEADLESS_HEIGHT_PROBE_MESSAGE,
  HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
  HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
} from '../constants';
import { createHeightReporter } from './height-report';

import type { HeightReporter, HeightReporterOptions } from './height-report';

const ORIGIN = 'https://drupal.example';
const reporters: HeightReporter[] = [];

/**
 * Creates a reporter wired to mock Resize/MutationObservers and a spy
 * hostWindow, running against the real document (jsdom) so
 * measureDocumentHeight's style rewrite runs for real. jsdom does no
 * layout, so document.documentElement.offsetHeight is stubbed to a
 * controllable value in place of a real rendered height.
 */
function makeHarness(
  overrides: Partial<HeightReporterOptions> = {},
  config: {
    readDocumentHeight?: () => number;
    onPostMessage?: (
      message: unknown,
      hostWindow: Pick<Window, 'postMessage'>,
      triggerResize: (height: number) => void,
    ) => void;
  } = {},
) {
  const hostWindow = { postMessage: vi.fn() };
  const { postMessage } = hostWindow;
  postMessage.mockImplementation((message: unknown) => {
    config.onPostMessage?.(message, hostWindow, triggerResize);
  });
  let documentHeight = 100;
  vi.spyOn(document.documentElement, 'offsetHeight', 'get').mockImplementation(
    () => config.readDocumentHeight?.() ?? documentHeight,
  );

  let observeCallbacks: Array<() => void> = [];
  const disconnect = vi.fn(() => {
    observeCallbacks = [];
  });
  const observe = vi.fn();

  class MockResizeObserver {
    constructor(callback: () => void) {
      observeCallbacks.push(callback);
    }
    observe = observe;
    disconnect = disconnect;
    unobserve = vi.fn();
  }
  vi.stubGlobal('ResizeObserver', MockResizeObserver);

  const activeMutationCallbacks = new Set<() => void>();
  const mutationDisconnect = vi.fn();
  const mutationObserve = vi.fn();

  class MockMutationObserver {
    #callback: () => void;
    constructor(callback: () => void) {
      this.#callback = callback;
    }
    observe = vi.fn((...args: unknown[]) => {
      mutationObserve(...args);
      activeMutationCallbacks.add(this.#callback);
    });
    disconnect = vi.fn(() => {
      activeMutationCallbacks.delete(this.#callback);
      mutationDisconnect();
    });
    takeRecords = vi.fn(() => []);
  }
  vi.stubGlobal('MutationObserver', MockMutationObserver);

  const triggerResize = (height: number) => {
    documentHeight = height;
    for (const callback of observeCallbacks) {
      callback();
    }
  };

  const options: HeightReporterOptions = {
    editorOrigin: ORIGIN,
    embedded: true,
    hostWindow,
    ...overrides,
  };

  const reporter = createHeightReporter(options);
  reporters.push(reporter);

  const triggerMutation = (height: number) => {
    documentHeight = height;
    // Snapshot before iterating: postHeight disconnects and re-observes the
    // same MutationObserver instance synchronously (to shield its own
    // measurement writes), which would otherwise mutate this Set live and
    // cause a callback removed-then-re-added mid-iteration to be revisited.
    for (const callback of [...activeMutationCallbacks]) {
      callback();
    }
  };

  return {
    reporter,
    hostWindow,
    postMessage,
    observe,
    mutationObserve,
    disconnect,
    mutationDisconnect,
    triggerResize,
    triggerMutation,
  };
}

afterEach(() => {
  for (const reporter of reporters.splice(0)) {
    reporter.destroy();
  }
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.documentElement.removeAttribute('style');
  document.body.removeAttribute('style');
  document.body.innerHTML = '';
});

describe('createHeightReporter', () => {
  it('reports the initial height to the signed editor origin', async () => {
    const { postMessage } = makeHarness();
    await vi.waitFor(() => {
      expect(postMessage).toHaveBeenCalledWith(
        { type: HEADLESS_HEIGHT_MESSAGE, height: 100 },
        ORIGIN,
      );
    });
  });

  it('re-reports height on every observed resize', async () => {
    const { postMessage, triggerResize } = makeHarness();
    await vi.waitFor(() => expect(postMessage).toHaveBeenCalled());
    postMessage.mockClear();

    triggerResize(250);

    await vi.waitFor(() => {
      expect(postMessage).toHaveBeenCalledWith(
        { type: HEADLESS_HEIGHT_MESSAGE, height: 250 },
        ORIGIN,
      );
    });
  });

  it('re-reports height on every observed mutation, catching growth ResizeObserver misses (e.g. viewport-relative CSS pinning the box)', async () => {
    const { postMessage, triggerMutation } = makeHarness();
    await vi.waitFor(() => expect(postMessage).toHaveBeenCalled());
    postMessage.mockClear();

    triggerMutation(300);

    await vi.waitFor(() => {
      expect(postMessage).toHaveBeenCalledWith(
        { type: HEADLESS_HEIGHT_MESSAGE, height: 300 },
        ORIGIN,
      );
    });
  });

  it('uses host-assisted probes and keeps the confirmed height pinned', async () => {
    const section = document.createElement('section');
    section.style.height = '150vh';
    document.body.appendChild(section);

    let viewportHeight = 500;
    vi.spyOn(window, 'innerHeight', 'get').mockImplementation(
      () => viewportHeight,
    );
    Object.defineProperty(section, 'clientHeight', {
      configurable: true,
      get: () => {
        if (/^\d+px$/.test(section.style.height)) {
          return parseInt(section.style.height, 10);
        }
        if (section.style.height === 'auto') {
          return 0;
        }
        return Math.round(viewportHeight * 1.5);
      },
    });

    const { hostWindow, postMessage } = makeHarness(
      {},
      {
        readDocumentHeight: () => section.clientHeight,
        onPostMessage: (message, hostWindow, triggerResize) => {
          if (
            typeof message !== 'object' ||
            message === null ||
            !('type' in message) ||
            message.type !== HEADLESS_HEIGHT_PROBE_MESSAGE ||
            !('id' in message) ||
            typeof message.id !== 'string' ||
            !('height' in message)
          ) {
            return;
          }
          viewportHeight =
            typeof message.height === 'number' ? message.height : 500;
          // The real document root resizes when the host applies every probe
          // height. Those observer notifications must not queue another pass.
          triggerResize(section.clientHeight);
          queueMicrotask(() => {
            window.dispatchEvent(
              new MessageEvent('message', {
                origin: ORIGIN,
                source: hostWindow as unknown as Window,
                data: {
                  type: HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
                  id: message.id,
                  height: message.height,
                },
              }),
            );
          });
        },
      },
    );

    await vi.waitFor(() => {
      expect(postMessage).toHaveBeenCalledWith(
        { type: HEADLESS_HEIGHT_MESSAGE, height: 750 },
        ORIGIN,
      );
    });
    expect(section.style.height).toBe('750px');
    expect(
      postMessage.mock.calls
        .map(([message]) => message)
        .filter(
          (message) =>
            typeof message === 'object' &&
            message !== null &&
            'type' in message &&
            message.type === HEADLESS_HEIGHT_PROBE_MESSAGE,
        )
        .map((message) =>
          typeof message === 'object' && message !== null && 'height' in message
            ? message.height
            : undefined,
        ),
    ).toEqual([1500, 4000, null]);

    postMessage.mockClear();
    viewportHeight = 600;
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: ORIGIN,
        source: hostWindow as unknown as Window,
        data: {
          type: HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
          height: 600,
        },
      }),
    );

    await vi.waitFor(() => {
      expect(postMessage).toHaveBeenCalledWith(
        { type: HEADLESS_HEIGHT_MESSAGE, height: 900 },
        ORIGIN,
      );
    });
    expect(section.style.height).toBe('900px');
    expect(
      postMessage.mock.calls
        .map(([message]) => message)
        .filter(
          (message) =>
            typeof message === 'object' &&
            message !== null &&
            'type' in message &&
            message.type === HEADLESS_HEIGHT_PROBE_MESSAGE,
        )
        .map((message) =>
          typeof message === 'object' && message !== null && 'height' in message
            ? message.height
            : undefined,
        ),
    ).toEqual([1800, 4800, null]);
  });

  it('posts nothing when standalone', () => {
    const { postMessage, triggerResize } = makeHarness({ embedded: false });
    triggerResize(250);
    expect(postMessage).not.toHaveBeenCalled();
  });

  it('posts nothing without a signed editor origin', () => {
    const { postMessage, triggerResize } = makeHarness({ editorOrigin: null });
    triggerResize(250);
    expect(postMessage).not.toHaveBeenCalled();
  });

  it('disconnects both observers on destroy()', () => {
    const { reporter, disconnect, mutationDisconnect } = makeHarness();

    reporter.destroy();

    expect(disconnect).toHaveBeenCalled();
    expect(mutationDisconnect).toHaveBeenCalled();
  });
});
