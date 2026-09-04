import { createHmac } from 'node:crypto';
import { describe, expect, it, vi } from 'vitest';

import { createRevalidateRouteHandler } from './revalidate';

const SECRET = 'top-secret-value';

function request(body: string, secret = SECRET): Request {
  return new Request('https://app.example/api/canvas/revalidate', {
    method: 'POST',
    headers: {
      'x-canvas-signature':
        'sha256=' + createHmac('sha256', secret).update(body).digest('hex'),
    },
    body,
  });
}

const body = JSON.stringify({
  event: 'publish',
  entities: [{ entityType: 'canvas_page', id: '1', uuid: 'u', langcode: 'en' }],
  tags: ['canvas_page:1', 'config:canvas.component.js.header'],
});

describe('createRevalidateRouteHandler (TanStack Start)', () => {
  it('verifies and hands the payload to the revalidate callback', async () => {
    const revalidate = vi.fn();
    const { POST } = createRevalidateRouteHandler({
      secret: SECRET,
      revalidate,
    });
    const response = await POST({ request: request(body) });

    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toEqual({ revalidated: 2 });
    expect(revalidate).toHaveBeenCalledOnce();
    expect(revalidate.mock.calls[0][0].tags).toEqual([
      'canvas_page:1',
      'config:canvas.component.js.header',
    ]);
  });

  it('rejects an invalid signature without revalidating', async () => {
    const revalidate = vi.fn();
    const { POST } = createRevalidateRouteHandler({
      secret: SECRET,
      revalidate,
    });
    const response = await POST({ request: request(body, 'wrong') });

    expect(response.status).toBe(401);
    expect(revalidate).not.toHaveBeenCalled();
  });

  it('is 500 when no secret is configured', async () => {
    const { POST } = createRevalidateRouteHandler({
      secret: '',
      revalidate: vi.fn(),
    });
    const response = await POST({ request: request(body) });
    expect(response.status).toBe(500);
  });
});
