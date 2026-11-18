// @vitest-environment jsdom

import { createJsonApiClient } from 'drupal-canvas';
import {
  CanvasContextProvider,
  JsonApiClientProvider,
  useJsonApiClient,
  usePageContext,
  useSiteContext,
} from 'drupal-canvas/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchPage } from '@drupal-canvas/headless/server';

import { CanvasComponentTree } from './canvas-component-tree';

import type { CanvasComponentTree as CanvasComponentTreeDocument } from '@drupal-canvas/headless';
import type { CanvasContext } from 'drupal-canvas';

const context: CanvasContext = {
  page: { pageTitle: 'From tree', breadcrumbs: [], mainEntity: null },
  site: {
    branding: { homeUrl: '/', siteName: 'Tree site', siteSlogan: '' },
    baseUrl: 'https://drupal.example',
    themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
  },
};

const outerContext: CanvasContext = {
  page: { pageTitle: 'From outer', breadcrumbs: [], mainEntity: null },
  site: {
    ...context.site!,
    branding: { ...context.site!.branding, siteName: 'Outer site' },
  },
};

function Header() {
  const page = usePageContext();
  const site = useSiteContext();
  const client = useJsonApiClient();
  return (
    <header>
      {page?.pageTitle ?? 'no-page'}|{site?.branding.siteName ?? 'no-site'}|
      {client
        ? `${client.baseUrl}|${client.runtimeConfig.proxyUrl ?? 'direct'}`
        : 'no-client'}
    </header>
  );
}

const tree = {
  root: 'header',
  elements: {
    header: {
      type: 'js.header',
      props: {},
      children: [],
    },
  },
} satisfies CanvasComponentTreeDocument;

describe('CanvasComponentTree context and client', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('establishes the provider from the context prop', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={tree}
        components={{ header: Header }}
        context={context}
      />,
    );
    expect(html).toContain('From tree|Tree site|');
    expect(
      warn.mock.calls.map(([message]) => String(message)),
    ).not.toContainEqual(expect.stringContaining('usePageContext()'));
  });

  it.each([
    {
      logo: { url: 'https://drupal.example/sites/default/files/logo.svg' },
      favicon: { url: 'https://cdn.example/icon.png', mimeType: 'image/png' },
    },
    { logo: { url: '' }, favicon: { url: '', mimeType: 'image/png' } },
  ])(
    'passes theme assets through without requiring the frontend to render them: %j',
    (assets) => {
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
      function Assets() {
        expect(useSiteContext()?.themeAssets).toEqual(assets);
        return <span>Assets are optional</span>;
      }
      const html = renderToStaticMarkup(
        <CanvasComponentTree
          tree={tree}
          components={{ header: Assets }}
          context={{
            ...context,
            site: { ...context.site!, themeAssets: assets },
          }}
        />,
      );
      expect(html).toContain('Assets are optional');
      expect(html).not.toContain('<img');
      expect(html).not.toContain('<link');
      expect(warn).not.toHaveBeenCalled();
    },
  );

  it('inherits an outer provider when the context prop is omitted', () => {
    const html = renderToStaticMarkup(
      <CanvasContextProvider context={outerContext}>
        <CanvasComponentTree tree={tree} components={{ header: Header }} />
      </CanvasContextProvider>,
    );
    expect(html).toContain('From outer|Outer site|');
  });

  it('lets an explicit context prop win over an outer provider, null values included', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={outerContext}>
          <CanvasComponentTree
            tree={tree}
            components={{ header: Header }}
            context={context}
          />
        </CanvasContextProvider>,
      ),
    ).toContain('From tree|Tree site|');
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={outerContext}>
          <CanvasComponentTree
            tree={tree}
            components={{ header: Header }}
            context={{ page: null, site: context.site }}
          />
        </CanvasContextProvider>,
      ),
    ).toContain('no-page|Tree site|');
  });

  it('renders a normalized Canvas 1.11 response without borrowing head or outer context', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const content = {
      element: 'renderless-container',
      slots: {
        default: { element: 'js-header', props: { canvasUuid: 'header' } },
      },
    };
    // Release-shaped wire response: context is absent, not manually null-filled.
    const page = await fetchPage('/', {
      baseUrl: 'https://drupal.example',
      fetchImpl: vi.fn().mockResolvedValue(
        Response.json({
          content,
          head: { title: 'Legacy head title' },
          route: {
            name: 'view.frontpage.page_1',
            requestUri: '/',
            params: [],
            managedByCanvas: false,
            entity: null,
          },
        }),
      ),
    });
    if (!page || !('context' in page)) throw new Error('Expected a page');
    expect(page.content).toEqual(content);
    const html = renderToStaticMarkup(
      <CanvasContextProvider context={outerContext}>
        <CanvasComponentTree
          tree={page.content}
          context={page.context}
          components={{ header: Header }}
        />
      </CanvasContextProvider>,
    );
    expect(html).toContain('no-page|no-site|no-client');
    expect(html).not.toContain('Legacy head title');
    expect(html).not.toContain('From outer');
    expect(html).not.toContain('Outer site');
  });

  it('renders converted draft content with legacy names, markup, and drop zones', async () => {
    const markup =
      '<!-- canvas-start-server-one --><p>Server markup</p><!-- canvas-end-server-one -->';
    const page = await fetchPage('/', {
      baseUrl: 'https://drupal.example',
      draftData: {
        path: '/',
        resourceVersion: 'rel:working-copy',
        sub: '42',
        renewUrl: 'https://drupal.example/canvas-headless/renew',
        accessToken: 'draft-token',
        tokenType: 'Bearer',
        tokenExpiresAt: Date.now() + 60_000,
        codeVerifier: 'draft-verifier',
      },
      fetchImpl: vi.fn().mockResolvedValue(
        Response.json({
          head: { title: 'Draft page' },
          route: { managedByCanvas: true },
          content: {
            element: 'canvas-preview-content-region',
            slots: {
              default: {
                element: 'js-hero-card',
                props: { canvasUuid: 'hero-one' },
                slots: {
                  default: [
                    { element: 'drupal-markup', slots: { default: markup } },
                    [
                      {
                        element: 'js-inline-label',
                        props: { canvasUuid: 'inline-one' },
                      },
                      {
                        element: 'js-empty-card',
                        props: { canvasUuid: 'empty-one' },
                        slots: { default: [] },
                      },
                    ],
                  ],
                  aside: false,
                },
              },
            },
          },
        }),
      ),
    });
    if (!page || !('content' in page)) throw new Error('Expected a page');

    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={page.content}
        preview={page.isPreview}
        components={{
          Hero_Card: ({ children, aside, ...props }) => {
            expect(props).not.toHaveProperty('canvasUuid');
            return (
              <article>
                {children}
                <aside>{aside}</aside>
              </article>
            );
          },
          'inline-label': () => <b>Inline label</b>,
          'empty-card': ({ children }) => <footer>{children}</footer>,
        }}
      />,
    );

    expect(html).toMatch(/<article>.*Server markup.*Inline label.*<aside>/);
    expect(html).toContain(markup);
    expect(html).toContain('data-canvas-slot-name="aside"');
    expect(html).toContain('canvas--slot-empty-placeholder');
    expect(html).toMatch(
      /<footer>.*canvas--slot-empty-placeholder.*<\/footer>/,
    );
    expect(html.match(/data-canvas-region-id="content"/g)).toHaveLength(2);
    expect(html.match(/data-canvas-uuid="hero-one"/g)).toHaveLength(2);
    expect(html).not.toContain('data-canvas-uuid="el-');
  });

  it('preserves exact json-render tags and component props in native content', () => {
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={{
          root: 'el-0',
          elements: {
            'el-0': {
              type: 'js-header',
              props: { canvasUuid: 'application-data' },
              children: [],
            },
          },
        }}
        components={{
          'js-header': ({ canvasUuid }) => <p>{canvasUuid}</p>,
          header: () => <p>Legacy registry match</p>,
        }}
      />,
    );

    expect(html).toBe('<p>application-data</p>');
  });

  it('creates a client from the runtime configuration and inherits an outer client otherwise', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={tree}
        components={{ header: Header }}
        context={context}
        jsonApi={{
          baseUrl: 'https://drupal.example',
          apiPrefix: 'jsonapi',
          proxyUrl: '/api/canvas/jsonapi',
          resourceVersion: 'rel:working-copy',
          preview: true,
        }}
      />,
    );
    // jsdom defines `window`, so the browser client is created: it goes
    // through the proxy.
    expect(html).toContain('https://drupal.example|/api/canvas/jsonapi');

    const outerClient = createJsonApiClient({
      baseUrl: 'https://outer.example',
    });
    expect(
      renderToStaticMarkup(
        <JsonApiClientProvider client={outerClient}>
          <CanvasComponentTree
            tree={tree}
            components={{ header: Header }}
            context={context}
          />
        </JsonApiClientProvider>,
      ),
    ).toContain('https://outer.example|direct');
  });
});

