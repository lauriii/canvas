import {
  afterAll,
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vitest';
import {
  createRootRoute,
  createRoute,
  createRouter,
} from '@tanstack/react-router';
import { createStartHandler } from '@tanstack/react-start/server';

import { createDraftRouteHandlers } from './route-handlers';

const upstream = vi.hoisted(() => {
  const fetch = vi.fn();
  vi.stubGlobal('fetch', fetch);
  return fetch;
});

vi.mock('#tanstack-router-entry', () => ({
  getRouter: () => {
    const root = createRootRoute();
    const proxy = createRoute({
      getParentRoute: () => root,
      path: '/api/canvas/jsonapi/$',
      server: { handlers: createDraftRouteHandlers().jsonApiProxy },
    });
    return createRouter({ routeTree: root.addChildren([proxy]) });
  },
}));

// Exercise Start's actual route matching and method selection, rather than
// calling jsonApiProxy.PUT directly. An unhandled method reaches this HTML
// renderer, reproducing the original framework fallthrough.
const renderHtml = vi.fn(
  () =>
    new Response('<html>Application</html>', {
      headers: { 'Content-Type': 'text/html' },
    }),
);
const handle = createStartHandler(renderHtml);
const url = 'https://app.example/api/canvas/jsonapi/jsonapi/node/article';

describe('JSON:API proxy server route', () => {
  beforeEach(() => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example');
    upstream.mockReset();
    upstream.mockImplementation(
      async () =>
        new Response('{}', {
          headers: { 'Content-Type': 'application/vnd.api+json' },
        }),
    );
    renderHtml.mockClear();
  });
  afterEach(() => vi.unstubAllEnvs());
  afterAll(() => vi.unstubAllGlobals());

  it('rejects PUT through the shared proxy without rendering HTML or dispatching upstream', async () => {
    const response = await handle(new Request(url, { method: 'PUT' }));
    expect(response.status).toBe(405);
    expect(response.headers.get('Allow')).toBe(
      'GET, HEAD, POST, PATCH, DELETE',
    );
    expect(response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(await response.json()).toMatchObject({
      error: 'method_not_allowed',
    });
    expect(upstream).not.toHaveBeenCalled();
    expect(renderHtml).not.toHaveBeenCalled();
  });

  it.each(['GET', 'HEAD', 'POST', 'PATCH', 'DELETE'])(
    'routes supported %s requests to the proxy',
    async (method) => {
      const response = await handle(
        new Request(url, {
          method,
          headers: { Origin: 'https://app.example' },
        }),
      );
      expect(response.status).toBe(200);
      // Configuration may first discover backend capabilities. The routed
      // JSON:API request must still arrive upstream with its original method.
      expect(upstream).toHaveBeenLastCalledWith(
        'https://drupal.example/jsonapi/node/article',
        expect.objectContaining({ method }),
      );
      expect(renderHtml).not.toHaveBeenCalled();
    },
  );

  it('preserves fail-closed authentication for a draft flag without a session', async () => {
    const response = await handle(
      new Request(url, {
        headers: { Cookie: 'canvas_headless_draft_mode=1' },
      }),
    );
    expect(response.status).toBe(401);
    expect(
      upstream.mock.calls.some(([input]) =>
        String(input).includes('/jsonapi/'),
      ),
    ).toBe(false);
    expect(renderHtml).not.toHaveBeenCalled();
  });

  it('keeps OPTIONS local and preserves cross-origin rejection', async () => {
    const options = await handle(new Request(url, { method: 'OPTIONS' }));
    expect(options.status).toBe(204);
    const rejected = await handle(
      new Request(url, {
        method: 'POST',
        headers: { Origin: 'https://foreign.example' },
      }),
    );
    expect(rejected.status).toBe(403);
    expect(upstream).not.toHaveBeenCalled();
    expect(renderHtml).not.toHaveBeenCalled();
  });
});
