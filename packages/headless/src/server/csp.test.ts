import { afterEach, describe, expect, it, vi } from 'vitest';

import { mergeFrameAncestors, resolveFrameAncestors } from './csp';

afterEach(() => {
  vi.unstubAllEnvs();
});

describe('resolveFrameAncestors', () => {
  it("is 'self'-only without the environment variable", () => {
    vi.stubEnv('DRAFT_ALLOWED_FRAME_ANCESTORS', '');
    expect(resolveFrameAncestors()).toBe("'self'");
  });

  it('appends the configured embedder origins', () => {
    vi.stubEnv('DRAFT_ALLOWED_FRAME_ANCESTORS', 'https://drupal.example');
    expect(resolveFrameAncestors()).toBe("'self' https://drupal.example");
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

  it('replaces an existing frame-ancestors directive', () => {
    expect(
      mergeFrameAncestors(
        "frame-ancestors https://old.example; img-src 'self'",
        "'self'",
      ),
    ).toEqual(["img-src 'self'", "frame-ancestors 'self'"]);
  });

  it('keeps every policy of a comma-separated policy list', () => {
    expect(
      mergeFrameAncestors(
        "default-src 'self', frame-ancestors https://old.example; img-src 'self'",
        "'self'",
      ),
    ).toEqual([
      "default-src 'self'",
      "img-src 'self'",
      "frame-ancestors 'self'",
    ]);
  });

  it('keeps every policy of an array value', () => {
    expect(
      mergeFrameAncestors(
        ["default-src 'self'", 'frame-ancestors https://old.example'],
        "'self'",
      ),
    ).toEqual(["default-src 'self'", "frame-ancestors 'self'"]);
  });

  it('does not mistake prefixed directives for frame-ancestors', () => {
    expect(
      mergeFrameAncestors('frame-ancestors-report-only x', "'self'"),
    ).toEqual(['frame-ancestors-report-only x', "frame-ancestors 'self'"]);
  });
});