describe('CanvasComponentTree runtime configuration', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  const runtime = {
    baseUrl: 'https://drupal.example',
    apiPrefix: 'jsonapi',
    proxyUrl: '/api/canvas/jsonapi',
    resourceVersion: null,
    preview: false,
  };

  it('uses the nearest JsonApiRuntimeProvider when no jsonApi prop is given', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { JsonApiRuntimeProvider } = await import('./jsonapi-runtime');
    const html = renderToStaticMarkup(
      <JsonApiRuntimeProvider config={runtime}>
        <CanvasComponentTree
          tree={tree}
          components={{ header: Header }}
          context={context}
        />
      </JsonApiRuntimeProvider>,
    );
    expect(html).toContain('https://drupal.example|/api/canvas/jsonapi');
    const explicit = renderToStaticMarkup(
      <JsonApiRuntimeProvider config={runtime}>
        <CanvasComponentTree
          tree={tree}
          components={{ header: Header }}
          context={context}
          jsonApi={{ ...runtime, baseUrl: 'https://explicit.example' }}
        />
      </JsonApiRuntimeProvider>,
    );
    expect(explicit).toContain('https://explicit.example|/api/canvas/jsonapi');
  });

  it('creates the same draft-aware client during server rendering, direct for public rendering', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const globalWithWindow = globalThis as { window?: unknown };
    const savedWindow = globalWithWindow.window;
    // Simulate server rendering.
    delete globalWithWindow.window;
    try {
      const draftHtml = renderToStaticMarkup(
        <CanvasComponentTree
          tree={tree}
          components={{ header: Header }}
          context={context}
          jsonApi={{
            ...runtime,
            resourceVersion: 'rel:working-copy',
            preview: true,
          }}
        />,
      );
      // Non-null, with the browser client's configuration (proxy mapping,
      // draft resource version), so hydration sees the same client.
      expect(draftHtml).toContain('https://drupal.example|/api/canvas/jsonapi');
      const publicHtml = renderToStaticMarkup(
        <CanvasComponentTree
          tree={tree}
          components={{ header: Header }}
          context={context}
          jsonApi={runtime}
        />,
      );
      expect(publicHtml).toContain('https://drupal.example|direct');
    } finally {
      globalWithWindow.window = savedWindow;
    }
  });
});
