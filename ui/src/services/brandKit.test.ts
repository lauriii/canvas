import { describe, expect, it } from 'vitest';

import { createUploadFontRequest } from '@/services/brandKit';

describe('createUploadFontRequest', () => {
  it('wraps uploads in an octet-stream blob', async () => {
    const file = new File(['font-data'], 'brand-font.woff2');

    const request = createUploadFontRequest(file);

    expect(request.url).toBe('canvas/api/v0/artifacts/upload');
    expect(request.method).toBe('POST');
    expect(request.headers).toEqual({
      'Content-Disposition': 'file; filename="brand-font.woff2"',
    });
    expect(request.body).toBeInstanceOf(Blob);
    expect((request.body as Blob).type).toBe('application/octet-stream');
    expect(request.headers).not.toHaveProperty('Content-Type');
  });
});
