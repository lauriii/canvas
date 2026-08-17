import { act } from 'react';
import { renderSpec } from 'drupal-canvas/json-render-utils';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { PreviewFrameApp } from './PreviewFrameApp';

import type { ReactNode } from 'react';
import type { Root } from 'react-dom/client';
import type {
  PreviewRenderCanvasData,
  PreviewRenderRequest,
} from '@wb/lib/preview-contract';

/** @vitest-environment jsdom */

vi.mock('@wb/lib/discovery-client', () => ({
  fetchDiscoveryResult: vi.fn().mockResolvedValue({
    componentRoot: 'components',
    projectRoot: '/proj',
    components: [],
    pages: [
      {
        name: 'Test',
        slug: 'about',
        uuid: null,
        path: 'pages/about.json',
        relativePath: 'pages/about.json',
      },
    ],
    warnings: [],
    stats: { scannedFiles: 0, ignoredFiles: 0 },
  }),
}));

vi.mock('@wb/lib/preview-client', () => ({
  fetchPreviewManifest: vi.fn().mockResolvedValue({
    componentRoot: 'components',
    components: [],
    warnings: [],
    globalCssUrl: null,
  }),
}));

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
  canvasData?: PreviewRenderCanvasData,
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
      ...(canvasData ? { canvasData } : {}),
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
      root.render(
        <MemoryRouter>
          <PreviewFrameApp />
        </MemoryRouter>,
      );
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
      root.render(
        <MemoryRouter>
          <PreviewFrameApp />
        </MemoryRouter>,
      );
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

  it('applies render canvasData to drupalSettings and resets it when absent', async () => {
    const runtimeWindow = window as Window & {
      drupalSettings?: {
        canvasData?: {
          v0?: Record<string, unknown>;
        };
      };
    };
    await act(async () => {
      root = createRoot(container);
      root.render(
        <MemoryRouter>
          <PreviewFrameApp />
        </MemoryRouter>,
      );
    });

    const canvasData: PreviewRenderCanvasData = {
      pageTitle: 'Entity title',
      breadcrumbs: [{ key: '<front>', text: 'Home', url: '/' }],
      mainEntity: {
        bundle: 'article',
        entityTypeId: 'node',
        uuid: 'a5715314-9b06-42a4-8be4-8c555b12b869',
        requestedLanguage: 'en',
        renderedLanguage: 'en',
        translations: [],
      },
    };
    await dispatchRenderRequest(
      makeRequest('template-a:2', 'page', canvasData),
      renderSpecMock,
    );

    const v0 = runtimeWindow.drupalSettings?.canvasData?.v0;
    expect(v0?.pageTitle).toBe('Entity title');
    expect(v0?.breadcrumbs).toEqual(canvasData.breadcrumbs);
    expect(v0?.mainEntity).toEqual(canvasData.mainEntity);

    // A later render without canvasData must fully reset the page-level
    // fields, so page data from the previous render cannot leak into it.
    await dispatchRenderRequest(
      makeRequest('component-a', 'component'),
      renderSpecMock,
    );

    expect(v0?.pageTitle).toBe('');
    expect(v0?.breadcrumbs).toEqual([]);
    expect(v0?.mainEntity).toBeNull();
  });

  it('posts shell-sync when an internal page link is clicked', async () => {
    const postMessageSpy = vi.spyOn(window.parent, 'postMessage');
    await act(async () => {
      root = createRoot(container);
      root.render(
        <MemoryRouter>
          <PreviewFrameApp />
        </MemoryRouter>,
      );
    });

    await act(async () => {
      const link = document.createElement('a');
      link.href = `${window.location.origin}/page/about`;
      container.appendChild(link);
      link.click();
    });

    const shellSyncCall = postMessageSpy.mock.calls.find(
      (call) =>
        call[0] &&
        typeof call[0] === 'object' &&
        'type' in call[0] &&
        (call[0] as { type: string }).type === 'preview:shell-sync',
    );
    expect(shellSyncCall?.[0]).toMatchObject({
      source: 'canvas-workbench-frame',
      type: 'preview:shell-sync',
      payload: { path: '/page/about' },
    });
    expect(shellSyncCall?.[1]).toBe(window.location.origin);
    postMessageSpy.mockRestore();
  });
});
