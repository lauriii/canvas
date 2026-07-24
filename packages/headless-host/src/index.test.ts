// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  HEADLESS_HEIGHT_MESSAGE,
  HEADLESS_HEIGHT_PROBE_MESSAGE,
  HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
  HEADLESS_STATUS_MESSAGE,
  HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
} from '@drupal-canvas/headless';

import { createHeadlessPreviewHost } from './index';

const FRONTEND_ORIGIN = 'https://frontend.example';

function createHarness({ active = true }: { active?: boolean } = {}) {
  const iframe = document.createElement('iframe');
  iframe.style.height = '500px';
  iframe.style.visibility = 'visible';
  document.body.appendChild(iframe);

  const postMessage = vi.spyOn(iframe.contentWindow!, 'postMessage');
  vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
    callback(0);
    return 1;
  });
  vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(() => {});
  const onHeight = vi.fn();
  const host = createHeadlessPreviewHost({
    iframe,
    frontendOrigin: FRONTEND_ORIGIN,
    draftUrl: `${FRONTEND_ORIGIN}/draft`,
    fetchAssertion: vi.fn(),
    onHeight,
  });

  const send = (data: unknown) => {
    window.dispatchEvent(
      new MessageEvent('message', {
        data,
        origin: FRONTEND_ORIGIN,
        source: iframe.contentWindow,
      }),
    );
  };

  if (active) {
    send({
      type: HEADLESS_STATUS_MESSAGE,
      status: 'active',
      path: '/',
      tokenExpiresAt: Date.now() + 60_000,
    });
  }

  return { host, iframe, onHeight, postMessage, send };
}

afterEach(() => {
  document.body.innerHTML = '';
  vi.restoreAllMocks();
});

describe('headless height probing', () => {
  it('ignores sizing messages from the previous document while inactive', () => {
    const { host, iframe, onHeight, postMessage, send } = createHarness({
      active: false,
    });

    send({ type: HEADLESS_HEIGHT_MESSAGE, height: 1200 });
    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'stale-probe',
      height: 1500,
    });

    expect(onHeight).not.toHaveBeenCalled();
    expect(iframe.style.height).toBe('500px');
    expect(postMessage).not.toHaveBeenCalled();

    host.destroy();
  });

  it('temporarily applies probe heights and restores the iframe', () => {
    const { host, iframe, postMessage, send } = createHarness();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-1',
      height: 1500,
    });

    expect(iframe.style.height).toBe('1500px');
    expect(iframe.style.visibility).toBe('hidden');
    expect(postMessage).toHaveBeenLastCalledWith(
      {
        type: HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
        id: 'probe-1',
        height: 1500,
      },
      FRONTEND_ORIGIN,
    );

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-2',
      height: null,
    });

    expect(iframe.style.height).toBe('500px');
    expect(iframe.style.visibility).toBe('visible');
    expect(postMessage).toHaveBeenLastCalledWith(
      {
        type: HEADLESS_HEIGHT_PROBE_READY_MESSAGE,
        id: 'probe-2',
        height: null,
      },
      FRONTEND_ORIGIN,
    );

    host.destroy();
  });

  it('preserves a height committed by the embedder during a probe', () => {
    const { host, iframe, send } = createHarness();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-1',
      height: 1500,
    });

    // Simulate a declarative UI committing a reported final height while the
    // host temporarily owns the iframe's height for probing.
    iframe.style.height = '1200px';

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-2',
      height: 4000,
    });
    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-3',
      height: null,
    });

    expect(iframe.style.height).toBe('1200px');

    host.destroy();
  });

  it('ignores final height reports while a probe is active', () => {
    const { host, onHeight, send } = createHarness();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-1',
      height: 1500,
    });
    send({ type: HEADLESS_HEIGHT_MESSAGE, height: 1500 });

    expect(onHeight).not.toHaveBeenCalled();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-2',
      height: null,
    });
    send({ type: HEADLESS_HEIGHT_MESSAGE, height: 750 });

    expect(onHeight).toHaveBeenCalledWith(750);

    host.destroy();
  });

  it('restores the iframe when the host is destroyed during a probe', () => {
    const { host, iframe, send } = createHarness();

    send({
      type: HEADLESS_HEIGHT_PROBE_MESSAGE,
      id: 'probe-1',
      height: 1500,
    });
    host.destroy();

    expect(iframe.style.height).toBe('500px');
    expect(iframe.style.visibility).toBe('visible');
  });

  it('sends the selected viewport height after the new document is active', () => {
    const { host, postMessage, send } = createHarness({ active: false });

    host.setViewportHeight(800);
    expect(postMessage).not.toHaveBeenCalled();

    send({
      type: HEADLESS_STATUS_MESSAGE,
      status: 'active',
      path: '/about',
      tokenExpiresAt: Date.now() + 60_000,
    });

    expect(postMessage).toHaveBeenLastCalledWith(
      {
        type: HEADLESS_VIEWPORT_HEIGHT_MESSAGE,
        height: 800,
      },
      FRONTEND_ORIGIN,
    );

    host.destroy();
  });
});
