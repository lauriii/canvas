import { describe, expect, it, vi } from 'vitest';

import { resolveDraftConfig } from './config';

describe('resolveDraftConfig', () => {
  it('reads the Canvas site URL from the shared environment variable', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://drupal.example///');

    expect(resolveDraftConfig()).toEqual({
      baseUrl: 'https://drupal.example',
    });
    vi.unstubAllEnvs();
  });

  it('names CANVAS_SITE_URL when the required setting is missing', () => {
    vi.stubEnv('CANVAS_SITE_URL', '');

    expect(() => resolveDraftConfig()).toThrow(
      'CANVAS_SITE_URL must be set. See .env.example.',
    );
    vi.unstubAllEnvs();
  });
});
