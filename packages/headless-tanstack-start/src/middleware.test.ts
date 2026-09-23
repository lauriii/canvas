import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import './middleware';

type Handler = (context: { next: () => Promise<unknown> }) => Promise<unknown>;
const mocks = vi.hoisted(() => ({
  handler: undefined as Handler | undefined,
  getDraftData: vi.fn(),
  getResponseHeader: vi.fn(),
  setResponseHeader: vi.fn(),
}));
vi.mock('@tanstack/react-start', () => ({
  createMiddleware: () => ({
    server(handler: Handler) {
      mocks.handler = handler;
      return handler;
    },
  }),
}));
vi.mock('@tanstack/react-start/server', () => ({
  getResponseHeader: mocks.getResponseHeader,
  setResponseHeader: mocks.setResponseHeader,
}));
vi.mock('./server', () => ({ getDraftData: mocks.getDraftData }));

beforeEach(() => {
  vi.resetAllMocks();
  vi.stubEnv('CANVAS_SITE_URL', 'https://cms.example');
  vi.stubEnv('CANVAS_EDITOR_ORIGINS', undefined);
  mocks.getDraftData.mockResolvedValue({
    renewUrl: 'https://editor.example/renew',
  });
});
afterEach(() => vi.unstubAllEnvs());

describe('TanStack CSP middleware wiring', () => {
  it.each([
    [undefined, "'self' https://cms.example https://editor.example"],
    ['https://explicit.example', "'self' https://explicit.example"],
  ])(
    'resolves configured origins %j after the handler chain',
    async (configured, expected) => {
      vi.stubEnv('CANVAS_EDITOR_ORIGINS', configured);
      const result = { response: new Response('page') };
      expect(
        await mocks.handler!({
          next: async () => {
            mocks.getResponseHeader.mockReturnValue("default-src 'self'");
            return result;
          },
        }),
      ).toBe(result);
      expect(mocks.setResponseHeader).toHaveBeenCalledWith(
        'Content-Security-Policy',
        `default-src 'self', frame-ancestors ${expected}`,
      );
      expect(result.response.headers.get('Content-Security-Policy')).toBe(
        `default-src 'self', frame-ancestors ${expected}`,
      );
    },
  );
  it('handles an absent session', async () => {
    mocks.getDraftData.mockResolvedValue(null);
    await mocks.handler!({
      next: async () => ({ response: new Response('page') }),
    });
    expect(mocks.setResponseHeader).toHaveBeenCalledWith(
      'Content-Security-Policy',
      "frame-ancestors 'self' https://cms.example",
    );
  });
  it('keeps application-owned frame-ancestors authoritative', async () => {
    const policy =
      "default-src 'self', frame-ancestors https://app-owned.example";
    mocks.getResponseHeader.mockReturnValue(policy);
    await mocks.handler!({
      next: async () => ({ response: new Response('page') }),
    });
    expect(mocks.setResponseHeader).toHaveBeenCalledWith(
      'Content-Security-Policy',
      policy,
    );
  });
  it('handles immutable redirect responses without losing their destination', async () => {
    const result = { response: Response.redirect('https://app.example/login') };
    await mocks.handler!({ next: async () => result });
    expect(result.response.status).toBe(302);
    expect(result.response.headers.get('Location')).toBe(
      'https://app.example/login',
    );
    expect(result.response.headers.get('Content-Security-Policy')).toBe(
      "frame-ancestors 'self' https://cms.example https://editor.example",
    );
  });
  it('preserves application CSP directly on the returned response too', async () => {
    mocks.getResponseHeader.mockReturnValue("default-src 'self'");
    const response = new Response('page', {
      headers: {
        'Content-Security-Policy':
          'script-src https://scripts.example, frame-ancestors https://app-owned.example',
      },
    });
    await mocks.handler!({ next: async () => ({ response }) });
    const policy =
      "default-src 'self', script-src https://scripts.example, frame-ancestors https://app-owned.example";
    expect(response.headers.get('Content-Security-Policy')).toBe(policy);
    expect(mocks.setResponseHeader).toHaveBeenCalledWith(
      'Content-Security-Policy',
      policy,
    );
  });
});
