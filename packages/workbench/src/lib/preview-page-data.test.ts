import { afterEach, describe, expect, it, vi } from 'vitest';

import { fetchPreviewPageData } from './preview-page-data';

describe('preview-page-data', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('fetches page data from the page-data endpoint', async () => {
    const pageData = {
      pageTitle: 'Test page',
      breadcrumbs: [{ key: '<front>', text: 'Home', url: '/' }],
      mainEntity: {
        bundle: 'article',
        entityTypeId: 'node',
        uuid: 'a5715314-9b06-42a4-8be4-8c555b12b869',
        requestedLanguage: 'en',
        renderedLanguage: 'en',
        translations: [],
      },
    };
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(Response.json(pageData));

    const result = await fetchPreviewPageData('node', '2');

    expect(result).toEqual(pageData);
    expect(fetchMock).toHaveBeenCalledWith('/canvas/api/v0/page-data/node/2', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    });
  });

  it('throws the server-provided message on a failed request', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      Response.json({ message: 'Access denied.' }, { status: 403 }),
    );

    await expect(fetchPreviewPageData('node', '2')).rejects.toThrow(
      'Access denied.',
    );
  });

  it('falls back to a status message when the error body is not JSON', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response('gateway timeout', { status: 504 }),
    );

    await expect(fetchPreviewPageData('node', '2')).rejects.toThrow(
      'Page data request failed with status 504.',
    );
  });
});
