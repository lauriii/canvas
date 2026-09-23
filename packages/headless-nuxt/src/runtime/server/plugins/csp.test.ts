import { IncomingMessage, ServerResponse } from 'node:http';
import { Socket } from 'node:net';
import { createEvent, getResponseHeader, setResponseHeader } from 'h3';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import install from './csp';

import type { H3Event } from 'h3';

const { getDraftData } = vi.hoisted(() => ({ getDraftData: vi.fn() }));
vi.mock('../session', () => ({ getDraftData }));
let hookName: string;
let beforeResponse: (event: H3Event) => void | Promise<void>;
let event: H3Event;
beforeEach(() => {
  vi.stubEnv('CANVAS_SITE_URL', 'https://cms.example');
  vi.stubEnv('CANVAS_EDITOR_ORIGINS', undefined);
  getDraftData.mockResolvedValue({ renewUrl: 'https://editor.example/renew' });
  const req = new IncomingMessage(new Socket());
  event = createEvent(req, new ServerResponse(req));
  install({
    hooks: {
      hook(name, handler) {
        hookName = name;
        beforeResponse = handler;
      },
    },
  });
});
afterEach(() => vi.unstubAllEnvs());

describe('Nuxt CSP response hook', () => {
  it.each([
    [undefined, "'self' https://cms.example https://editor.example"],
    ['https://explicit.example', "'self' https://explicit.example"],
  ])('resolves configured origins %j', async (configured, expected) => {
    vi.stubEnv('CANVAS_EDITOR_ORIGINS', configured);
    setResponseHeader(event, 'Content-Security-Policy', [
      "default-src 'self'",
      "script-src 'self'",
    ]);
    expect(hookName).toBe('beforeResponse');
    await beforeResponse(event);
    expect(getDraftData).toHaveBeenCalledWith(event);
    expect(getResponseHeader(event, 'Content-Security-Policy')).toEqual([
      "default-src 'self'",
      "script-src 'self'",
      `frame-ancestors ${expected}`,
    ]);
  });
  it('handles an absent session', async () => {
    getDraftData.mockResolvedValue(null);
    await beforeResponse(event);
    expect(getResponseHeader(event, 'Content-Security-Policy')).toEqual([
      "frame-ancestors 'self' https://cms.example",
    ]);
  });
  it('keeps application-owned frame-ancestors across repeated headers', async () => {
    const policies = [
      "default-src 'self'",
      'frame-ancestors https://app-owned.example',
    ];
    setResponseHeader(event, 'Content-Security-Policy', policies);
    await beforeResponse(event);
    expect(getResponseHeader(event, 'Content-Security-Policy')).toEqual(
      policies,
    );
  });
});
