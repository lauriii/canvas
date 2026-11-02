import { afterEach, describe, expect, it, vi } from 'vitest';

import { fetchCsrfToken, forgetCsrfToken } from '@/utils/csrf';

describe('fetchCsrfToken', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    forgetCsrfToken('/');
  });

  it('reuses the token until it is forgotten or fails', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(new Response('', { status: 500 }))
      .mockResolvedValueOnce(new Response('one'))
      .mockResolvedValueOnce(new Response('two'));
    vi.stubGlobal('fetch', fetch);

    await expect(fetchCsrfToken('/')).rejects.toThrow();
    expect(await fetchCsrfToken('/')).toBe('one');
    expect(await fetchCsrfToken('/')).toBe('one');
    forgetCsrfToken('/');
    expect(await fetchCsrfToken('/')).toBe('two');
    expect(fetch).toHaveBeenCalledTimes(3);
  });
});
