// @vitest-environment jsdom

/**
 * Server rendering of a live draft session with SWR fallback data, then
 * browser hydration of that HTML: the draft-aware client created on the
 * server keeps the SWR key enabled, so the fallback renders on both sides
 * and hydration does not mismatch, while draft data is never fetched during
 * server rendering (ADR 21, "Server rendering and SWR").
 */

import { act } from 'react';
import { useJsonApiClient } from 'drupal-canvas/react';
import { hydrateRoot } from 'react-dom/client';
import { renderToString } from 'react-dom/server';
import useSWR, { SWRConfig } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  CanvasComponentTree,
  ServerRenderingDraftFetchError,
} from './canvas-component-tree';

import type { CanvasContext, JsonApiRuntimeConfig } from 'drupal-canvas';

const context: CanvasContext = {
  page: { pageTitle: 'Draft page', breadcrumbs: [], mainEntity: null },
  site: {
    branding: { homeUrl: '/', siteName: 'Site', siteSlogan: '' },
    baseUrl: 'https://drupal.example',
    themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
  },
};

const draftRuntime: JsonApiRuntimeConfig = {
  baseUrl: 'https://drupal.example',
  apiPrefix: 'jsonapi',
  proxyUrl: '/api/canvas/jsonapi',
  resourceVersion: 'rel:working-copy',
  preview: true,
};

interface Article {
  title: string;
}

function jsonApiResponse(title: string): Response {
  return new Response(
    JSON.stringify({
      data: { type: 'node--article', id: 'a', attributes: { title } },
    }),
    { status: 200, headers: { 'content-type': 'application/vnd.api+json' } },
  );
}

/** A portable Code Component: string key, fetch through the hook's client. */
function ArticleTitle() {
  const client = useJsonApiClient();
  const { data } = useSWR(
    client ? 'article' : null,
    () => client!.getResource('node--article', 'a') as Promise<Article>,
  );
  return <p data-testid="title">{data ? data.title : 'Loading'}</p>;
}

const tree = { element: 'js-article', props: { canvasUuid: 'article' } };

function App() {
  return (
    <SWRConfig
      value={{
        fallback: { article: { title: 'Draft article' } },
        provider: () => new Map(),
        dedupingInterval: 0,
      }}
    >
      <CanvasComponentTree
        tree={tree}
        components={{ article: ArticleTitle }}
        context={context}
        jsonApi={draftRuntime}
      />
    </SWRConfig>
  );
}

function renderOnServer(element: React.ReactElement): string {
  const globalWithWindow = globalThis as { window?: unknown };
  const savedWindow = globalWithWindow.window;
  delete globalWithWindow.window;
  try {
    return renderToString(element);
  } finally {
    globalWithWindow.window = savedWindow;
  }
}

describe('draft server rendering with SWR fallback and hydration', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
  });

  it('renders the fallback on the server through a non-null draft client and hydrates without mismatch', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});

    // Server: the client exists (the SWR key stays enabled) and SWR reads the
    // fallback; no network request is made.
    const serverFetch = vi.fn<typeof fetch>();
    vi.stubGlobal('fetch', serverFetch);
    const html = renderOnServer(<App />);
    expect(html).toContain('Draft article');
    expect(html).not.toContain('Loading');
    expect(serverFetch).not.toHaveBeenCalled();
    expect(
      warn.mock.calls.map(([message]) => String(message)),
    ).not.toContainEqual(expect.stringContaining('useJsonApiClient()'));

    // Browser: hydrate the server HTML; the fallback renders again, then
    // SWR revalidates through the same-origin proxy with cookies.
    const browserFetch = vi.fn<typeof fetch>(async () =>
      jsonApiResponse('Revalidated draft article'),
    );
    vi.stubGlobal('fetch', browserFetch);
    (
      globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
    ).IS_REACT_ACT_ENVIRONMENT = true;
    const container = document.createElement('div');
    container.innerHTML = html;
    document.body.append(container);
    const recoverable: unknown[] = [];
    await act(async () => {
      hydrateRoot(container, <App />, {
        onRecoverableError: (cause) => recoverable.push(cause),
      });
    });
    expect(recoverable).toEqual([]);
    expect(
      error.mock.calls.map(([message]) => String(message)),
    ).not.toContainEqual(expect.stringMatching(/hydration/i));

    await vi.waitFor(() =>
      expect(container.textContent).toContain('Revalidated draft article'),
    );
    expect(browserFetch).toHaveBeenCalledTimes(1);
    const [url, init] = browserFetch.mock.calls[0];
    expect(String(url)).toMatch(
      /^\/api\/canvas\/jsonapi\/jsonapi\/node\/article\/a\?.*resourceVersion=rel(%3A|:)working-copy/,
    );
    expect(init?.credentials).toBe('same-origin');
  });

  it('refuses draft network requests during server rendering with an actionable error', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const serverFetch = vi.fn<typeof fetch>();
    vi.stubGlobal('fetch', serverFetch);
    let pending: Promise<Article> | undefined;
    function Fetcher() {
      const client = useJsonApiClient();
      // Non-null: the same configuration as the browser client.
      expect(client?.runtimeConfig).toMatchObject({
        proxyUrl: '/api/canvas/jsonapi',
        resourceVersion: 'rel:working-copy',
        preview: true,
      });
      pending = client?.getResource('node--article', 'a') as
        | Promise<Article>
        | undefined;
      return null;
    }
    renderOnServer(
      <CanvasComponentTree
        tree={tree}
        components={{ article: Fetcher }}
        context={context}
        jsonApi={draftRuntime}
      />,
    );
    expect(pending).toBeDefined();
    await expect(pending).rejects.toBeInstanceOf(
      ServerRenderingDraftFetchError,
    );
    await expect(pending).rejects.toThrow(/getClient\(\)/);
    expect(serverFetch).not.toHaveBeenCalled();
  });
});
