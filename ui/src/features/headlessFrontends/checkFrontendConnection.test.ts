import { afterEach, describe, expect, it, vi } from 'vitest';

import { checkFrontendConnection } from './checkFrontendConnection';

const fetchMock = vi.fn<typeof fetch>();

vi.stubGlobal('fetch', fetchMock);

describe('checkFrontendConnection', () => {
  afterEach(() => {
    fetchMock.mockReset();
  });

  it('reports ready when the Canvas endpoint requests an assertion', async () => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 401 }));

    await expect(
      checkFrontendConnection('https://frontend.example'),
    ).resolves.toBe('ready');
    expect(fetchMock).toHaveBeenCalledWith(
      'https://frontend.example/api/canvas/components',
      expect.objectContaining({ credentials: 'omit' }),
    );
  });

  it('reports setup needed when the endpoint does not use the adapter contract', async () => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 404 }));

    await expect(
      checkFrontendConnection('https://frontend.example'),
    ).resolves.toBe('setup-needed');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('reports setup needed when the endpoint is unreadable but the site responds', async () => {
    fetchMock
      .mockRejectedValueOnce(new TypeError('Failed to fetch'))
      .mockResolvedValueOnce(new Response());

    await expect(
      checkFrontendConnection('https://frontend.example'),
    ).resolves.toBe('setup-needed');
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      'https://frontend.example',
      expect.objectContaining({ mode: 'no-cors' }),
    );
  });

  it('reports unreachable when neither request reaches the site', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

    await expect(
      checkFrontendConnection('https://frontend.example'),
    ).resolves.toBe('unreachable');
  });
});
