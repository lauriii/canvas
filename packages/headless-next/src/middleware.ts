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
 * Apply Canvas framing policy to an app-owned middleware/proxy response.
 * Supply the app's complete CSP on this response before calling this helper;
 * an existing frame-ancestors directive remains authoritative. Next config
 * and hosting-layer headers are not visible here and must not compete with it.
 */
export function applyCanvasHeaders(
  request: NextRequest,
  response: NextResponse,
): NextResponse {
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
