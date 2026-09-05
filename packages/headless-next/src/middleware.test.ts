import { NextRequest, NextResponse } from 'next/server';
import { describe, expect, it } from 'vitest';
import { DRAFT_DATA_COOKIE_NAME } from '@drupal-canvas/headless';

import { applyCanvasHeaders, canvasMiddleware } from './middleware';

const draftData = {
  path: '/about',
  resourceVersion: 'rel:working-copy',
  sub: '1',
  renewUrl: 'https://editor.example/canvas-headless/renew',
  accessToken: 'token',
  tokenType: 'Bearer',
  tokenExpiresAt: Date.now() + 60_000,
  codeVerifier: 'verifier',
};

/**
 * A request carrying a cookie exactly as a browser sends it, through a
 * real Cookie header: values are percent-encoded on the wire, and reading
 * the session through the decoding parser is the whole point of resolving
 * the policy here rather than in a `next.config` header rule.
 */
function requestWithCookie(value: string): NextRequest {
  return new NextRequest('https://app.example/about', {
    headers: {
      cookie: `${DRAFT_DATA_COOKIE_NAME}=${encodeURIComponent(value)}`,
    },
  });
}

function requestWithSession(): NextRequest {
  return requestWithCookie(JSON.stringify(draftData));
}

function policy(response: Response): string | null {
  return response.headers.get('content-security-policy');
}

describe('applyCanvasHeaders', () => {
  it("sends 'self' alone without a draft session", () => {
    const response = applyCanvasHeaders(
      new NextRequest('https://app.example/about'),
      NextResponse.next(),
    );
    expect(policy(response)).toBe("frame-ancestors 'self'");
  });

  it('admits the editor origin from a draft session cookie', () => {
    const response = applyCanvasHeaders(
      requestWithSession(),
      NextResponse.next(),
    );
    expect(policy(response)).toBe(
      "frame-ancestors 'self' https://editor.example",
    );
  });

  it("sends 'self' alone for an unparseable cookie", () => {
    const response = applyCanvasHeaders(
      requestWithCookie('not-json'),
      NextResponse.next(),
    );
    expect(policy(response)).toBe("frame-ancestors 'self'");
  });

  it('preserves the directives the response already carries', () => {
    const existing = NextResponse.next();
    existing.headers.set('Content-Security-Policy', "default-src 'self'");
    const response = applyCanvasHeaders(requestWithSession(), existing);
    expect(policy(response)).toBe(
      "default-src 'self', frame-ancestors 'self' https://editor.example",
    );
  });

  it('leaves an application-owned frame-ancestors directive authoritative', () => {
    const existing = NextResponse.next();
    existing.headers.set(
      'Content-Security-Policy',
      "default-src 'self'; frame-ancestors 'none'",
    );
    const response = applyCanvasHeaders(requestWithSession(), existing);
    expect(policy(response)).toBe("default-src 'self'; frame-ancestors 'none'");
  });
});

describe('canvasMiddleware', () => {
  it('answers with a pass-through response carrying the policy', () => {
    expect(policy(canvasMiddleware(requestWithSession()))).toBe(
      "frame-ancestors 'self' https://editor.example",
    );
  });
});
