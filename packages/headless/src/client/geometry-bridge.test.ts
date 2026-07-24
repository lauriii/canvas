// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  HEADLESS_GEOMETRY_MESSAGE,
  HEADLESS_GEOMETRY_REQUEST_MESSAGE,
} from '../constants';
import { createCanvasGeometryBridge } from './geometry-bridge';

const HOST_SESSION_ID = 'host-session';

describe('createCanvasGeometryBridge', () => {
  afterEach(() => {
    document.body.replaceChildren();
    vi.restoreAllMocks();
  });

  it('accepts only the configured host and bridges geometry', async () => {
    document.body.innerHTML = `
      <!-- canvas-region-start-content -->
      <!-- canvas-start-card-one -->
      <article>Card</article>
      <!-- canvas-end-card-one -->
      <!-- canvas-region-end-content -->
    `;
    const article = document.querySelector('article')!;
    vi.spyOn(article, 'getClientRects').mockReturnValue([
      new DOMRect(10, 20, 100, 40),
    ] as unknown as DOMRectList);
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
      callback(0);
      return 1;
    });

    const hostWindow = {
      postMessage: vi.fn(),
    };
    const otherWindow = { postMessage: vi.fn() };
    const bridge = createCanvasGeometryBridge({
      editorOrigin: 'https://drupal.example',
      root: document,
      hostWindow,
      listenerTarget: window,
    });

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://evil.example',
        source: hostWindow as unknown as MessageEventSource,
        data: { type: HEADLESS_GEOMETRY_REQUEST_MESSAGE },
      }),
    );
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://drupal.example',
        source: otherWindow as unknown as MessageEventSource,
        data: { type: HEADLESS_GEOMETRY_REQUEST_MESSAGE },
      }),
    );
    expect(hostWindow.postMessage).not.toHaveBeenCalled();

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://drupal.example',
        source: hostWindow as unknown as MessageEventSource,
        data: {
          type: HEADLESS_GEOMETRY_REQUEST_MESSAGE,
          hostSessionId: HOST_SESSION_ID,
        },
      }),
    );

    await vi.waitFor(() => {
      expect(
        hostWindow.postMessage.mock.calls.find(
          ([message]) =>
            (message as { type?: string }).type === HEADLESS_GEOMETRY_MESSAGE,
        ),
      ).toEqual([
        {
          type: HEADLESS_GEOMETRY_MESSAGE,
          hostSessionId: HOST_SESSION_ID,
          geometry: expect.arrayContaining([
            expect.objectContaining({
              type: 'component',
              id: 'card-one',
              componentUuid: 'card-one',
              markerFormat: 'comment',
              rect: {
                top: 20,
                right: 110,
                bottom: 60,
                left: 10,
                width: 100,
                height: 40,
              },
            }),
          ]),
        },
        'https://drupal.example',
      ]);
    });

    const geometryMessagesBeforeRequest =
      hostWindow.postMessage.mock.calls.filter(
        ([message]) =>
          (message as { type?: string }).type === HEADLESS_GEOMETRY_MESSAGE,
      ).length;
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://drupal.example',
        source: hostWindow as unknown as MessageEventSource,
        data: {
          type: HEADLESS_GEOMETRY_REQUEST_MESSAGE,
          hostSessionId: HOST_SESSION_ID,
        },
      }),
    );
    expect(
      hostWindow.postMessage.mock.calls.filter(
        ([message]) =>
          (message as { type?: string }).type === HEADLESS_GEOMETRY_MESSAGE,
      ),
    ).toHaveLength(geometryMessagesBeforeRequest + 1);

    bridge.destroy();
  });
});
