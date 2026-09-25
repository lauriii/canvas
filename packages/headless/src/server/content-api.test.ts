import { once } from 'node:events';
import { createServer } from 'node:http';
import { describe, expect, it, vi } from 'vitest';

import {
  isPageRedirect,
  parsePreviewRequest,
  serializeJsonForHtml,
  withPreviewContext,
} from '../index';
import { fetchPage } from './content-api';

import type { AddressInfo } from 'node:net';

describe('language negotiation transport', () => {
  it.each([false, true])(
    'follows HTTP redirects; credentials stay on their origin (foreign=%s)',
    async (foreign) => {
      const received: Array<{ url: string; authorization?: string }> = [];
      const result = {
        content: { element: 'canvas-page' },
        head: { title: 'French draft' },
        route: { managedByCanvas: true },
      };
      const destination = createServer((request, response) => {
        received.push({
          url: request.url!,
          authorization: request.headers.authorization,
        });
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify(result));
      });
      destination.listen(0, '127.0.0.1');
      await once(destination, 'listening');
      const destinationUrl = `http://127.0.0.1:${(destination.address() as AddressInfo).port}`;
      const source = createServer((request, response) => {
        received.push({
          url: request.url!,
          authorization: request.headers.authorization,
        });
        const url = new URL(request.url!, 'http://localhost');
        if (!url.searchParams.has('_canvas_headless_language_redirect')) {
          url.searchParams.set('requestUri', '/fr/page/1?language=route-owned');
          url.searchParams.set('_canvas_headless_language_redirect', '1');
          response.writeHead(302, {
            Location: `${foreign ? destinationUrl : ''}${url.pathname}${url.search}`,
            'Cache-Control': 'private, no-store',
          });
          response.end();
        } else {
          response.setHeader('Content-Type', 'application/json');
          response.end(JSON.stringify(result));
        }
      });
      source.listen(0, '127.0.0.1');
      await once(source, 'listening');
      try {
        const page = await fetchPage(
          '/page/1?language=route-owned&_canvas_excludeAutoSave=true&_canvas_language=fr&_canvas_viewMode=full',
          {
            baseUrl: `http://127.0.0.1:${(source.address() as AddressInfo).port}/drupal`,
            draftData: {
              path: '/page/1',
              sub: '42',
              resourceVersion: 'rel:working-copy',
              renewUrl: 'https://unused.example/renew',
              tokenType: 'Bearer',
              accessToken: 'test-only-token',
              tokenExpiresAt: Date.now() + 60_000,
              codeVerifier: 'test-only-verifier',
            },
          },
        );
        expect(page).toMatchObject({ head: { title: 'French draft' } });
        expect(received).toHaveLength(2);
        expect(received[0].authorization).toBe('Bearer test-only-token');
        expect(received[1].authorization).toBe(
          foreign ? undefined : 'Bearer test-only-token',
        );
        const target = new URL(received[1].url, 'http://localhost');
        expect(target.pathname).toBe('/drupal/canvas/content-api');
        expect(Object.fromEntries(target.searchParams)).toEqual({
          requestUri: '/fr/page/1?language=route-owned',
          language: 'fr',
          viewMode: 'full',
          excludeAutoSave: 'true',
          _canvas_headless_language_redirect: '1',
        });
      } finally {
        source.closeAllConnections();
        destination.closeAllConnections();
        await Promise.all([
          new Promise<void>((resolve) => source.close(() => resolve())),
          new Promise<void>((resolve) => destination.close(() => resolve())),
        ]);
      }
    },
  );
});

