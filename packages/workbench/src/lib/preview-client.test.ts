import { afterEach, describe, expect, it, vi } from 'vitest';

import { fetchPreviewManifest } from './preview-client';

afterEach(() => {
  vi.restoreAllMocks();
});

describe('fetchPreviewManifest', () => {
  it('surfaces the server error response', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          error: [
            'Invalid component metadata in src/components/example/component.yml:',
            "Line 3, Column 7: $.type must not contain unknown property 'type'",
          ].join('\n'),
        }),
        {
          status: 500,
          headers: { 'Content-Type': 'application/json' },
        },
      ),
    );

    await expect(fetchPreviewManifest()).rejects.toThrow(
      "Line 3, Column 7: $.type must not contain unknown property 'type'",
    );
  });
});
