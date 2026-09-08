import { createHmac } from 'node:crypto';
import { describe, expect, it, vi } from 'vitest';

import { createRevalidateApiRoute } from './revalidate';

import type { PublishPayload } from '@drupal-canvas/headless/server';
import type { APIContext } from 'astro';

const SECRET = 'test-secret';

const payload: PublishPayload = {
  event: 'publish',
  entities: [{ entityType: 'node', id: '1', uuid: 'uuid-1', langcode: 'en' }],
  tags: ['node:1', 'node_list'],
};

// The webhook signs the exact request body, so tests sign the same bytes.
function sign(body: string, secret = SECRET): string {
  return `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;
}

// A minimal APIContext: the handler only reads context.request.
function post(body: string, signature: string | null): APIContext {
  const headers = new Headers();
  if (signature !== null) {
    headers.set('x-canvas-signature', signature);
  }
  return {
    request: new Request('https://example.com/api/canvas/revalidate', {
      method: 'POST',
      body,
      headers,
    }),
  } as APIContext;
}

describe('createRevalidateApiRoute', () => {
  it('runs the callback and reports the tag count for a valid signature', async () => {
    const revalidate = vi.fn();
    const { POST } = createRevalidateApiRoute({ secret: SECRET, revalidate });
    const body = JSON.stringify(payload);

    const response = await POST(post(body, sign(body)));

    expect(revalidate).toHaveBeenCalledTimes(1);
    expect(revalidate).toHaveBeenCalledWith(payload);
    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toEqual({
      revalidated: payload.tags.length,
    });
  });

  it('answers 401 and skips the callback for an invalid signature', async () => {
    const revalidate = vi.fn();
    const { POST } = createRevalidateApiRoute({ secret: SECRET, revalidate });
    const body = JSON.stringify(payload);

    const response = await POST(post(body, 'sha256=deadbeef'));

    expect(response.status).toBe(401);
    expect(revalidate).not.toHaveBeenCalled();
  });

  it('answers 501 when no revalidate callback is supplied', async () => {
    const { POST } = createRevalidateApiRoute({ secret: SECRET });
    const body = JSON.stringify(payload);

    const response = await POST(post(body, sign(body)));

    expect(response.status).toBe(501);
  });
});
