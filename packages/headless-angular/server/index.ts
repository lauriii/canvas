import {
  CANVAS_COMPONENT_PREVIEW_PATH,
  CANVAS_COMPONENT_PREVIEW_QUERY,
  getDraftEditorOrigin,
  isDraftSessionExpired,
  isPageRedirect,
} from '@drupal-canvas/headless';
import { assertCanvasPath } from '@drupal-canvas/headless-angular/contracts';
import { createComponentMetadataHandler } from '@drupal-canvas/headless/components-endpoint/handler';
import {
  buildClearedDraftCookie,
  buildDraftCookie,
  createDraftServer,
  mergeFrameAncestors,
  resolveFrameAncestors,
} from '@drupal-canvas/headless/server';

import type {
  CanvasPageData,
  CanvasRequestContext,
} from '@drupal-canvas/headless-angular/contracts';
import type { ComponentMetadataPayload } from '@drupal-canvas/headless/components-endpoint/handler';
import type {
  DraftCookie,
  DraftServerOptions,
} from '@drupal-canvas/headless/server';

export const ANGULAR_DRAFT_FLAG_COOKIE = 'canvas_headless_draft_mode';
export interface CanvasServerOptions extends Pick<
  DraftServerOptions,
  'config' | 'fetchImpl'
> {
  /** Import the generated server-only manifest; no source filesystem at runtime. */
  manifest: ComponentMetadataPayload;
}

/** One instance per HTTP request. No global cookie jar or credential-bearing cache. */
export function createCanvasRequest(
  request: Request,
  options: CanvasServerOptions,
) {
  const cookies = new Map<string, string>();
  for (const part of (request.headers.get('cookie') ?? '').split(';')) {
    const index = part.indexOf('=');
    if (index < 0) continue;
    const name = part.slice(0, index).trim();
    if (cookies.has(name)) continue;
    try {
      cookies.set(name, decodeURIComponent(part.slice(index + 1).trim()));
    } catch {
      /* Invalid cookies are absent. */
    }
  }
  const pending: DraftCookie[] = [];
  const setCookie = async (cookie: DraftCookie) => {
    cookies.set(cookie.name, cookie.value);
    pending.push(cookie);
  };
  const server = createDraftServer({
    config: options.config,
    fetchImpl: options.fetchImpl,
    adapter: {
      getRequestUrl: async () => request.url,
      getCookie: async (name) => cookies.get(name) ?? null,
      setCookie,
      isDraftFlagEnabled: async () =>
        cookies.get(ANGULAR_DRAFT_FLAG_COOKIE) === '1',
      enableDraftFlag: () =>
        setCookie(buildDraftCookie(ANGULAR_DRAFT_FLAG_COOKIE, '1')),
      disableDraftFlag: () =>
        setCookie(buildClearedDraftCookie(ANGULAR_DRAFT_FLAG_COOKIE)),
      redirect: (path) =>
        new Response(null, { status: 307, headers: { Location: path } }),
    },
  });
  const session = async () => {
    const data = await server.getDraftData();
    return {
      enabled: cookies.get(ANGULAR_DRAFT_FLAG_COOKIE) === '1',
      tokenExpiresAt: data?.tokenExpiresAt ?? null,
      initialExpired: data ? isDraftSessionExpired(data) : true,
      renewUrl: data?.renewUrl ?? null,
      editorOrigin: getDraftEditorOrigin(data),
    };
  };
  const loadPage = async (path: string): Promise<CanvasPageData> => {
    assertCanvasPath(path);
    const url = new URL(path, request.url);
    const component =
      url.pathname === CANVAS_COMPONENT_PREVIEW_PATH
        ? url.searchParams.get(CANVAS_COMPONENT_PREVIEW_QUERY)
        : null;
    return {
      page: component
        ? await server.fetchComponentPreview(component, path)
        : await server.fetchPage(path),
      session: await session(),
    };
  };
  const finalize = async (response: Response): Promise<Response> => {
    const headers = new Headers(response.headers);
    headers.set(
      'Content-Security-Policy',
      mergeFrameAncestors(
        headers.get('Content-Security-Policy'),
        resolveFrameAncestors(await server.getDraftData()),
      ).join(', '),
    );
    // SSR and data may depend on private cookies. Never allow a shared cache to
    // turn one editor's response into another user's page (including redirects).
    headers.set('Cache-Control', 'private, no-store');
    for (const cookie of pending) {
      headers.append('Set-Cookie', serializeCookie(cookie));
    }
    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers,
    });
  };
  const metadata = createComponentMetadataHandler({
    config: () => server.getConfig(),
    isProduction: true,
    loadManifest: async () => options.manifest,
  });
  const handle = async (): Promise<Response | null> => {
    const url = new URL(request.url);
    const path = url.pathname;
    const method = request.method;
    if (path === '/api/draft')
      return finalize(
        method === 'GET'
          ? await server.enableDraftMode(request)
          : methodNotAllowed('GET'),
      );
    if (path === '/api/draft/renew')
      return finalize(
        method === 'POST'
          ? await server.renewDraftSession(request)
          : methodNotAllowed('POST'),
      );
    if (path === '/api/disable-draft') {
      // Unlike assertion-protected renewal, logout has no credential in its body.
      if (request.headers.get('origin') !== url.origin)
        return finalize(new Response('Origin not allowed', { status: 403 }));
      return finalize(
        method === 'POST'
          ? await server.disableDraftMode()
          : methodNotAllowed('POST'),
      );
    }
    if (path === '/api/canvas/components')
      return finalize(
        method === 'GET'
          ? await metadata.GET(request)
          : method === 'OPTIONS'
            ? await metadata.OPTIONS(request)
            : methodNotAllowed('GET, OPTIONS'),
      );
    if (path === '/api/canvas/page' || path === '/api/canvas/entity') {
      if (method !== 'GET') return finalize(methodNotAllowed('GET'));
      try {
        if (path.endsWith('/page'))
          return finalize(
            Response.json(await loadPage(url.searchParams.get('path') ?? '/')),
          );
        const type = url.searchParams.get('type');
        const id = url.searchParams.get('id');
        if (!type || !id)
          return finalize(
            new Response('Missing entity identity', { status: 400 }),
          );
        return finalize(
          Response.json(
            await server.fetchEntity({
              type,
              id,
              viewMode: url.searchParams.get('viewMode') ?? undefined,
            }),
          ),
        );
      } catch (error) {
        if (error instanceof Error && error.message.startsWith('Canvas paths'))
          return finalize(new Response(error.message, { status: 400 }));
        throw error;
      }
    }
    return null;
  };
  return { server, session, loadPage, handle, finalize };
}

