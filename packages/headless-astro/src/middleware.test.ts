import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DRAFT_DATA_COOKIE_NAME } from '@drupal-canvas/headless';
import { createDraftServer } from '@drupal-canvas/headless/server';

import {
  ASTRO_DRAFT_FLAG_COOKIE_NAME,
  createAstroDraftAdapter,
} from './adapter';
import { onRequest } from './middleware';

import type { APIContext } from 'astro';

const { getDraftData } = vi.hoisted(() => ({ getDraftData: vi.fn() }));
vi.mock('./server', () => ({ getDraftData }));

beforeEach(() => {
  vi.stubEnv('CANVAS_SITE_URL', 'https://cms.example');
  vi.stubEnv('CANVAS_EDITOR_ORIGINS', undefined);
  getDraftData.mockResolvedValue({ renewUrl: 'https://editor.example/renew' });
});
afterEach(() => vi.unstubAllEnvs());

describe('Astro CSP wiring', () => {
  it.each([
    [undefined, "'self' https://cms.example https://editor.example"],
    ['https://explicit.example', "'self' https://explicit.example"],
  ])(
    'resolves configured origins %j after the route runs',
    async (configured, expected) => {
      vi.stubEnv('CANVAS_EDITOR_ORIGINS', configured);
      const response = new Response('page');
      const context = {} as APIContext;
      await onRequest(context, async () => {
        response.headers.set('Content-Security-Policy', "default-src 'self'");
        return response;
      });
      expect(getDraftData).toHaveBeenCalledWith(context);
      expect(response.headers.get('Content-Security-Policy')).toBe(
        `default-src 'self', frame-ancestors ${expected}`,
      );
    },
  );
  it('handles an absent session', async () => {
    getDraftData.mockResolvedValue(null);
    const response = new Response('page');
    await onRequest({} as APIContext, async () => response);
    expect(response.headers.get('Content-Security-Policy')).toBe(
      "frame-ancestors 'self' https://cms.example",
    );
  });
  it('keeps application-owned frame-ancestors authoritative', async () => {
    const response = new Response('page', {
      headers: {
        'Content-Security-Policy':
          "default-src 'self', frame-ancestors https://app-owned.example",
      },
    });
    await onRequest({} as APIContext, async () => response);
    expect(response.headers.get('Content-Security-Policy')).toBe(
      "default-src 'self', frame-ancestors https://app-owned.example",
    );
  });
});

describe('Astro request-local preview context', () => {
  it('uses each document URL when page loaders pass only the pathname', async () => {
    const sharedCookies = new Map([
      [ASTRO_DRAFT_FLAG_COOKIE_NAME, '1'],
      [
        DRAFT_DATA_COOKIE_NAME,
        JSON.stringify({
          path: '/node/99',
          resourceVersion: 'rel:working-copy',
          sub: '1',
          renewUrl: 'https://cms.example/canvas-headless/renew',
          accessToken: 'test-token',
          tokenType: 'Bearer',
          tokenExpiresAt: Date.now() + 60_000,
          codeVerifier: 'test-verifier',
        }),
      ],
    ]);
    const apiUrls: URL[] = [];
    const fetchImpl = vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input));
      apiUrls.push(url);
      return Response.json({
        content: { element: 'renderless-container' },
        head: { title: url.searchParams.get('language') },
        route: { managedByCanvas: true },
      });
    });
    const load = (language: string, path = '/node/1') => {
      const adapter = createAstroDraftAdapter({
        url: new URL(
          `https://app.example/node/1?_canvas_language=${language}&_canvas_viewMode=teaser&_canvas_pageVariant=alternate&tracking=app-only`,
        ),
        cookies: {
          get: (name) => {
            const value = sharedCookies.get(name);
            return value === undefined ? undefined : { value };
          },
          set: (name, value) => {
            sharedCookies.set(name, value);
          },
        },
        redirect: (target) => Response.redirect(`https://app.example${target}`),
      });
      return createDraftServer({
        adapter,
        config: { baseUrl: 'https://cms.example' },
        fetchImpl,
      }).fetchPage(path);
    };
    await load('en');
    await load('pl');
    await load('en');
    await load('pl', '/node/1?_canvas_language=de&page=2');
    expect(apiUrls.map((url) => url.searchParams.get('language'))).toEqual([
      'en',
      'pl',
      'en',
      'de',
    ]);
    expect(apiUrls.map((url) => url.searchParams.get('requestUri'))).toEqual([
      '/node/1',
      '/node/1',
      '/node/1',
      '/node/1?page=2',
    ]);
    for (const url of apiUrls) {
      expect(url.searchParams.get('viewMode')).toBe('teaser');
      expect(url.searchParams.get('pageVariant')).toBe('alternate');
      expect(url.searchParams.has('tracking')).toBe(false);
    }
  });
});
