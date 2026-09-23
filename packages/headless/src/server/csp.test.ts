import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  hasFrameAncestors,
  mergeFrameAncestors,
  resolveFrameAncestors,
} from './csp';

describe('resolveFrameAncestors', () => {
  beforeEach(() => {
    vi.stubEnv('CANVAS_EDITOR_ORIGINS', undefined);
    vi.stubEnv('CANVAS_SITE_URL', undefined);
  });
  afterEach(() => vi.unstubAllEnvs());

  it("is 'self'-only without configuration or draft data", () => {
    expect(resolveFrameAncestors()).toBe("'self'");
  });

  it('appends the editor origin from the signed renewal URL', () => {
    expect(
      resolveFrameAncestors({
        renewUrl: 'https://drupal.example:8443/canvas-headless/renew',
      }),
    ).toBe("'self' https://drupal.example:8443");
  });

  it('combines and normalizes both defaults, with or without a session', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://CMS.example:443/subdir');
    expect(resolveFrameAncestors()).toBe("'self' https://cms.example");
    expect(
      resolveFrameAncestors({ renewUrl: 'http://editor.example/renew' }),
    ).toBe("'self' https://cms.example http://editor.example");
    expect(
      resolveFrameAncestors({ renewUrl: 'https://cms.example/renew' }),
    ).toBe("'self' https://cms.example");
  });

  it.each(['', '  , ', 'not-a-url', 'https://[::1]:8443'])(
    'does not restore either default for explicit %j',
    (configured) => {
      vi.stubEnv('CANVAS_SITE_URL', 'https://cms.example');
      vi.stubEnv('CANVAS_EDITOR_ORIGINS', configured);
      expect(
        resolveFrameAncestors({ renewUrl: 'https://editor.example/renew' }),
      ).toBe("'self'");
    },
  );

  it('uses only valid explicit origins, normalized and deduplicated in order', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://cms.example');
    vi.stubEnv(
      'CANVAS_EDITOR_ORIGINS',
      'https://EXPLICIT.example:443/a, not-a-url\nhttp://localhost:3000 https://explicit.example/b',
    );
    expect(
      resolveFrameAncestors({ renewUrl: 'https://editor.example/renew' }),
    ).toBe("'self' https://explicit.example http://localhost:3000");
  });

  it.each([
    'not a URL',
    'javascript:alert(1)',
    'https://user:pw@evil.example',
    'https://*.example.com',
    'https://*',
    'https://a.example;script-src',
    'https://a_b.example',
    'http://[::1]:3199',
    'https://[2001:db8::1]:8443',
    'https://[::ffff:192.0.2.1]',
    'https://a.example%3Bscript-src',
  ])('rejects %s from configuration and session defaults', (value) => {
    vi.stubEnv('CANVAS_SITE_URL', value);
    expect(resolveFrameAncestors({ renewUrl: value })).toBe("'self'");
    vi.stubEnv('CANVAS_EDITOR_ORIGINS', `https://cms.example, ${value}`);
    expect(resolveFrameAncestors()).toBe("'self' https://cms.example");
  });

  it('does not split a default URL into multiple sources', () => {
    const value = 'https://a.example,https://evil.example';
    vi.stubEnv('CANVAS_SITE_URL', value);
    expect(resolveFrameAncestors({ renewUrl: value })).toBe("'self'");
    vi.stubEnv('CANVAS_EDITOR_ORIGINS', 'https://a.example,default-src');
    expect(resolveFrameAncestors()).toBe("'self' https://a.example");
  });

  it.each([
    'https://cms.example',
    'https://site-1.ddev.site',
    'http://localhost:3000',
    'https://127.0.0.1:32991',
  ])('accepts the concrete origin %s without DNS lookup', (value) => {
    vi.stubEnv('CANVAS_EDITOR_ORIGINS', value);
    expect(resolveFrameAncestors()).toBe(`'self' ${value}`);
  });

  it('reads the environment on each call', () => {
    vi.stubEnv('CANVAS_SITE_URL', 'https://first.example');
    expect(resolveFrameAncestors()).toBe("'self' https://first.example");
    vi.stubEnv('CANVAS_EDITOR_ORIGINS', 'https://second.example');
    expect(resolveFrameAncestors()).toBe("'self' https://second.example");
  });
});

describe('hasFrameAncestors', () => {
  it('finds the directive in any policy', () => {
    expect(
      hasFrameAncestors([
        "default-src 'self'",
        'img-src *; frame-ancestors https://editor.example',
      ]),
    ).toBe(true);
  });

  it('does not mistake a prefixed directive for frame-ancestors', () => {
    expect(hasFrameAncestors('frame-ancestors-report-only x')).toBe(false);
  });
});

describe('mergeFrameAncestors', () => {
  it('is the bare directive when the app set no policy', () => {
    expect(mergeFrameAncestors(null, "'self'")).toEqual([
      "frame-ancestors 'self'",
    ]);
    expect(mergeFrameAncestors('  ', "'self'")).toEqual([
      "frame-ancestors 'self'",
    ]);
    expect(mergeFrameAncestors([], "'self'")).toEqual([
      "frame-ancestors 'self'",
    ]);
  });

  it("preserves the app's other directives", () => {
    expect(
      mergeFrameAncestors(
        "default-src 'self'; script-src 'self' https://cdn.example",
        "'self' https://drupal.example",
      ),
    ).toEqual([
      "default-src 'self'; script-src 'self' https://cdn.example",
      "frame-ancestors 'self' https://drupal.example",
    ]);
  });

  it('preserves an existing application frame-ancestors directive', () => {
    expect(
      mergeFrameAncestors(
        "frame-ancestors https://old.example; img-src 'self'",
        "'self'",
      ),
    ).toEqual(["frame-ancestors https://old.example; img-src 'self'"]);
  });

  it('keeps every policy of a comma-separated policy list', () => {
    expect(
      mergeFrameAncestors(
        "default-src 'self', frame-ancestors https://old.example; img-src 'self'",
        "'self'",
      ),
    ).toEqual([
      "default-src 'self'",
      "frame-ancestors https://old.example; img-src 'self'",
    ]);
  });

  it('keeps every policy of an array value', () => {
    expect(
      mergeFrameAncestors(
        ["default-src 'self'", 'frame-ancestors https://old.example'],
        "'self'",
      ),
    ).toEqual(["default-src 'self'", 'frame-ancestors https://old.example']);
  });

  it('does not mistake prefixed directives for frame-ancestors', () => {
    expect(
      mergeFrameAncestors('frame-ancestors-report-only x', "'self'"),
    ).toEqual(['frame-ancestors-report-only x', "frame-ancestors 'self'"]);
  });
});
