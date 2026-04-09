import { act } from 'react';
import { renderSpec } from 'drupal-canvas/json-render-utils';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { PreviewFrameApp } from './PreviewFrameApp';

import type { ReactNode } from 'react';
import type { Root } from 'react-dom/client';
import type { PreviewRenderRequest } from '@wb/lib/preview-contract';

/** @vitest-environment jsdom */

vi.mock('drupal-canvas/json-render-utils', () => ({
  defineComponentRegistry: vi.fn().mockResolvedValue({}),
  renderSpec: vi.fn(() => 'rendered'),
}));

vi.mock('lucide-react', () => ({
  CircleAlertIcon: () => null,
}));

vi.mock('@wb/client/components/ui/alert', () => ({
  Alert: ({ children }: { children?: ReactNode }) => (
    <div data-testid="alert">{children}</div>
  ),
  AlertTitle: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
  AlertDescription: ({ children }: { children?: ReactNode }) => (
    <div>{children}</div>
  ),
}));

const validSpec = {
  root: 'root',
  elements: {
    root: {
      type: 'js.hero',
      props: {},
    },
  },
};

function makeRequest(
  renderId: string,
  renderType: 'page' | 'component',
): PreviewRenderRequest {
  return {
    source: 'canvas-workbench-parent',
    type: 'preview:render',
    payload: {
      renderId,
      renderType,
      spec: validSpec,
      registrySources: [],
      cssUrls: [],
    },
  };
}

async function dispatchRenderRequest(
  request: PreviewRenderRequest,
  renderSpecMock: ReturnType<typeof vi.mocked<typeof renderSpec>>,
) {
  const renderCallsBefore = renderSpecMock.mock.calls.length;
  await act(async () => {
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'http://localhost',
        data: request,
      }),
    );
    for (
      let i = 0;
      i < 50 && renderSpecMock.mock.calls.length === renderCallsBefore;
      i++
    ) {
      await Promise.resolve();
    }
    expect(renderSpecMock.mock.calls.length).toBe(renderCallsBefore + 1);
  });
}

describe('PreviewFrameApp', () => {
  let container: HTMLDivElement;
  let root: Root;
  let scrollToSpy: ReturnType<typeof vi.spyOn>;
  /** Updated by the `scrollTo` mock so tests can assert iframe-style scroll position. */
  let simulatedScrollY: number;
  const renderSpecMock = vi.mocked(renderSpec);

  beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    simulatedScrollY = 0;
    scrollToSpy = vi.spyOn(window, 'scrollTo').mockImplementation((x, y) => {
      if (typeof x === 'number' && typeof y === 'number') {
        simulatedScrollY = y;
      }
    });
  });

  afterEach(() => {
    void act(() => {
      root?.unmount();
    });
    container?.remove();
    document.body.replaceChildren();
    vi.restoreAllMocks();
  });

  it('scrolls to top when the preview target changes, not when the same target repeats', async () => {
    await act(async () => {
      root = createRoot(container);
      root.render(<PreviewFrameApp />);
    });

    await dispatchRenderRequest(makeRequest('page-a', 'page'), renderSpecMock);

    expect(scrollToSpy).toHaveBeenCalledWith(0, 0);

    const countAfterFirstTarget = scrollToSpy.mock.calls.length;

    await dispatchRenderRequest(makeRequest('page-a', 'page'), renderSpecMock);

    expect(scrollToSpy.mock.calls.length).toBe(countAfterFirstTarget);

    await dispatchRenderRequest(makeRequest('page-b', 'page'), renderSpecMock);

    expect(scrollToSpy.mock.calls.length).toBeGreaterThan(
      countAfterFirstTarget,
    );
    expect(scrollToSpy).toHaveBeenLastCalledWith(0, 0);
  });

  it('keeps scroll position when the same preview target updates again', async () => {
    await act(async () => {
      root = createRoot(container);
      root.render(<PreviewFrameApp />);
    });

    await dispatchRenderRequest(makeRequest('page-a', 'page'), renderSpecMock);
    expect(simulatedScrollY).toBe(0);

    window.scrollTo(0, 180);
    expect(simulatedScrollY).toBe(180);

    const scrollToCallsAfterUserScroll = scrollToSpy.mock.calls.length;

    await dispatchRenderRequest(makeRequest('page-a', 'page'), renderSpecMock);

    expect(simulatedScrollY).toBe(180);
    expect(scrollToSpy.mock.calls.length).toBe(scrollToCallsAfterUserScroll);
  });
});