describe('isPageRedirect', () => {
  it('distinguishes redirect and page results', () => {
    expect(
      isPageRedirect({
        redirect: {
          external: false,
          url: '/new-location',
          statusCode: 301,
        },
      }),
    ).toBe(true);
    expect(
      isPageRedirect({
        content: { element: 'canvas-page' },
        head: { title: 'Page' },
        context: { page: null, site: null },
        route: {
          name: 'entity.canvas_page.canonical',
          requestUri: '/page',
          params: {},
          managedByCanvas: true,
          negotiatedLanguage: 'fr',
          translations: [
            {
              langcode: 'en',
              name: 'English',
              nativeName: 'English',
              translationAvailable: true,
              current: false,
              url: '/page',
              external: false,
            },
            {
              langcode: 'fr',
              name: 'French',
              nativeName: 'Français',
              translationAvailable: false,
              current: true,
              url: '/fr/page',
              external: false,
            },
          ],
          entity: {
            entityType: 'canvas_page',
            bundle: 'canvas_page',
            id: '1',
            uuid: 'page-uuid',
            langcode: 'en',
          },
        },
      }),
    ).toBe(false);
    expect(
      isPageRedirect({
        content: null,
        head: { title: 'Empty page' },
        context: { page: null, site: null },
        route: {
          name: 'user.login',
          requestUri: '/user/login',
          params: {},
          managedByCanvas: false,
          negotiatedLanguage: 'en',
          translations: [],
          entity: null,
        },
      }),
    ).toBe(false);
  });
});

describe('serializeJsonForHtml', () => {
  it('keeps JSON parseable while escaping HTML-significant characters', () => {
    const value = {
      text: '</script><script>alert("x")</script>&\u2028\u2029',
    };

    const serialized = serializeJsonForHtml(value);

    expect(serialized).not.toContain('<');
    expect(serialized).not.toContain('>');
    expect(serialized).not.toContain('&');
    expect(JSON.parse(serialized)).toEqual(value);
  });
});

describe('request-local preview context', () => {
  const draftData = {
    path: '/page/1',
    sub: '42',
    resourceVersion: 'rel:working-copy',
    renewUrl: 'https://drupal.example/renew',
    tokenType: 'Bearer',
    accessToken: 'test-only-token',
    tokenExpiresAt: Date.now() + 60_000,
    codeVerifier: 'test-only-verifier',
    // Old cookies must not affect any request, even when still valid.
    previewContext: { language: 'de', viewMode: 'teaser', pageVariant: 'old' },
  };
  const context = {
    language: 'fr',
    viewMode: 'full',
    pageVariant: 'new',
    excludeAutoSave: true,
  };
  const page = {
    content: null,
    head: { title: 'Page' },
    route: { managedByCanvas: true },
  };

  it.each([
    [
      undefined,
      {
        language: 'fr',
        viewMode: 'full',
        pageVariant: 'new',
        excludeAutoSave: 'true',
      },
    ],
    [{}, {}],
    [{ language: 'pl' }, { language: 'pl' }],
  ])(
    'uses explicit request context %j in place of URL or cookie context',
    async (previewContext, expected) => {
      const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
      await fetchPage(
        withPreviewContext('/page/1?tag=a%20b&tag=c#heading', context),
        {
          baseUrl: 'https://drupal.example',
          draftData,
          previewContext,
          fetchImpl,
        },
      );
      const [url] = fetchImpl.mock.calls[0];
      expect(Object.fromEntries(url.searchParams)).toEqual({
        requestUri: '/page/1?tag=a%20b&tag=c#heading',
        ...expected,
      });
    },
  );

  it('does not apply a legacy cookie context when the request has none', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
    await fetchPage('/page/1', {
      baseUrl: 'https://drupal.example',
      draftData,
      fetchImpl,
    });
    const [url] = fetchImpl.mock.calls[0];
    expect(Object.fromEntries(url.searchParams)).toEqual({
      requestUri: '/page/1',
    });
  });

  it.each([null, { ...draftData, tokenExpiresAt: 0 }])(
    'does not forward preview context without live authorization (%j)',
    async (session) => {
      const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
      await fetchPage(withPreviewContext('/page/1', context), {
        baseUrl: 'https://drupal.example',
        draftData: session,
        fetchImpl,
      });
      const [url, init] = fetchImpl.mock.calls[0];
      expect(Object.fromEntries(url.searchParams)).toEqual({
        requestUri: '/page/1',
      });
      expect(init.headers).not.toHaveProperty('Authorization');
    },
  );

  it('isolates component previews from page context while retaining their requested language', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(Response.json(page));
    await fetchPage(withPreviewContext('/page/1', context), {
      baseUrl: 'https://drupal.example',
      draftData,
      componentPreviewId: 'js.heading',
      fetchImpl,
    });
    const [url] = fetchImpl.mock.calls[0];
    expect(Object.fromEntries(url.searchParams)).toEqual({
      requestUri: '/page/1',
      componentId: 'js.heading',
      language: 'fr',
    });
  });

  it.each([
    [false, true, undefined, context],
    [true, true, undefined, undefined],
    [false, false, undefined, undefined],
    [false, true, 'js.heading', { language: 'fr' }],
  ])(
    'retains request context across applicable redirects (external=%s, live=%s, component=%s)',
    async (external, live, componentPreviewId, expected) => {
      const redirect = {
        external,
        url: external
          ? 'https://other.example/new?tag=a%20b#heading'
          : '/new?tag=a%20b#heading',
        statusCode: 301,
      };
      const fetchImpl = vi.fn().mockResolvedValue(Response.json({ redirect }));
      const result = await fetchPage(withPreviewContext('/old', context), {
        baseUrl: 'https://drupal.example',
        draftData: live ? draftData : null,
        componentPreviewId,
        fetchImpl,
      });
      expect(result).toEqual({
        redirect: {
          ...redirect,
          url: expected
            ? withPreviewContext(redirect.url, expected)
            : redirect.url,
        },
      });
    },
  );
});

