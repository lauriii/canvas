import { NextRequest, NextResponse } from 'next/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DRAFT_DATA_COOKIE_NAME } from '@drupal-canvas/headless';

import { applyCanvasHeaders, canvasMiddleware } from './middleware';

const draftData = {
  path: '/',
  resourceVersion: 'rel:working-copy',
  sub: '1',
  renewUrl: 'https://editor.example/renew?value=%25',
  accessToken: 'test',
  tokenType: 'Bearer',
  tokenExpiresAt: 0,
  codeVerifier: 'test',
};
const request = (cookie?: string) =>
  new NextRequest('https://app.example', {
    headers: cookie ? { Cookie: `${DRAFT_DATA_COOKIE_NAME}=${cookie}` } : {},
  });
const encoded = encodeURIComponent(JSON.stringify(draftData));

beforeEach(() => {
  vi.stubEnv('CANVAS_SITE_URL', 'https://cms.example/subdir');
  vi.stubEnv('CANVAS_EDITOR_ORIGINS', undefined);
});
afterEach(() => vi.unstubAllEnvs());

describe('Canvas middleware/proxy', () => {
  it.each([undefined, 'malformed', '%E0%A4%A', encodeURIComponent('{}')])(
    'uses the site default with an absent or invalid cookie: %s',
    (cookie) => {
      expect(
        canvasMiddleware(request(cookie)).headers.get(
          'Content-Security-Policy',
        ),
      ).toBe("frame-ancestors 'self' https://cms.example");
    },
  );
  it.each([encoded, JSON.stringify(draftData)])(
    'parses the cookie via Next: %s',
    (cookie) => {
      expect(
        canvasMiddleware(request(cookie)).headers.get(
          'Content-Security-Policy',
        ),
      ).toBe(
        "frame-ancestors 'self' https://cms.example https://editor.example",
      );
    },
  );
  it('does not restore cookie or site defaults for an empty override', () => {
    vi.stubEnv('CANVAS_EDITOR_ORIGINS', '');
    expect(
      canvasMiddleware(request(encoded)).headers.get('Content-Security-Policy'),
    ).toBe("frame-ancestors 'self'");
  });
  it('uses only the explicit valid sources', () => {
    vi.stubEnv('CANVAS_EDITOR_ORIGINS', 'https://explicit.example');
    expect(
      canvasMiddleware(request(encoded)).headers.get('Content-Security-Policy'),
    ).toBe("frame-ancestors 'self' https://explicit.example");
  });
  it.each([
    [
      "default-src 'self'; script-src https://scripts.example",
      "default-src 'self'; script-src https://scripts.example, frame-ancestors 'self' https://cms.example https://editor.example",
    ],
    [
      "default-src 'self', frame-ancestors https://app-owned.example",
      "default-src 'self', frame-ancestors https://app-owned.example",
    ],
  ])('preserves application policy %s', (existing, expected) => {
    const response = NextResponse.next();
    response.headers.set('Content-Security-Policy', existing);
    response.headers.set('X-App-Header', 'preserved');
    response.cookies.set('app-cookie', 'preserved');
    expect(applyCanvasHeaders(request(encoded), response)).toBe(response);
    expect(response.headers.get('Content-Security-Policy')).toBe(expected);
    expect(response.headers.get('X-App-Header')).toBe('preserved');
    expect(response.cookies.get('app-cookie')?.value).toBe('preserved');
  });
  it.each(['en', 'pl'])(
    'forwards this request URL and replaces a spoofed internal header: %s',
    (language) => {
      const url = `https://app.example/node/1?_canvas_language=${language}`;
      const response = canvasMiddleware(
        new NextRequest(url, {
          headers: {
            'x-canvas-preview-request-url':
              'https://app.example/?_canvas_language=de',
            'x-app-request': 'preserved',
          },
        }),
      );
      expect(
        response.headers.get(
          'x-middleware-request-x-canvas-preview-request-url',
        ),
      ).toBe(url);
      expect(response.headers.get('x-middleware-request-x-app-request')).toBe(
        'preserved',
      );
      expect(response.headers.has('x-canvas-preview-request-url')).toBe(false);
    },
  );
  it('preserves composed request overrides and a rewrite destination', () => {
    const input = new NextRequest(
      'https://app.example/node/1?_canvas_language=pl',
      {
        headers: { 'x-app-request': 'original', 'x-remove-me': 'original' },
      },
    );
    const overridden = new Headers(input.headers);
    overridden.set('x-app-request', 'updated');
    overridden.delete('x-remove-me');
    const response = NextResponse.rewrite('https://app.example/render/node/1', {
      request: { headers: overridden },
    });
    response.headers.set('X-App-Response', 'preserved');
    response.cookies.set('app-cookie', 'preserved');
    expect(applyCanvasHeaders(input, response)).toBe(response);
    const names = response.headers
      .get('x-middleware-override-headers')!
      .split(',');
    expect(names).toContain('x-canvas-preview-request-url');
    expect(names).not.toContain('x-remove-me');
    expect(response.headers.get('x-middleware-request-x-app-request')).toBe(
      'updated',
    );
    expect(response.headers.get('x-middleware-rewrite')).toBe(
      'https://app.example/render/node/1',
    );
    expect(response.headers.has('x-middleware-next')).toBe(false);
    expect(response.headers.get('X-App-Response')).toBe('preserved');
    expect(response.cookies.get('app-cookie')?.value).toBe('preserved');
  });
});
