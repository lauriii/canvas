import { createHmac } from 'node:crypto';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { createRevalidateEventHandler } from './revalidate';

import type { H3Event } from 'h3';

// `defineEventHandler` is mocked to the identity so the returned handler can
// be called directly with a plain event stand-in. The other helpers read from
// and write to that stand-in.
vi.mock('h3', () => ({
  defineEventHandler: (handler: unknown) => handler,
  readRawBody: (event: FakeEvent) => Promise.resolve(event.rawBody),
  getHeader: (event: FakeEvent, name: string) => event.headers[name],
  setResponseStatus: (event: FakeEvent, code: number) => {
    event.statusCode = code;
  },
}));

// `nitropack/runtime` loads Nitro's build-only virtual storage module, which
// does not exist outside a Nitro build, so `useStorage` is mocked here.
const { useStorageMock, clearMock } = vi.hoisted(() => {
  const clearMock = vi.fn().mockResolvedValue(undefined);
  return {
    clearMock,
    useStorageMock: vi.fn(() => ({ clear: clearMock })),
  };
});
vi.mock('nitropack/runtime', () => ({ useStorage: useStorageMock }));

interface FakeEvent {
  rawBody: string | undefined;
  headers: Record<string, string>;
  statusCode: number;
}

type Handler = (event: H3Event) => Promise<unknown>;

const SECRET = 'test-secret';

function sign(body: string, secret: string): string {
  return `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;
}

function makeEvent(rawBody: string, signature?: string): FakeEvent {
  return {
    rawBody,
    headers: signature ? { 'x-canvas-signature': signature } : {},
    statusCode: 200,
  };
}

function payloadBody(tags: string[]): string {
  return JSON.stringify({ event: 'publish', entities: [], tags });
}

describe('createRevalidateEventHandler', () => {
  beforeEach(() => {
    useStorageMock.mockClear();
    clearMock.mockClear();
  });

  it('clears the Canvas cache group and returns the tag count', async () => {
    const body = payloadBody(['node:1', 'node:2', 'http_response']);
    const event = makeEvent(body, sign(body, SECRET));
    const handler = createRevalidateEventHandler({
      secret: SECRET,
    }) as unknown as Handler;

    const result = await handler(event as unknown as H3Event);

    expect(result).toEqual({ revalidated: 3 });
    expect(useStorageMock).toHaveBeenCalledWith('cache');
    expect(clearMock).toHaveBeenCalledWith('canvas');
    expect(event.statusCode).toBe(200);
  });

  it('calls the revalidate callback with the payload instead of clearing', async () => {
    const body = payloadBody(['node:1']);
    const event = makeEvent(body, sign(body, SECRET));
    const revalidate = vi.fn().mockResolvedValue(undefined);
    const handler = createRevalidateEventHandler({
      secret: SECRET,
      revalidate,
    }) as unknown as Handler;

    const result = await handler(event as unknown as H3Event);

    expect(result).toEqual({ revalidated: 1 });
    expect(revalidate).toHaveBeenCalledWith({
      event: 'publish',
      entities: [],
      tags: ['node:1'],
    });
    expect(clearMock).not.toHaveBeenCalled();
  });

  it('answers 401 for an invalid signature without touching the cache', async () => {
    const body = payloadBody(['node:1']);
    const event = makeEvent(body, sign(body, 'wrong-secret'));
    const handler = createRevalidateEventHandler({
      secret: SECRET,
    }) as unknown as Handler;

    const result = await handler(event as unknown as H3Event);

    expect(event.statusCode).toBe(401);
    expect(typeof result).toBe('string');
    expect(clearMock).not.toHaveBeenCalled();
  });
});
