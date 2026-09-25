import { once } from 'node:events';
import { createServer } from 'node:http';
import { describe, expect, it } from 'vitest';

import { isPageRedirect, serializeJsonForHtml } from '../index';
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
        const page = await fetchPage('/page/1?language=route-owned', {
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
            previewContext: {
              language: 'fr',
              viewMode: 'full',
              pageVariant: 'test',
            },
          },
        });
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
          pageVariant: 'test',
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
