import { afterEach, describe, expect, it, vi } from 'vitest';

import { createAuthenticatedFetch } from './auth-proxy';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('createAuthenticatedFetch', () => {
  it('authenticates requests to the site as the Workbench proxy does', async () => {
    const fetchMock = vi.fn(async () => new Response('{}'));
    vi.stubGlobal('fetch', fetchMock);
    const authenticatedFetch = await createAuthenticatedFetch(
      'https://drupal.example/',
      { CANVAS_ACCESS_TOKEN: 'token' },
    );

    await authenticatedFetch('/canvas/api/v0/interface-translation', {
      headers: { Accept: 'application/json' },
    });

    const [url, init] = fetchMock.mock.calls[0] as unknown as [
      URL,
      RequestInit,
    ];
    expect(url.href).toBe(
      'https://drupal.example/canvas/api/v0/interface-translation',
    );
    const headers = new Headers(init.headers);
    expect(headers.get('Authorization')).toBe('Bearer token');
    expect(headers.get('Accept')).toBe('application/json');
  });
});
