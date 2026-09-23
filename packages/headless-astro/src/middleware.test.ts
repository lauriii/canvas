import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { onRequest } from './middleware';

import type { APIContext } from 'astro';

const { getDraftData } = vi.hoisted(() => ({ getDraftData: vi.fn() }));
vi.mock('./server', () => ({ getDraftData }));

beforeEach(() => {
  vi.stubEnv('CANVAS_SITE_URL', 'https://cms.example');
  vi.stubEnv('CANVAS_EDITOR_ORIGINS', undefined);
  getDraftData.mockResolvedValue({ renewUrl: 'https://editor.example/renew' });
});
afterEach(() => vi.unstubAllEnvs());

describe('Astro CSP wiring', () => {
  it.each([
    [undefined, "'self' https://cms.example https://editor.example"],
    ['https://explicit.example', "'self' https://explicit.example"],
  ])(
    'resolves configured origins %j after the route runs',
    async (configured, expected) => {
      vi.stubEnv('CANVAS_EDITOR_ORIGINS', configured);
      const response = new Response('page');
      const context = {} as APIContext;
      await onRequest(context, async () => {
        response.headers.set('Content-Security-Policy', "default-src 'self'");
        return response;
      });
      expect(getDraftData).toHaveBeenCalledWith(context);
      expect(response.headers.get('Content-Security-Policy')).toBe(
        `default-src 'self', frame-ancestors ${expected}`,
      );
    },
  );
  it('handles an absent session', async () => {
    getDraftData.mockResolvedValue(null);
    const response = new Response('page');
    await onRequest({} as APIContext, async () => response);
    expect(response.headers.get('Content-Security-Policy')).toBe(
      "frame-ancestors 'self' https://cms.example",
    );
  });
  it('keeps application-owned frame-ancestors authoritative', async () => {
    const response = new Response('page', {
      headers: {
        'Content-Security-Policy':
          "default-src 'self', frame-ancestors https://app-owned.example",
      },
    });
    await onRequest({} as APIContext, async () => response);
    expect(response.headers.get('Content-Security-Policy')).toBe(
      "default-src 'self', frame-ancestors https://app-owned.example",
    );
  });
});
