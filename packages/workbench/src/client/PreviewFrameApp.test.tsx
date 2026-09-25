import { act } from 'react';
import {
  defineComponentRegistry,
  renderSpec,
} from 'drupal-canvas/json-render-utils';
import {
  useJsonApiClient,
  usePageContext,
  useSiteContext,
} from 'drupal-canvas/react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { PreviewFrameApp } from './PreviewFrameApp';

import type { ReactNode } from 'react';
import type { Root } from 'react-dom/client';
import type { PreviewRenderRequest } from '@wb/lib/preview-contract';

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
    componentSchemas: new Map(),
  }),
}));

vi.mock('@wb/lib/preview-client', () => ({
  fetchPreviewManifest: vi.fn().mockResolvedValue({
    componentRoot: 'components',
    components: [],
    warnings: [],
    globalCssUrl: null,
    brandKitCssUrl: null,
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
  renderType: 'page' | 'component' | 'page-template',
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
  expectedRenderCalls = 1,
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
      i < 50 &&
      renderSpecMock.mock.calls.length <
        renderCallsBefore + expectedRenderCalls;
      i++
    ) {
      await Promise.resolve();
    }
    expect(renderSpecMock.mock.calls.length).toBe(
      renderCallsBefore + expectedRenderCalls,
    );
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

  it('renders page content at the selected page template marker', async () => {
    const registry = {};
    vi.mocked(defineComponentRegistry).mockResolvedValueOnce(registry);
    await act(async () => {
      root = createRoot(container);
      root.render(
        <MemoryRouter>
          <PreviewFrameApp />
        </MemoryRouter>,
      );
    });

    const request = makeRequest('page-a', 'page');
    const pageTemplateSpec = {
      root: 'content-marker',
      elements: {
        'content-marker': { type: 'marker.page_content', props: {} },
      },
    };
    request.payload.pageTemplate = { spec: pageTemplateSpec };

    await dispatchRenderRequest(request, renderSpecMock, 2);

    expect(renderSpecMock.mock.calls.at(-2)?.[0]).toBe(validSpec);
    expect(renderSpecMock.mock.calls.at(-1)?.[0]).toBe(pageTemplateSpec);
    expect(registry).toHaveProperty('marker.page_content');
  });

  it('supplies page and site context and a JSON:API client to previewed components', async () => {
    const seen: Array<{
      page: ReturnType<typeof usePageContext>;
      site: ReturnType<typeof useSiteContext>;
      client: ReturnType<typeof useJsonApiClient>;
    }> = [];
    function Probe() {
      seen.push({
        page: usePageContext(),
        site: useSiteContext(),
        client: useJsonApiClient(),
      });
      return null;
    }
    await act(async () => {
      root = createRoot(container);
      root.render(
        <MemoryRouter>
          <PreviewFrameApp />
        </MemoryRouter>,
      );
    });

    renderSpecMock.mockReturnValueOnce(<Probe />);
    await dispatchRenderRequest(makeRequest('page-a', 'page'), renderSpecMock);

    expect(seen.at(-1)?.page).toEqual({
      pageTitle: '',
      breadcrumbs: [],
      mainEntity: null,
    });
    expect(seen.at(-1)?.site).toEqual({
      branding: {
        homeUrl: '/',
        siteName: 'Workbench test site',
        siteSlogan: '',
      },
      baseUrl: 'https://drupal.example',
      themeAssets: {
        logo: { url: 'https://drupal.example/logo.svg' },
        favicon: {
          url: 'https://drupal.example/favicon.ico',
          mimeType: 'image/x-icon',
        },
      },
    });
    expect(seen.at(-1)?.client?.baseUrl).toBe('https://drupal.example');
    expect(seen.at(-1)?.client?.apiPrefix).toBe('jsonapi');
  });

  it('re-renders the same target in place with fresh props, keeping component state', async () => {
    // A mock edit reaches the mounted iframe as a new render request for the
    // same render id: the component keeps its state, its props change.
    const { useState } = await import('react');
    function Counter({ title }: { title: string }) {
      const [count, setCount] = useState(0);
      return (
        <button data-testid="counter" onClick={() => setCount(count + 1)}>
          {title}:{count}
        </button>
      );
    }
    await act(async () => {
      root = createRoot(container);
      root.render(
        <MemoryRouter>
          <PreviewFrameApp />
        </MemoryRouter>,
      );
    });
    renderSpecMock.mockImplementationOnce(
      (spec) =>
        (
          <Counter
            key="counter"
            title={String(spec.elements.root.props.title)}
          />
        ) as unknown as string,
    );
    const first = makeRequest('card:mock-1', 'component');
    first.payload.spec = {
      root: 'root',
      elements: { root: { type: 'js.card', props: { title: 'Before' } } },
    };
    await dispatchRenderRequest(first, renderSpecMock);
    const counter = () =>
      container.querySelector('[data-testid="counter"]') as HTMLButtonElement;
    expect(counter().textContent).toBe('Before:0');
    await act(async () => {
      counter().click();
    });
    expect(counter().textContent).toBe('Before:1');

    renderSpecMock.mockImplementationOnce(
      (spec) =>
        (
          <Counter
            key="counter"
            title={String(spec.elements.root.props.title)}
          />
        ) as unknown as string,
    );
    const second = makeRequest('card:mock-1', 'component');
    second.payload.spec = {
      root: 'root',
      elements: { root: { type: 'js.card', props: { title: 'After' } } },
    };
    await dispatchRenderRequest(second, renderSpecMock);
    // Same element, fresh props, state kept: no remount happened.
    expect(counter().textContent).toBe('After:1');
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
