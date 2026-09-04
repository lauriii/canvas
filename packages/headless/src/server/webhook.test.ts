import { createHmac } from 'node:crypto';
import { describe, expect, it } from 'vitest';

import {
  parsePublishPayload,
  readPublishWebhook,
  verifyPublishSignature,
} from './webhook';

const SECRET = 'top-secret-value';

function sign(body: string, secret = SECRET): string {
  return 'sha256=' + createHmac('sha256', secret).update(body).digest('hex');
}

describe('verifyPublishSignature', () => {
  const body = JSON.stringify({ event: 'publish', entities: [], tags: [] });

  it('accepts a correct signature', async () => {
    await expect(
      verifyPublishSignature(body, sign(body), SECRET),
    ).resolves.toBe(true);
  });

  it('rejects a wrong secret', async () => {
    await expect(
      verifyPublishSignature(body, sign(body, 'other'), SECRET),
    ).resolves.toBe(false);
  });

  it('rejects a tampered body', async () => {
    await expect(
      verifyPublishSignature(body + ' ', sign(body), SECRET),
    ).resolves.toBe(false);
  });

  it('rejects a missing or malformed header', async () => {
    await expect(verifyPublishSignature(body, null, SECRET)).resolves.toBe(
      false,
    );
    await expect(
      verifyPublishSignature(body, 'not-a-signature', SECRET),
    ).resolves.toBe(false);
  });
});

describe('parsePublishPayload', () => {
  it('parses a valid payload', () => {
    const payload = parsePublishPayload(
      JSON.stringify({
        event: 'publish',
        entities: [
          { entityType: 'canvas_page', id: '1', uuid: 'u', langcode: 'en' },
        ],
        tags: ['canvas_page:1'],
      }),
    );
    expect(payload.tags).toEqual(['canvas_page:1']);
    expect(payload.entities[0].entityType).toBe('canvas_page');
  });

  it('throws on non-JSON', () => {
    expect(() => parsePublishPayload('not json')).toThrow();
  });

  it('throws on the wrong shape', () => {
    expect(() =>
      parsePublishPayload(JSON.stringify({ event: 'other' })),
    ).toThrow();
  });
});

describe('readPublishWebhook', () => {
  const body = JSON.stringify({ event: 'publish', entities: [], tags: ['t'] });

  it('returns the payload for a valid signed request', async () => {
    const result = await readPublishWebhook({
      rawBody: body,
      signature: sign(body),
      secret: SECRET,
    });
    expect(result).toEqual({
      ok: true,
      payload: { event: 'publish', entities: [], tags: ['t'] },
    });
  });

  it('is 500 when no secret is configured', async () => {
    const result = await readPublishWebhook({
      rawBody: body,
      signature: sign(body),
      secret: undefined,
    });
    expect(result).toEqual({
      ok: false,
      status: 500,
      message: expect.any(String),
    });
  });

  it('is 401 for a bad signature', async () => {
    const result = await readPublishWebhook({
      rawBody: body,
      signature: sign(body, 'other'),
      secret: SECRET,
    });
    expect(result.ok).toBe(false);
    expect((result as { status: number }).status).toBe(401);
  });

  it('is 400 for a malformed payload', async () => {
    const bad = 'not json';
    const result = await readPublishWebhook({
      rawBody: bad,
      signature: sign(bad),
      secret: SECRET,
    });
    expect(result.ok).toBe(false);
    expect((result as { status: number }).status).toBe(400);
  });
});