/** Mount after static assets. Render receives public page data plus a server-only entity callback. */
export function createCanvasHandler(options: CanvasServerOptions) {
  return async (
    request: Request,
    render: (
      request: Request,
      context: CanvasRequestContext,
    ) => Promise<Response | null>,
  ): Promise<Response> => {
    const context = createCanvasRequest(request, options);
    const api = await context.handle();
    if (api) return api;
    if (request.method !== 'GET' && request.method !== 'HEAD')
      return context.finalize(methodNotAllowed('GET, HEAD'));
    const url = new URL(request.url);
    const path = url.pathname + url.search;
    const data = await context.loadPage(path);
    if (data.page && isPageRedirect(data.page)) {
      return context.finalize(
        new Response(null, {
          status: data.page.redirect.statusCode,
          headers: { Location: data.page.redirect.url },
        }),
      );
    }
    const rendered = await render(request, {
      canvas: {
        path,
        data,
        fetchEntity: (options) => context.server.fetchEntity(options),
      },
    });
    const response = rendered ?? new Response('Not found', { status: 404 });
    return context.finalize(
      new Response(request.method === 'HEAD' ? null : response.body, {
        status: data.page === null ? 404 : response.status,
        headers: response.headers,
      }),
    );
  };
}
function methodNotAllowed(allow: string) {
  return new Response('Method not allowed', {
    status: 405,
    headers: { Allow: allow },
  });
}
function serializeCookie(cookie: DraftCookie): string {
  return [
    `${cookie.name}=${encodeURIComponent(cookie.value)}`,
    `Path=${cookie.path}`,
    cookie.httpOnly && 'HttpOnly',
    cookie.secure && 'Secure',
    `SameSite=${cookie.sameSite[0].toUpperCase()}${cookie.sameSite.slice(1)}`,
    cookie.partitioned && 'Partitioned',
    cookie.expires && `Expires=${cookie.expires.toUTCString()}`,
  ]
    .filter(Boolean)
    .join('; ');
}
