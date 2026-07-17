import { describe, expect, it } from 'vitest';

import {
  hasFrameAncestors,
  mergeFrameAncestors,
  resolveFrameAncestors,
} from './csp';

describe('resolveFrameAncestors', () => {
  it("is 'self'-only without draft data", () => {
    expect(resolveFrameAncestors()).toBe("'self'");
  });

  it('appends the editor origin from the signed renewal URL', () => {
    expect(
      resolveFrameAncestors({
        renewUrl: 'https://drupal.example:8443/canvas-headless/renew',
      }),
    ).toBe("'self' https://drupal.example:8443");
  });

  it('ignores an invalid renewal URL', () => {
    expect(resolveFrameAncestors({ renewUrl: 'not a URL' })).toBe("'self'");
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