describe('preview request URL helpers', () => {
  it('preserves unrelated query encoding, duplicates, and the fragment', () => {
    const path =
      '/page?tag=a%20b&tag=c%2Fd&language=route-owned&viewMode=grid&_canvas_tracking=1&_canvas_language=fr&_canvas_viewMode=teaser&_canvas_pageVariant=alternate&_canvas_excludeAutoSave=true#section?keep=1';
    expect(parsePreviewRequest(path)).toEqual({
      requestUri:
        '/page?tag=a%20b&tag=c%2Fd&language=route-owned&viewMode=grid&_canvas_tracking=1#section?keep=1',
      language: 'fr',
      viewMode: 'teaser',
      pageVariant: 'alternate',
      excludeAutoSave: true,
    });
  });

  // cspell:ignore Fcanvas Fmode
  it('removes malformed values and parses encoded keys and repeated context safely', () => {
    expect(
      parsePreviewRequest(
        '/page?%5Fcanvas_language=pl&_canvas_language=fr&_canvas_viewMode=bad%2Fmode&_canvas_pageVariant=%20&_canvas_excludeAutoSave=false&_canvas_excludeAutoSave=true#',
      ),
    ).toEqual({
      requestUri: '/page#',
      language: 'pl',
      excludeAutoSave: false,
    });
  });

  it('uses current-request defaults when the route omits reserved parameters', () => {
    const defaults = parsePreviewRequest(
      '/current?_canvas_language=pl&_canvas_viewMode=teaser&_canvas_pageVariant=alternate&_canvas_excludeAutoSave=true',
    );
    expect(parsePreviewRequest('/page?tag=a%20b', defaults)).toEqual({
      requestUri: '/page?tag=a%20b',
      language: 'pl',
      viewMode: 'teaser',
      pageVariant: 'alternate',
      excludeAutoSave: true,
    });
    expect(
      parsePreviewRequest(
        '/page?_canvas_language=fr&_canvas_excludeAutoSave=false',
        defaults,
      ),
    ).toEqual({
      requestUri: '/page',
      language: 'fr',
      viewMode: 'teaser',
      pageVariant: 'alternate',
      excludeAutoSave: false,
    });
    expect(
      parsePreviewRequest(
        '/page?_canvas_language=&_canvas_language=en&_canvas_viewMode=invalid%2Fmode&_canvas_pageVariant=&_canvas_excludeAutoSave=invalid',
        defaults,
      ),
    ).toEqual({
      requestUri: '/page',
      excludeAutoSave: false,
    });
  });

  it('replaces all previous context without changing route parameters', () => {
    const path =
      '/page?tag=a%20b&tag=c&_canvas_language=pl&_canvas_viewMode=teaser&_canvas_pageVariant=old&_canvas_excludeAutoSave=true#';
    expect(withPreviewContext(path, { language: 'en' })).toBe(
      '/page?tag=a%20b&tag=c&_canvas_language=en#',
    );
    expect(withPreviewContext(path, {})).toBe('/page?tag=a%20b&tag=c#');
    expect(
      withPreviewContext(path, {
        language: '',
        viewMode: '../full',
        pageVariant: ' ',
        excludeAutoSave: false,
      }),
    ).toBe('/page?tag=a%20b&tag=c#');
  });
});
