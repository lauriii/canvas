import { NextResponse } from 'next/server';
import {
  DRAFT_DATA_COOKIE_NAME,
  parseDraftData,
} from '@drupal-canvas/headless';
import {
  mergeFrameAncestors,
  resolveFrameAncestors,
} from '@drupal-canvas/headless/server';

import type { NextRequest } from 'next/server';

/**
 * Apply Canvas framing policy and forward the current preview request URL
 * to server components on an app-owned middleware/proxy response.
 * Supply the app's complete CSP on this response before calling this helper;
 * an existing frame-ancestors directive remains authoritative. Next config
 * and hosting-layer headers are not visible here and must not compete with it.
 */
export function applyCanvasHeaders(
  request: NextRequest,
  response: NextResponse,
): NextResponse {
  // Preserve any request-header changes from earlier middleware, including
  // deletions. Next encodes that replacement set on the response.
  const existingOverrides = response.headers.get(
    'x-middleware-override-headers',
  );
  const requestHeaders =
    existingOverrides === null ? new Headers(request.headers) : new Headers();
  for (const name of existingOverrides?.split(',') ?? []) {
    const headerName = name.trim();
    const value = response.headers.get(`x-middleware-request-${headerName}`);
    if (headerName && value !== null) requestHeaders.set(headerName, value);
  }
  // Never accept the client's value for this internal header. Only the
  // current URL may supply preview settings to the server-component adapter.
  requestHeaders.set('x-canvas-preview-request-url', request.url);
  const forwarded = NextResponse.next({ request: { headers: requestHeaders } });
  for (const [name, value] of forwarded.headers) {
    if (
      name === 'x-middleware-override-headers' ||
      name.startsWith('x-middleware-request-')
    ) {
      response.headers.set(name, value);
    }
  }

  // Next's cookie API decodes the wire value. Do not match raw Cookie headers
  // or decode twice: JSON parsing is shared with the other session readers.
  const draftData = parseDraftData(
    request.cookies.get(DRAFT_DATA_COOKIE_NAME)?.value,
  );
  response.headers.set(
    'Content-Security-Policy',
    mergeFrameAncestors(
      response.headers.get('Content-Security-Policy'),
      resolveFrameAncestors(draftData),
    ).join(', '),
  );
  return response;
}

/** Mount as middleware (Next.js 15) or proxy (Next.js 16), on all documents. */
export function canvasMiddleware(request: NextRequest): NextResponse {
  return applyCanvasHeaders(request, NextResponse.next());
}
